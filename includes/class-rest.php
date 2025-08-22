<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_REST {
    public function __construct() {
        add_action('rest_api_init', array($this,'routes'));
    }

    public function routes() {
        register_rest_route('farhat-video-qa/v1', '/ask', array(
            'methods'  => 'POST',
            'permission_callback' => array($this,'permission_check'),
            'callback' => array($this,'ask')
        ));
    }

    /**
     * Security model (balanced for LocalWP/BuddyBoss):
     * - Accept EITHER a valid WP REST nonce (X-WP-Nonce) OR a valid public nonce (X-FVQA-Nonce),
     *   regardless of login state. This prevents “logout” behaviours on some stacks when the WP nonce
     *   isn’t sent (iframes, preview, port differences).
     * - Soft same-origin check (if Origin/Referer provided and host mismatches → 403).
     * - Simple per-IP rate limit.
     */
    public function permission_check( WP_REST_Request $req ) {
        // Soft origin/referer check (host only, ignore ports)
        $site_host = parse_url( home_url(), PHP_URL_HOST );
        $hdr = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        if ( $hdr ) {
            $h = parse_url($hdr, PHP_URL_HOST);
            if ( $h && $site_host && strcasecmp($h, $site_host) !== 0 ) {
                return new WP_Error('forbidden_origin','Forbidden origin.', array('status'=>403));
            }
        }

        // Accept either nonce
        $wp_nonce  = $req->get_header('x-wp-nonce');
        $pub_nonce = $req->get_header('x-fvqa-nonce');

        $wp_ok  = $wp_nonce  ? wp_verify_nonce($wp_nonce, 'wp_rest') : false;
        $pub_ok = $pub_nonce ? wp_verify_nonce($pub_nonce, 'fvqa_public') : false;

        if ( $wp_ok || $pub_ok ) {
            return true;
        }

        // Last resort: logged-in users with cookies but missing header (rare)
        if ( is_user_logged_in() ) {
            return current_user_can('read') ? true : new WP_Error('forbidden','Forbidden.', array('status'=>403));
        }

        return new WP_Error('nonce_required','Missing or invalid nonce.', array('status'=>403));
    }

    private function rate_limit_check() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = 'fvqa_rl_' . md5( $ip );
        $window = 60; $limit  = 20;

        $data = get_transient( $key );
        if ( ! is_array( $data ) ) { $data = array( 'count' => 0, 'start' => time() ); }
        $now = time();
        if ( $now - $data['start'] > $window ) { $data = array( 'count' => 0, 'start' => $now ); }
        $data['count']++;
        set_transient( $key, $data, $window );

        if ( $data['count'] > $limit ) {
            return new WP_Error('rate_limited','Too many requests. Try again shortly.', array('status'=>429));
        }
        return true;
    }

    public function ask( WP_REST_Request $req ) {
        $rl = $this->rate_limit_check();
        if ( is_wp_error($rl) ) {
            return new WP_REST_Response(array('error'=>$rl->get_error_message()), $rl->get_error_data()['status'] ?? 429);
        }

        $video_id_raw = $req->get_param('video_id');
        $question_raw = $req->get_param('question');

        $video_id = fvqa_validate_video_id( $video_id_raw );
        $question = is_string($question_raw) ? sanitize_textarea_field($question_raw) : '';

        if ( ! $video_id || $question === '' ) {
            return new WP_REST_Response( array('error'=>'Missing or invalid video_id or question.'), 400 );
        }
        if ( strlen($question) > 800 ) {
            return new WP_REST_Response( array('error'=>'Question too long.'), 413 );
        }

        $s = fvqa_get_settings();
        if ( empty($s['openai_key']) || empty($s['vimeo_token']) ) {
            return new WP_REST_Response( array('error'=>'Plugin not configured. Missing API keys.'), 500 );
        }

        $indexer   = new FVQA_Indexer( $s['openai_key'] );
        $retriever = new FVQA_Retriever( $s['openai_key'], 0.60, 5 );

        $ok = $indexer->ensure_indexed( $video_id );
        if ( is_wp_error($ok) ) {
            return new WP_REST_Response( array('error'=>$ok->get_error_message()), $ok->get_error_data()['status'] ?? 500 );
        }

        $out = $retriever->answer( $video_id, $question );

        // Optional logs
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
            'sources' => array_map('sanitize_text_field', $out['sources'])
        );
    }
}
new FVQA_REST();
