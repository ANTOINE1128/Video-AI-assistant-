<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Transcriber {
    private $openai_key;
    public function __construct( $openai_key ) { $this->openai_key = $openai_key; }

    public function transcribe_from_vimeo_download( $video_id, $vimeo_client ) {
        $settings = fvqa_get_settings();
        if ( ! empty( $settings['disable_whisper'] ) ) {
            return new WP_Error( 'whisper_disabled', 'Whisper fallback disabled in settings.', array( 'status' => 412 ) );
        }
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $info = $vimeo_client->fetch_download_links( $video_id );
        if ( is_wp_error( $info ) ) return $info;

        $dl = $info['download'] ?? array();
        if ( ! $dl ) {
            return new WP_Error('no_download','No downloadable file found (token needs the video_files scope).', array('status'=>403));
        }

        usort( $dl, function( $a, $b ) {
            $as = isset($a['size']) ? (int)$a['size'] : PHP_INT_MAX;
            $bs = isset($b['size']) ? (int)$b['size'] : PHP_INT_MAX;
            return $as <=> $bs;
        } );

        $entry = $dl[0];
        $url   = $entry['link'] ?? null;
        $size  = isset($entry['size']) ? (int)$entry['size'] : 0;

        if ( ! $url ) return new WP_Error('no_link','No download link available.', array('status'=>404));

        // Whisper API hard limit ~25MB (26214400 bytes)
        $WHISPER_LIMIT = 26214400;
        $BUFFER = 128 * 1024;

        if ( $size <= 0 ) {
            $head = wp_remote_head( $url, array( 'timeout' => 20 ) );
            if ( ! is_wp_error( $head ) ) {
                $cl = wp_remote_retrieve_header( $head, 'content-length' );
                if ( is_numeric( $cl ) ) { $size = (int) $cl; }
            }
        }

        if ( $size > 0 && $size >= ($WHISPER_LIMIT - $BUFFER) ) {
            $mb = number_format_i18n( $size / 1048576, 1 );
            return new WP_Error(
                'whisper_file_too_large',
                'Audio too large for Whisper (' . $mb . ' MB > 25 MB). Options: (A) add English captions to the Vimeo video so we can fetch the VTT, (B) go to Video Q&A → Manual Ingest and upload a .vtt or paste the transcript, or (C) toggle “Disable Whisper Fallback” in settings.',
                array( 'status' => 413 )
            );
        }

        $tmp = download_url( $url, 120 );
        if ( is_wp_error( $tmp ) ) return $tmp;

        $resp = $this->openai_audio_transcribe( $tmp );
        @unlink( $tmp );
        return $resp;
    }

    private function openai_audio_transcribe( $file_path ) {
        $boundary = wp_generate_password( 24, false );
        $headers = array(
            'Authorization' => 'Bearer ' . $this->openai_key,
            'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
        );

        $body = '';
        $fields = array(
            'model'           => 'whisper-1',
            'response_format' => 'verbose_json',
            'language'        => 'en'
        );
        foreach ( $fields as $name => $value ) {
            $body .= "--$boundary\r\n";
            $body .= "Content-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $fileData = file_get_contents( $file_path );
        $filename = basename( $file_path );
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $fileData . "\r\n";
        $body .= "--$boundary--\r\n";

        $res = fvqa_http_with_retry( 'POST', 'https://api.openai.com/v1/audio/transcriptions', array(
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 180,
        ), 2 ); // <-- fixed: removed invalid 5th argument

        if ( is_wp_error( $res ) ) return $res;

        $code = (int) wp_remote_retrieve_response_code( $res );
        $bodyStr = wp_remote_retrieve_body( $res );

        if ( $code === 413 || (strpos($bodyStr, 'Maximum content size limit') !== false) ) {
            return new WP_Error(
                'whisper_file_too_large',
                'Audio too large for Whisper (25 MB limit). Use captions (VTT) or Manual Ingest in settings, or disable Whisper fallback.',
                array( 'status' => 413 )
            );
        }

        if ( $code !== 200 ) {
            return new WP_Error( 'openai_whisper', 'Transcription failed: HTTP '.$code.' '.$bodyStr, array( 'status' => $code ?: 500 ) );
        }

        $json = json_decode( $bodyStr, true );
        $text = $json['text'] ?? '';
        if ( ! $text ) return new WP_Error( 'empty', 'No transcription text', array( 'status' => 500 ) );

        // Return as a single cue; indexer will chunk it.
        return array( array( 'start' => 0, 'end' => 0, 'text' => $text ) );
    }
}
