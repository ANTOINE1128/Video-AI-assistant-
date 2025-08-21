<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_REST {
    public function __construct() {
        add_action('rest_api_init', array($this,'routes'));
    }

    public function routes() {
        register_rest_route('farhat-video-qa/v1', '/ask', array(
            'methods'  => 'POST',
            'permission_callback' => '__return_true',
            'callback' => array($this,'ask')
        ));
    }

    public function ask( WP_REST_Request $req ) {
        $video_id = sanitize_text_field( $req->get_param('video_id') );
        $question = sanitize_text_field( $req->get_param('question') );
        if ( ! $video_id || ! $question ) {
            return new WP_REST_Response( array('error'=>'Missing video_id or question.'), 400 );
        }

        $s = fvqa_get_settings();
        $indexer   = new FVQA_Indexer( $s['openai_key'] );
        $retriever = new FVQA_Retriever( $s['openai_key'], 0.60, 5 );

        $ok = $indexer->ensure_indexed( $video_id );
        if ( is_wp_error( $ok ) ) {
            return new WP_REST_Response( array('error'=>$ok->get_error_message()), $ok->get_error_data()['status'] ?? 500 );
        }

        $out = $retriever->answer( $video_id, $question );

        // logs
        if ( ! empty($s['log_enabled']) ) {
            global $wpdb; $tbl_logs = $wpdb->prefix . 'fvqa_logs';
            $wpdb->insert( $tbl_logs, array(
                'video_id' => $video_id,
                'question' => $question,
                'answer'   => $out['answer'],
                'citations'=> wp_json_encode($out['sources']),
                'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ), array('%s','%s','%s','%s','%s','%s'));
        }

        return array(
            'answer'  => $out['answer'],
            'sources' => $out['sources']
        );
    }
}
new FVQA_REST();
