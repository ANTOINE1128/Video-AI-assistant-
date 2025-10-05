<?php
if ( ! defined('ABSPATH') ) { exit; }

class FVQA_REST {
    public function __construct(){ add_action('rest_api_init', array($this,'routes')); }

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
                        'validate_callback'=> function($v){ if ($v === null || $v === '') return true; return is_numeric($v) && intval($v) >= 0; }
                    ),
                    'want_audio'=> array(
                        'required'=>false,
                        'validate_callback'=> function($v){ return in_array($v, array(true,false,0,1,'0','1'), true); }
                    ),
                )
            )
        ));

        register_rest_route('fvqa/v1', '/warm', array(
            array(
                'methods'  => 'POST',
                'callback' => array($this,'warm'),
                'permission_callback' => '__return_true',
                'args' => array('video_id' => array('type'=>'string','required'=>true))
            )
        ));

        // Diagnostics: check chunk count and a few rows
        register_rest_route('fvqa/v1', '/chunks', array(
            array(
                'methods'  => 'GET',
                'callback' => array($this,'chunks'),
                'permission_callback' => '__return_true',
                'args' => array('video_id' => array('type'=>'string','required'=>true))
            )
        ));
    }

    private function chunks_count($video_id){
        global $wpdb; if (!$video_id) return 0;
        $table = $wpdb->prefix.'fvqa_chunks';
        return intval( $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE video_id=%s", $video_id) ) );
    }

    private function ensure_indexed_if_needed($video_id, $options){
        if (!$video_id) return;
        $have = $this->chunks_count($video_id);
        if ($have > 0) return;

        $lock_key = 'fvqa_indexing_lock_'.$video_id;
        if ( get_transient($lock_key) ) return;
        set_transient($lock_key, 1, 60);

        try {
            if ( class_exists('FVQA_Indexer') ) {
                $idx = new FVQA_Indexer($options['openai_key'], $options);
                $idx->ensure_indexed($video_id);
            }
        } catch (\Throwable $e) {
            error_log('[FVQA] Indexing failed for video '.$video_id.' : '.$e->getMessage());
        } finally {
            delete_transient($lock_key);
        }
    }

    public function chunks(\WP_REST_Request $req){
        global $wpdb;
        $video_id = (string)$req->get_param('video_id');
        $table = $wpdb->prefix.'fvqa_chunks';
        $count = $this->chunks_count($video_id);
        $sample = $wpdb->get_results( $wpdb->prepare(
            "SELECT start_sec, end_sec, LEFT(text, 160) AS text FROM $table WHERE video_id=%s ORDER BY start_sec ASC LIMIT 3",
            $video_id
        ), ARRAY_A ) ?: [];
        return new \WP_REST_Response(array(
            'video_id'=>$video_id,'count'=>$count,'sample'=>$sample
        ), 200);
    }

    public function warm(\WP_REST_Request $req){
        $o        = fvqa_get_settings();
        $video_id = (string)($req->get_param('video_id') ?? '');
        if ($video_id === '') return new \WP_REST_Response(array('ok'=>false,'error'=>'Missing video_id'), 400);

        try {
            $before = $this->chunks_count($video_id);
            $this->ensure_indexed_if_needed($video_id, $o);
            $after  = $this->chunks_count($video_id);

            $notes_status = 'skipped';
            if ( class_exists('FVQA_Retriever') && $after > 0 ) {
                $gen = array(
                    'model'         => $o['openai_model'],
                    'system_prompt' => $o['system_prompt'],
                    'user_prompt'   => $o['user_prompt'],
                    'temperature'   => $o['temperature'],
                    'top_p'         => $o['top_p'],
                    'max_tokens'    => min(800, intval($o['max_tokens'] ?? 1200)),
                    'map_model'     => 'gpt-4.1-mini'
                );
                $rtv = new FVQA_Retriever($o['openai_key'], $o['similarity_threshold'], $o['max_chunks'], $gen);
                $notes_status = $rtv->warm($video_id) ? 'cached' : 'no-notes';
            }

            if ($after <= 0) {
                return new \WP_REST_Response(array(
                    'ok'=>false,
                    'error'=>'No transcript chunks found for this video. Confirm the video has an active captions/subtitles track and that your Vimeo token can read it.',
                    'before'=>$before,'after'=>$after,'notes_cache'=>$notes_status
                ), 200);
            }

            return new \WP_REST_Response(array(
                'ok'=>true,'before'=>$before,'after'=>$after,'notes_cache'=>$notes_status
            ), 200);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(array('ok'=>false,'error'=>$e->getMessage()), 500);
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
        if ($time_hint === '' || $time_hint === null) $time_hint = null; else $time_hint = intval($time_hint);

        $gen = array(
            'model'        => $o['openai_model'],
            'system_prompt'=> $o['system_prompt'],
            'user_prompt'  => $o['user_prompt'],
            'temperature'  => $o['temperature'],
            'top_p'        => $o['top_p'],
            'max_tokens'   => $o['max_tokens']
        );

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

        try { if (!empty($video_id)) $this->ensure_indexed_if_needed($video_id, $o); } catch(\Throwable $e){ error_log('[FVQA] ensure_indexed_if_needed error: '.$e->getMessage()); }

        try {
            $rtv = new FVQA_Retriever($o['openai_key'], $o['similarity_threshold'], $o['max_chunks'], $gen);
            $res = $rtv->answer($video_id, $question, $time_hint);
        } catch(\Throwable $e){ return new \WP_REST_Response(array('error'=>'Server error: '.$e->getMessage()), 500); }

        $answer_text = (string)($res['answer'] ?? '');
        $sources     = $res['sources'] ?? array();

        if ($answer_text === '' || $answer_text === "I couldn't find that in this video.") {
            $cnt = !empty($video_id) ? $this->chunks_count($video_id) : -1;
            $msg = "[FVQA] not-found. video_id={$video_id} chunks={$cnt} q='".substr($question,0,120)."'";
            if (!empty($o['debug_logs'])) error_log($msg);
        }

        $out = array('answer'=>$answer_text, 'sources'=>$sources);
        if ($want_audio && $answer_text !== '' && stripos($answer_text,'Error:') !== 0){
            $tts = fvqa_tts_synthesize($o['openai_key'], $answer_text, $o['tts_voice'], $o['tts_model']);
            if (is_wp_error($tts)) { $out['audio_error'] = $tts->get_error_message(); }
            else { $out['audio_url'] = $tts['url']; }
        }
        return new \WP_REST_Response($out, 200);
    }
}
new FVQA_REST();
