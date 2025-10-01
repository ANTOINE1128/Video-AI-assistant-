<?php
if ( ! defined('ABSPATH') ) { exit; }

class FVQA_REST {
    public function __construct(){
        add_action('rest_api_init', array($this,'routes'));
    }

    public function routes(){
        register_rest_route('fvqa/v1', '/ask', array(
            array(
                'methods'  => 'POST',
                'callback' => array($this,'ask'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'question' => array('type'=>'string','required'=>false),
                    'mode'     => array('type'=>'string','required'=>false, 'enum'=>array('chat','button')),
                    'button_id'=> array('type'=>'string','required'=>false),
                    'video_id' => array('type'=>'string','required'=>false),
                    'time_hint'=> array(
                        'required'=>false,
                        'validate_callback'=> function($v){
                            if ($v === null) return true;
                            if ($v === '') return true;
                            return is_numeric($v) && intval($v) >= 0;
                        }
                    ),
                    'want_audio'=> array(
                        'required'=>false,
                        'validate_callback'=> function($v){ return in_array($v, array(true,false,0,1,'0','1'), true); }
                    ),
                )
            )
        ));
    }

    /** Small helper: count chunks for a video id */
    private function chunks_count($video_id){
        global $wpdb;
        if (!$video_id) return 0;
        $table = $wpdb->prefix.'fvqa_chunks';
        return intval( $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE video_id=%s", $video_id) ) );
    }

    /** Try to ensure video is indexed (with a transient lock to avoid races) */
    private function ensure_indexed_if_needed($video_id, $options){
        if (!$video_id) return;

        $have = $this->chunks_count($video_id);
        if ($have > 0) return;

        // Prevent concurrent indexing storms if multiple students click at once
        $lock_key = 'fvqa_indexing_lock_'.$video_id;
        if ( get_transient($lock_key) ) {
            // Someone else is indexing. Give it a moment to finish on next request.
            return;
        }
        set_transient($lock_key, 1, 60); // lock for 60s

        try {
            if ( class_exists('FVQA_Indexer') ) {
                $idx = new FVQA_Indexer($options['openai_key'], $options);
                $idx->ensure_indexed($video_id);
            }
        } catch (\Throwable $e) {
            // Log but don't fatal the request; retrieval may still succeed if chunks appear later.
            error_log('[FVQA] Indexing failed for video '.$video_id.' : '.$e->getMessage());
        } finally {
            delete_transient($lock_key);
        }
    }

    public function ask(\WP_REST_Request $req){
        $o = fvqa_get_settings();
        $question   = (string)($req->get_param('question') ?? '');
        $mode       = (string)($req->get_param('mode') ?? 'chat');
        $button_id  = (string)($req->get_param('button_id') ?? '');
        $video_id   = (string)($req->get_param('video_id') ?? '');
        $time_hint  = $req->get_param('time_hint');
        $want_audio = $req->get_param('want_audio');

        if ($time_hint === '' || $time_hint === null) $time_hint = null;
        else $time_hint = intval($time_hint);

        // Build generation profile
        $gen = array(
            'model'        => $o['openai_model'],
            'system_prompt'=> $o['system_prompt'],
            'user_prompt'  => $o['user_prompt'],
            'temperature'  => $o['temperature'],
            'top_p'        => $o['top_p'],
            'max_tokens'   => $o['max_tokens']
        );

        // If it's a button click, override prompts & model from that button
        if ($mode === 'button' && !empty($button_id) && !empty($o['action_buttons']) && is_array($o['action_buttons'])){
            foreach($o['action_buttons'] as $btn){
                if (!empty($btn['id']) && $btn['id'] === $button_id){
                    if (!empty($btn['model']))         $gen['model'] = $btn['model'];
                    if (!empty($btn['system_prompt'])) $gen['system_prompt'] = $btn['system_prompt'];
                    if (!empty($btn['user_prompt']))   $gen['user_prompt']   = $btn['user_prompt'];
                    if (!empty($btn['audio']))         $want_audio = $want_audio || (bool)$btn['audio'];
                    break;
                }
            }
        }

        // ✅ NEW: auto-index this video if we have zero chunks
        try {
            if (!empty($video_id)) {
                $this->ensure_indexed_if_needed($video_id, $o);
            }
        } catch(\Throwable $e){
            // Don't hard fail; proceed to retrieval with whatever we have
            error_log('[FVQA] ensure_indexed_if_needed error for '.$video_id.': '.$e->getMessage());
        }

        try {
            $rtv = new FVQA_Retriever($o['openai_key'], $o['similarity_threshold'], $o['max_chunks'], $gen);
            $res = $rtv->answer($video_id, $question, $time_hint);
        } catch(\Throwable $e){
            return new \WP_REST_Response(array('error'=>'Server error: '.$e->getMessage()), 500);
        }

        $answer_text = (string)($res['answer'] ?? '');
        $sources     = $res['sources'] ?? array();

        // If still empty, add a useful hint in debug mode
        if ($answer_text === '' || $answer_text === "I couldn't find that in this video.") {
            $cnt = !empty($video_id) ? $this->chunks_count($video_id) : -1;
            if (!empty($o['debug_logs'])) {
                error_log("[FVQA] Retrieval says 'not found'. video_id={$video_id} chunks={$cnt} q='".substr($question,0,120)."'");
            }
        }

        $out = array('answer'=>$answer_text, 'sources'=>$sources);

        if ($want_audio && $answer_text !== '' && stripos($answer_text,'Error:') !== 0){
            $tts = fvqa_tts_synthesize($o['openai_key'], $answer_text, $o['tts_voice'], $o['tts_model']);
            if (is_wp_error($tts)){
                $out['audio_error'] = $tts->get_error_message();
            } else {
                $out['audio_url'] = $tts['url'];
            }
        }

        return new \WP_REST_Response($out, 200);
    }
}

new FVQA_REST();
