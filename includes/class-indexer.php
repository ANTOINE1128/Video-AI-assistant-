<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Indexer {
    private $openai_key;
    public function __construct( $openai_key ) { $this->openai_key = $openai_key; }

    public function ensure_indexed( $video_id ) {
        global $wpdb; $tbl = $wpdb->prefix . 'fvqa_chunks';
        $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tbl WHERE video_id=%s", $video_id ) );
        if ( $exists > 0 ) return true;

        $settings = fvqa_get_settings();
        $vimeo    = new FVQA_Vimeo_Client( $settings['vimeo_token'] ?? '' );

        // Try captions first
        $caption_url = $vimeo->get_en_caption_url( $video_id );
        if ( is_wp_error( $caption_url ) ) return $caption_url;

        if ( $caption_url ) {
            $vtt = $vimeo->download_url( $caption_url );
            if ( is_wp_error( $vtt ) ) return $vtt;
            return $this->index_from_vtt( $video_id, $vtt );
        }

        // Whisper fallback (optional)
        $trans = ( new FVQA_Transcriber( $this->openai_key ) )->transcribe_from_vimeo_download( $video_id, $vimeo );
        if ( is_wp_error( $trans ) ) return $trans;

        return $this->index_from_cues( $video_id, $trans );
    }

    public function index_from_vtt( $video_id, $vtt_string ) {
        $cues = fvqa_parse_vtt( $vtt_string );
        if ( empty( $cues ) ) return new WP_Error('no_cues','No cues found in VTT', array('status'=>422));
        return $this->index_from_cues( $video_id, $cues );
    }

    public function index_from_text( $video_id, $text ) {
        $text = trim( $text );
        if ( $text === '' ) return new WP_Error('no_text','Empty text', array('status'=>422));
        $cues = array( array( 'start'=>0, 'end'=>0, 'text'=>$text ) );
        return $this->index_from_cues( $video_id, $cues );
    }

    public function index_from_cues( $video_id, $cues ) {
        global $wpdb; $tbl = $wpdb->prefix . 'fvqa_chunks';
        $chunks = fvqa_chunk_text_with_timestamps( $cues );
        foreach ( $chunks as $ch ) {
            $emb = $this->embed( $ch['text'] );
            if ( empty( $emb ) ) return new WP_Error( 'embed_failed', 'Embedding failed (check OpenAI key/network).', array( 'status' => 500 ) );
            $wpdb->insert( $tbl, array(
                'video_id'  => $video_id,
                'start_sec' => $ch['start'],
                'end_sec'   => $ch['end'],
                'text'      => $ch['text'],
                'embedding' => wp_json_encode( $emb ),
            ), array( '%s','%d','%d','%s','%s' ) );
        }
        return true;
    }

    private function embed( $text ) {
        $res = fvqa_http_with_retry( 'POST', 'https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->openai_key,
                'Content-Type'  => 'application/json'
            ),
            'body' => wp_json_encode( array(
                'model' => 'text-embedding-3-small',
                'input' => $text
            ) ),
            'timeout' => 60,
        ), 3 );
        if ( is_wp_error( $res ) ) { fvqa_log( $res->get_error_message() ); return array(); }
        if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
            fvqa_log( 'Embeddings HTTP '.wp_remote_retrieve_response_code($res).': '.wp_remote_retrieve_body($res) );
            return array();
        }
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        return $json['data'][0]['embedding'] ?? array();
    }
}
