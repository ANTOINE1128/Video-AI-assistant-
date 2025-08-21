
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_REST {
    public function register_routes() {
        register_rest_route( 'farhat-video-qa/v1', '/ask', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'ask' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function ask( $req ) {
        // REST nonce
        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp_rest' ) ) {
            return new WP_Error( 'forbidden', 'Invalid nonce', array( 'status' => 403 ) );
        }

        $video_id = sanitize_text_field( $req->get_param( 'video_id' ) );
        $question = sanitize_text_field( $req->get_param( 'question' ) );
        if ( ! $video_id || ! $question ) return new WP_Error( 'bad_req', 'Missing video_id or question' );

        $settings = fvqa_get_settings();
        if ( empty( $settings['openai_key'] ) ) return new WP_Error( 'no_key', 'OpenAI key missing' );
        if ( empty( $settings['vimeo_token'] ) ) return new WP_Error( 'no_vimeo', 'Vimeo token missing' );

        // Ensure indexed
        $idx = new FVQA_Indexer( $settings['openai_key'] );
        $ok  = $idx->ensure_indexed( $video_id );
        if ( is_wp_error( $ok ) ) return $ok;

        // Retrieve + answer
        $ret = new FVQA_Retriever( $settings['openai_key'] );
        $ans = $ret->answer( $video_id, $question, 5 );
        if ( is_wp_error( $ans ) ) return $ans;

        // Log if enabled
        if ( ! empty( $settings['log_enabled'] ) ) {
            global $wpdb; $tbl = $wpdb->prefix . 'fvqa_logs';
            $wpdb->insert( $tbl, array(
                'video_id'  => $video_id,
                'question'  => $question,
                'answer'    => $ans['answer'],
                'citations' => json_encode( $ans['citations'] )
            ), array( '%s','%s','%s','%s' ) );
        }

        return array( 'ok' => true, 'answer' => $ans['answer'], 'citations' => $ans['citations'] );
    }
}
