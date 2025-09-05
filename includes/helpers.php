<?php
if ( ! defined('ABSPATH') ) { exit; }

/** Plugin settings helpers */
function fvqa_default_settings(){
    return array(
        'vimeo_token'         => '',
        'openai_key'          => '',
        'openai_model'        => 'gpt-4o-mini',
        'temperature'         => 0.2,
        'top_p'               => 1.0,
        'max_tokens'          => 1200,
        'system_prompt'       => "You are a helpful teaching assistant.",
        'user_prompt'         => "Question: {question}\n\nUse only these excerpts:\n{sources}\n\nTimestamp: {timestamp}",
        'similarity_threshold'=> 0.15,
        'max_chunks'          => 12,
        'log_enabled'         => 0,
        'debug_logs'          => 0,
        'disable_whisper'     => 0,
        'action_buttons'      => array(),
        'tts_voice'           => 'alloy', // for audio notes
        'tts_model'           => 'gpt-4o-mini-tts'
    );
}

function fvqa_get_settings(){
    $opts = get_option('fvqa_settings', array());
    if (!is_array($opts)) $opts=array();
    $def  = fvqa_default_settings();
    // Merge defaults
    foreach ($def as $k=>$v){ if (!array_key_exists($k,$opts)) $opts[$k]=$v; }
    return $opts;
}

/** Simple debug logger */
function fvqa_log($msg){
    $o = fvqa_get_settings();
    if (!empty($o['debug_logs'])){
        if (is_array($msg) || is_object($msg)) $msg = wp_json_encode($msg);
        error_log('[FVQA] '.$msg);
    }
}

/** HTTP with one retry and exponential backoff */
function fvqa_http_with_retry($method, $url, $args=array(), $retries=1){
    $args = is_array($args)? $args : array();
    $args['method'] = strtoupper($method);
    $attempts = 0;
    $delay = 0.5;
    while (true){
        $attempts++;
        $res = wp_remote_request($url, $args);
        if (!is_wp_error($res)){
            $code = wp_remote_retrieve_response_code($res);
            if ($code<500) return $res;
        }
        if ($attempts > max(1,intval($retries))) return $res;
        usleep( intval($delay*1000000) );
        $delay *= 2;
    }
}

/** Dropdown of model choices for admin */
function fvqa_model_choices(){
    return array(
        'gpt-5'         => 'GPT-5 (Responses API)',
        'o3-mini'       => 'o3-mini (reasoning)',
        'gpt-4.1'       => 'gpt-4.1',
        'gpt-4.1-mini'  => 'gpt-4.1-mini',
        'gpt-4o'        => 'GPT-4o',
        'gpt-4o-mini'   => 'GPT-4o mini',
    );
}

/** Sanitize action button rows from admin */
function fvqa_sanitize_action_buttons($rows){
    if (!is_array($rows)) return array();
    $out = array();
    foreach($rows as $r){
        $out[] = array(
            'id'            => sanitize_text_field($r['id'] ?? ''),
            'label'         => sanitize_text_field($r['label'] ?? ''),
            'model'         => sanitize_text_field($r['model'] ?? 'gpt-4o-mini'),
            'system_prompt' => wp_kses_post($r['system_prompt'] ?? ''),
            'user_prompt'   => wp_kses_post($r['user_prompt'] ?? ''),
            'audio'         => !empty($r['audio']) ? 1 : 0,
        );
    }
    return $out;
}

/**
 * Text-to-Speech using OpenAI Audio Speech API.
 * Returns array( 'url' => <public URL or local file path> ) on success or WP_Error.
 */
function fvqa_tts_synthesize($api_key, $text, $voice='alloy', $model='gpt-4o-mini-tts'){
    $api_key = trim((string)$api_key);
    if ($api_key==='') return new \WP_Error('openai_key','OpenAI key is not set');

    $payload = array(
        'model' => $model,
        'input' => (string)$text,
        'voice' => $voice,
        'format'=> 'mp3',
    );

    $res = fvqa_http_with_retry('POST', 'https://api.openai.com/v1/audio/speech', array(
        'headers' => array(
            'Authorization' => 'Bearer '.$api_key,
            'Content-Type'  => 'application/json',
        ),
        'timeout' => 60,
        'body'    => wp_json_encode($payload)
    ), 1);

    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    if ($code>=400) return new \WP_Error('openai_tts', 'OpenAI TTS error: '.$code);

    // Save to uploads
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) return new \WP_Error('upload_dir', $uploads['error']);
    $dir = trailingslashit($uploads['basedir']).'fvqa-audio';
    if (!file_exists($dir)) wp_mkdir_p($dir);
    $file = $dir.'/note-'.time().'-'.wp_generate_uuid4().'.mp3';
    file_put_contents($file, $body);

    $url = trailingslashit($uploads['baseurl']).'fvqa-audio/'.basename($file);
    return array('url'=>$url);
}
