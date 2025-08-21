
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
        $vimeo = new FVQA_Vimeo_Client( $settings['vimeo_token'] );
        $caption_url = $vimeo->get_en_caption_url( $video_id );

        if ( $caption_url ) {
            $vtt = $vimeo->download_url( $caption_url );
            if ( is_wp_error( $vtt ) ) return $vtt;
            $cues = fvqa_parse_vtt( $vtt );
        } else {
            $trans = ( new FVQA_Transcriber( $this->openai_key ) )->transcribe_from_vimeo_download( $video_id, $vimeo );
            if ( is_wp_error( $trans ) ) return $trans;
            $cues = $trans;
        }

        $chunks = fvqa_chunk_text_with_timestamps( $cues );
        foreach ( $chunks as $ch ) {
            $emb = $this->embed( $ch['text'] );
            $wpdb->insert( $tbl, array(
                'video_id'  => $video_id,
                'start_sec' => $ch['start'],
                'end_sec'   => $ch['end'],
                'text'      => $ch['text'],
                'embedding' => json_encode( $emb ),
            ), array( '%s','%d','%d','%s','%s' ) );
        }
        return true;
    }

    private function embed( $text ) {
        $res = wp_remote_post( 'https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->openai_key,
                'Content-Type'  => 'application/json'
            ),
            'body' => json_encode( array(
                'model' => 'text-embedding-3-small',
                'input' => $text
            ) ),
            'timeout' => 30,
        ) );
        if ( is_wp_error( $res ) ) return array();
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) return array();
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        return isset( $json['data'][0]['embedding'] ) ? $json['data'][0]['embedding'] : array();
    }
}
