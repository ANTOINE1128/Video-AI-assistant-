<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * REST endpoints for Farhat Lectures Video Q&A
 * Route namespace: /wp-json/fvqa/v1
 */
class FVQA_REST {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        // Accept BOTH POST and GET so front-end mismatches won't 404
        register_rest_route('fvqa/v1', '/ask', [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => [$this, 'ask'],
            'permission_callback' => [$this, 'can_ask'],
            'args'                => [
                'video_id'   => [ 'type'=>'string', 'required'=>true ],
                'question'   => [ 'type'=>'string', 'required'=>false, 'default'=>'' ],
                'mode'       => [ 'type'=>'string', 'required'=>false, 'default'=>'chat' ], // 'chat' or button keyspace
                'button_id'  => [ 'type'=>'string', 'required'=>false, 'default'=>'' ],
            ],
        ]);
    }

    /**
     * Set your auth policy here.
     * - Return true to allow guests (simplest and avoids nonce/session issues).
     * - If you want only logged-in users, return is_user_logged_in().
     */
    public function can_ask( $request ) {
        // Allow guests by default to prevent 403/401 and logout issues.
        return true;
        // If you want login-only:
        // return is_user_logged_in();
    }

    /**
     * Parse a wide variety of time hints from natural language into seconds.
     * Supports:
     *  - "12:15", "1:02:03", "10:00"
     *  - "725 sec", "725s", "at 725 seconds"
     *  - "12 min", "12 mins", "12 minutes"
     *  - "minute 10", "at minute 10", "in minute 10"
     *  - "the 10th minute", "10th minute"
     */
    private function parse_time_hint_to_seconds( $text ) {
        $q = mb_strtolower( (string)$text, 'UTF-8' );

        // H:MM:SS
        if ( preg_match('/\b(?:(\d{1,2}):)?([0-5]?\d):([0-5]\d)\b/', $q, $m) ) {
            if ($m[1] !== '') {
                $h = intval($m[1]); $m2 = intval($m[2]); $s = intval($m[3]);
                return $h*3600 + $m2*60 + $s;
            } else {
                $m2 = intval($m[2]); $s = intval($m[3]);
                return $m2*60 + $s;
            }
        }
        // MM:SS (redundant but harmless)
        if ( preg_match('/\b([0-5]?\d):([0-5]\d)\b/', $q, $m) ) {
            return intval($m[1])*60 + intval($m[2]);
        }

        // <number> seconds
        if ( preg_match('/\b(\d{1,5})\s*(?:sec|secs|second|seconds|s)\b/', $q, $m) ) {
            return intval($m[1]);
        }

        // <number> minutes
        if ( preg_match('/\b(\d{1,4})\s*(?:min|mins|minute|minutes|m)\b/', $q, $m) ) {
            return intval($m[1]) * 60;
        }

        // "minute <number>"
        if ( preg_match('/\bminute\s+(\d{1,4})\b/', $q, $m) ) {
            return intval($m[1]) * 60;
        }

        // "the 10th minute" / "10th minute"
        if ( preg_match('/\b(?:the\s+)?(\d{1,4})(?:st|nd|rd|th)?\s+minute\b/', $q, $m) ) {
            return intval($m[1]) * 60;
        }

        // Fallback: "at <number> ... minute"
        if ( preg_match('/\bat\s+(\d{1,4})\b.*\bminute\b/', $q, $m) ) {
            return intval($m[1]) * 60;
        }

        return null;
    }

    /**
     * Read params robustly from JSON body, form body, or query string.
     */
    private function get_param_any( \WP_REST_Request $request, $key, $default = '' ) {
        // JSON body
        $json = $request->get_json_params();
        if ( is_array($json) && array_key_exists($key, $json) ) {
            return $json[$key];
        }
        // Form body / route args
        $p = $request->get_params();
        if ( is_array($p) && array_key_exists($key, $p) ) {
            return $p[$key];
        }
        // Query string (GET)
        $q = $request->get_query_params();
        if ( is_array($q) && array_key_exists($key, $q) ) {
            return $q[$key];
        }
        return $default;
    }

    public function ask( \WP_REST_Request $request ) {
        // Robust param capture
        $video_id  = sanitize_text_field( (string)$this->get_param_any($request, 'video_id', '') );
        $question  = (string)$this->get_param_any($request, 'question', '') ;
        $mode      = sanitize_text_field( (string)$this->get_param_any($request, 'mode', 'chat') );
        $button_id = sanitize_text_field( (string)$this->get_param_any($request, 'button_id', '') );

        if ( $video_id === '' ) {
            return new \WP_REST_Response([
                'ok'    => false,
                'error' => 'Missing video_id (provide as JSON body, form body, or query string).',
            ], 400);
        }

        // Load settings (models, prompts, retrieval config, etc.)
        $opts = get_option('fvqa_settings', []);

        $openai_key = isset($opts['openai_key']) ? (string)$opts['openai_key'] : '';
        if ($openai_key === '') {
            return new \WP_REST_Response([
                'ok'    => false,
                'error' => 'OpenAI API key is not configured.',
            ], 400);
        }

        // Retrieval settings
        $similarity = isset($opts['retrieval_similarity']) ? (float)$opts['retrieval_similarity'] : 0.45;
        $k          = isset($opts['retrieval_k']) ? (int)$opts['retrieval_k'] : 15;

        // Generation settings (chat model default)
        $chat_model   = isset($opts['chat_model']) ? $opts['chat_model'] : 'gpt-4o-mini';
        $sys_prompt   = isset($opts['chat_system_prompt']) ? (string)$opts['chat_system_prompt'] : "You are a helpful teaching assistant.";
        $user_promptT = isset($opts['chat_user_prompt']) ? (string)$opts['chat_user_prompt'] : "Question: {question}\n\nUse the transcript snippets below to answer.\n\n{sources}";
        $temperature  = isset($opts['chat_temperature']) ? (float)$opts['chat_temperature'] : 0.4;
        $top_p        = isset($opts['chat_top_p']) ? (float)$opts['chat_top_p'] : 0.95;
        $max_tokens   = isset($opts['chat_max_tokens']) ? (int)$opts['chat_max_tokens'] : 500;

        // If this is a button action, override with button’s settings
        $buttons = isset($opts['buttons']) && is_array($opts['buttons']) ? $opts['buttons'] : [];
        if ($mode !== 'chat' && $button_id !== '' && isset($buttons[$button_id]) ) {
            $btn = $buttons[$button_id];
            if (!empty($btn['model']))         $chat_model   = $btn['model'];
            if (!empty($btn['system_prompt'])) $sys_prompt   = $btn['system_prompt'];
            if (!empty($btn['user_prompt']))   $user_promptT = $btn['user_prompt'];
            if (isset($btn['temperature']))    $temperature  = (float)$btn['temperature'];
            if (isset($btn['top_p']))          $top_p        = (float)$btn['top_p'];
            if (isset($btn['max_tokens']))     $max_tokens   = (int)$btn['max_tokens'];
        }

        // Parse natural-language time hints
        $timeHint = $this->parse_time_hint_to_seconds( $question );

        // Build generator config
        $gen = [
            'model'         => $chat_model,
            'system_prompt' => $sys_prompt,
            'user_prompt'   => $user_promptT,
            'temperature'   => $temperature,
            'top_p'         => $top_p,
            'max_tokens'    => $max_tokens,
        ];

        // Ensure video is indexed (creates chunks if needed)
        $indexer = new FVQA_Indexer( $openai_key );
        $ok = $indexer->ensure_indexed( $video_id );
        if ( is_wp_error($ok) ) {
            return new \WP_REST_Response([
                'ok'    => false,
                'error' => 'Indexing failed: '.$ok->get_error_message(),
            ], 500);
        }

        // Optional: fire a "thinking" event for UI
        do_action('fvqa_thinking_ping', [
            'video_id'  => $video_id,
            'mode'      => $mode,
            'button_id' => $button_id,
        ]);

        // Retrieve + Generate
        $retriever = new FVQA_Retriever( $openai_key, $similarity, $k, $gen );
        $result = $retriever->answer( $video_id, $question, $timeHint );

        if ( is_wp_error($result) ) {
            return new \WP_REST_Response([
                'ok'    => false,
                'error' => $result->get_error_message(),
            ], 500);
        }

        return new \WP_REST_Response([
            'ok'      => true,
            'answer'  => $result['answer'],
            'sources' => $result['sources'],
            'time'    => $timeHint, // seconds or null
        ], 200);
    }
}
