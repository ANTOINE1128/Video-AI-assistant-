<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** VERSION CONSTANT (bump to clear caches) */
if ( ! defined('FVQA_VERSION') ) define('FVQA_VERSION', '1.9.1');

/** Option access with sane defaults */
function fvqa_get_settings() {
    $defaults = array(
        'vimeo_token'          => '',
        'openai_key'           => '',
        'openai_model'         => 'gpt-4o-mini',   // default chat model
        'temperature'          => 0.2,
        'top_p'                => 1.0,
        'max_tokens'           => 600,
        'system_prompt'        => "You are Farhat Lectures' teaching assistant. Answer using only the provided transcript excerpts. If unsure, say you don’t know. Be concise and precise.",
        'user_prompt'          => "Question: {question}\n\nRelevant transcript excerpts:\n{sources}",
        'similarity_threshold' => 0.60,
        'max_chunks'           => 5,
        'log_enabled'          => 1,
        'debug_logs'           => 0,
        'disable_whisper'      => 0,
        // Action buttons
        'action_buttons'       => array(),
    );
    $opt = get_option('fvqa_settings', array());
    $out = wp_parse_args( is_array($opt) ? $opt : array(), $defaults );

    // clamp
    $out['temperature'] = max(0, min(2, floatval($out['temperature'])));
    $out['top_p']       = max(0, min(1, floatval($out['top_p'])));
    $out['max_tokens']  = max(1, intval($out['max_tokens']));
    $out['similarity_threshold'] = max(0, min(1, floatval($out['similarity_threshold'])));
    $out['max_chunks']  = max(1, intval($out['max_chunks']));
    if ( empty($out['action_buttons']) || ! is_array($out['action_buttons']) ) {
        $out['action_buttons'] = array();
    }
    return $out;
}

/** Models we expose in selects (chat + buttons) */
function fvqa_model_choices() {
    return array(
        'gpt-4o-mini' => 'GPT-4o mini (fast/affordable)',
        'gpt-4o'      => 'GPT-4o',
        'gpt-4.1-mini'=> 'GPT-4.1 mini',
        'o3-mini'     => 'o3-mini (reasoning)',
        'gpt-5'       => 'GPT-5',
    );
}

/** Utility: cosine similarity */
function fvqa_cosine($a, $b) {
    $dot = 0.0; $na = 0.0; $nb  = 0.0;
    $len = min(count($a), count($b));
    for ($i=0; $i<$len; $i++) {
        $dot += $a[$i]*$b[$i];
        $na  += $a[$i]*$a[$i];
        $nb  += $b[$i]*$b[$i];
    }
    if ($na==0 || $nb==0) return 0.0;
    return $dot / (sqrt($na)*sqrt($nb));
}

/** Format seconds as [MM:SS] */
function fvqa_format_timestamp($sec) {
    $sec = max(0, intval($sec));
    $m = floor($sec/60);
    $s = $sec%60;
    return sprintf('%02d:%02d', $m, $s);
}

/** Parse time hint (12:15, 1:02:03, 725s) → seconds */
function fvqa_parse_time_hint($text) {
    $t = strtolower(trim((string)$text));
    if ( preg_match('/\b(\d{1,2}):(\d{2}):(\d{2})\b/', $t, $m) )  return intval($m[1])*3600 + intval($m[2])*60 + intval($m[3]);
    if ( preg_match('/\b(\d{1,2}):(\d{2})\b/', $t, $m) )         return intval($m[1])*60 + intval($m[2]);
    if ( preg_match('/\b(\d{1,5})\s*s(ec|econds)?\b/', $t, $m) ) return intval($m[1]);
    return null;
}

/** Robust HTTP with retries */
function fvqa_http_with_retry( $method, $url, $args, $retries = 1 ) {
    $args = is_array($args) ? $args : array();
    $args['method']  = $method;
    $args['timeout'] = isset($args['timeout']) ? $args['timeout'] : 30;

    $resp = wp_remote_request( $url, $args );
    if ( ! is_wp_error($resp) ) return $resp;

    for ($i=0;$i<$retries;$i++) {
        usleep(200000);
        $resp = wp_remote_request( $url, $args );
        if ( ! is_wp_error($resp) ) break;
    }
    return $resp;
}

/** Sanitize repeater “action buttons” payload */
function fvqa_sanitize_action_buttons( $rows ) {
    $out = array();
    if ( ! is_array($rows) ) return $out;
    $models = fvqa_model_choices();
    foreach ( $rows as $row ) {
        $id    = isset($row['id']) ? sanitize_text_field($row['id']) : '';
        if ( $id === '' ) $id = wp_generate_uuid4();
        $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
        $model = isset($row['model']) ? sanitize_text_field($row['model']) : 'gpt-4o-mini';
        if ( ! isset($models[$model]) ) $model = 'gpt-4o-mini';
        $sys   = isset($row['system_prompt']) ? wp_kses_post($row['system_prompt']) : '';
        $usr   = isset($row['user_prompt'])   ? wp_kses_post($row['user_prompt'])   : '';
        if ( $label === '' ) continue;
        $out[] = array(
            'id'            => $id,
            'label'         => $label,
            'model'         => $model,
            'system_prompt' => $sys,
            'user_prompt'   => $usr,
        );
    }
    return array_values($out);
}

/* -------------------------------------------------------
 * Vimeo ID discovery helpers (server-side fallback)
 * -----------------------------------------------------*/

/** Extract the first Vimeo numeric ID from any blob of text/HTML. */
function fvqa_extract_first_vimeo_id( $blob ) {
    if ( ! is_string($blob) || $blob === '' ) return null;

    $patterns = array(
        '/data-vimeo-id=[\'"](\d{7,12})[\'"]/i',
        '#player\.vimeo\.com\/video\/(\d{7,12})#i',
        '#vimeo\.com\/(\d{7,12})(?:\/[a-zA-Z0-9_\/\-]+)?#i',
        '#https?:\/\/vimeo\.com\/manage\/videos\/(\d{7,12})#i',
    );
    foreach ( $patterns as $re ) {
        if ( preg_match($re, $blob, $m) ) return $m[1];
    }
    return null;
}

/** Try to guess Vimeo ID from the current post content & meta. */
function fvqa_guess_video_id_from_post( $post_id ) {
    if ( ! $post_id ) return null;
    $post = get_post( $post_id );
    if ( ! $post ) return null;

    // 1) raw post content
    $id = fvqa_extract_first_vimeo_id( $post->post_content );
    if ( $id ) return $id;

    // 2) search common meta fields
    $all_meta = get_post_meta( $post_id );
    if ( is_array($all_meta) ) {
        foreach ( $all_meta as $key => $vals ) {
            foreach ( (array) $vals as $val ) {
                if ( is_string($val) ) {
                    $id = fvqa_extract_first_vimeo_id( $val );
                    if ( $id ) return $id;
                }
            }
        }
    }

    return null;
}

/** Default filter implementation used by REST if nothing provided. */
function fvqa_current_video_id_default( $value ) {
    if ( ! empty($value) ) return $value;
    if ( is_singular() ) {
        $id = fvqa_guess_video_id_from_post( get_the_ID() );
        if ( $id ) return $id;
    }
    return $value;
}
add_filter( 'fvqa_current_video_id', 'fvqa_current_video_id_default' );
