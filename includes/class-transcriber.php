
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Transcriber {
    private $openai_key;
    public function __construct( $openai_key ) { $this->openai_key = $openai_key; }

    // Download file locally then send to Whisper for transcription (English)
    public function transcribe_from_vimeo_download( $video_id, $vimeo_client ) {
        $info = $vimeo_client->fetch_download_links( $video_id );
        if ( is_wp_error( $info ) ) return $info;
        $dl = isset( $info['download'] ) ? $info['download'] : array();
        if ( ! $dl ) return new WP_Error( 'no_download', 'No downloadable file found (need video_files scope).' );
        // Choose smallest progressive file
        usort( $dl, function( $a, $b ) { return ( ( isset($a['size']) ? $a['size'] : PHP_INT_MAX ) <=> ( isset($b['size']) ? $b['size'] : PHP_INT_MAX ) ); } );
        $url = isset( $dl[0]['link'] ) ? $dl[0]['link'] : null;
        if ( ! $url ) return new WP_Error( 'no_link', 'No download link available.' );

        // Fetch file to tmp
        $tmp = download_url( $url, 60 );
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
            'model' => 'whisper-1',
            'response_format' => 'verbose_json',
            'language' => 'en'
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

        $res = wp_remote_post( 'https://api.openai.com/v1/audio/transcriptions', array(
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 120,
        ) );
        if ( is_wp_error( $res ) ) return $res;
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) return new WP_Error( 'openai_whisper', 'Transcription failed: ' . wp_remote_retrieve_body( $res ) );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        $text = isset( $json['text'] ) ? $json['text'] : '';
        if ( ! $text ) return new WP_Error( 'empty', 'No transcription text' );
        // Return as single block (no timestamps available)
        return array( array( 'start' => 0, 'end' => 0, 'text' => $text ) );
    }
}
