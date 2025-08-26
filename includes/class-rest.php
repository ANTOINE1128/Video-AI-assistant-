<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_REST {
    public function __construct() {
        add_action('rest_api_init', array($this,'routes'));
    }

    public function routes(){
        register_rest_route('farhat-qa/v1','/ask', array(
            'methods'  => 'POST',
            'callback' => array($this,'ask'),
            'permission_callback' => function(){ return is_user_logged_in() || apply_filters('fvqa_allow_public', true); },
        ));
    }

    public function ask( WP_REST_Request $req ) {
        $opt  = fvqa_get_settings();
        $body = json_decode( $req->get_body(), true );

        $question = isset($body['question']) ? sanitize_text_field($body['question']) : '';
        $timeHint = isset($body['time_hint']) ? intval($body['time_hint']) : fvqa_parse_time_hint($question);
        $actionId = isset($body['action_id']) ? sanitize_text_field($body['action_id']) : null;

        // NEW: accept client-sent video_id (preferred)
        $video_id = null;
        if ( isset($body['video_id']) && preg_match('/^\d{7,12}$/', (string)$body['video_id']) ) {
            $video_id = (string)$body['video_id'];
        }

        // Fallback: try to discover on the server
        if ( empty($video_id) ) {
            $video_id = apply_filters('fvqa_current_video_id', null);
            if ( empty($video_id) && is_singular() ) {
                $video_id = fvqa_guess_video_id_from_post( get_the_ID() );
            }
        }

        if ( empty($video_id) ) {
            return new WP_REST_Response( array('answer'=>"I couldn't find a video on this page.", 'sources'=>array()), 200 );
        }

        // Base chat config
        $gen = array(
            'model'         => $opt['openai_model'],
            'temperature'   => $opt['temperature'],
            'top_p'         => $opt['top_p'],
            'max_tokens'    => $opt['max_tokens'],
            'system_prompt' => $opt['system_prompt'],
            'user_prompt'   => $opt['user_prompt'],
        );

        // Button overrides
        if ( $actionId ) {
            foreach ( $opt['action_buttons'] as $r ) {
                if ( isset($r['id']) && $r['id'] === $actionId ) {
                    if ( ! empty($r['model']) )         $gen['model'] = $r['model'];
                    if ( ! empty($r['system_prompt']) ) $gen['system_prompt'] = $r['system_prompt'];
                    if ( ! empty($r['user_prompt']) )   $gen['user_prompt']   = $r['user_prompt'];
                    break;
                }
            }
        }

        // Inject timestamp placeholder if present
        if ( strpos($gen['user_prompt'], '{timestamp}') !== false && $timeHint !== null ) {
            $gen['user_prompt'] = str_replace('{timestamp}', fvqa_format_timestamp($timeHint), $gen['user_prompt']);
        }

        // Ensure index exists, then answer
        $indexer = new FVQA_Indexer( $opt['openai_key'], $opt );
        $indexer->ensure_indexed( $video_id );

        $retriever = new FVQA_Retriever( $opt['openai_key'], $opt['similarity_threshold'], $opt['max_chunks'], $gen );
        $result    = $retriever->answer( $video_id, $question );

        // Log (optional)
        if ( ! is_wp_error($result) && ! empty($opt['log_enabled']) ) {
            $label = '';
            if ( $actionId ) {
                foreach ($opt['action_buttons'] as $r) {
                    if ($r['id'] === $actionId) { $label = $r['label']; break; }
                }
            }
            if ( class_exists('FVQA_Logger') && ! empty($result['answer']) ) {
                $q_for_log = $question . ( $label ? ' [button: '.$label.']' : '' );
                FVQA_Logger::log($video_id, get_current_user_id(), $q_for_log, $result['answer']);
            }
        }

        return new WP_REST_Response( $result, 200 );
    }
}
new FVQA_REST();
