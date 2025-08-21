<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fvqa_get_settings() {
    $defaults = array(
        'vimeo_token'     => '',
        'openai_key'      => '',
        'log_enabled'     => 1,
        'disable_whisper' => 0,
        'debug_logs'      => 1,
    );
    $opt = get_option( 'fvqa_settings', array() );
    return wp_parse_args( $opt, $defaults );
}

if ( ! function_exists( 'fvqa_http_with_retry' ) ) {
    function fvqa_http_with_retry( $method, $url, $args = array(), $retries = 3, $base_delay_ms = 800 ) {
        $method = strtoupper( $method );
        $fn = $method === 'POST' ? 'wp_remote_post' : 'wp_remote_get';

        $defaults = array(
            'timeout'      => 45,
            'redirection'  => 2,
            'sslverify'    => true,
            'decompress'   => true,
            'headers'      => array(
                'User-Agent' => 'Farhat-Video-QA/0.5 (+WordPress; ' . site_url() . ')'
            ),
        );
        $args = wp_parse_args( $args, $defaults );

        for ( $i = 0; $i <= $retries; $i++ ) {
            $res = call_user_func( $fn, $url, $args );

            if ( ! is_wp_error( $res ) ) {
                $code = (int) wp_remote_retrieve_response_code( $res );
                if ( $code >= 200 && $code < 300 ) return $res;
                if ( in_array( $code, array(429,500,502,503,504), true ) ) {
                    fvqa_log( "HTTP $code on $url (try $i)" );
                } else {
                    return $res;
                }
            } else {
                $msg = $res->get_error_message();
                if ( strpos($msg,'cURL error 28')!==false || strpos($msg,'timed out')!==false ) {
                    fvqa_log( "timeout/DNS on $url (try $i): $msg" );
                } else {
                    return $res;
                }
            }
            if ( $i < $retries ) { usleep( ($base_delay_ms*(1+$i))*1000 ); }
        }
        return is_wp_error($res) ? $res : new WP_Error('http_retry_exhausted','HTTP retry attempts exhausted for '.$url);
    }
}

function fvqa_extract_vimeo_id_from_html( $html ) {
    if ( preg_match( '#player\.vimeo\.com/video/(\d+)#', $html, $m ) ) return $m[1];
    if ( preg_match( '#vimeo\.com/(?:channels/[^/]+/|ondemand/[^/]+/|groups/[^/]+/videos/)?(\d+)#', $html, $m ) ) return $m[1];
    return null;
}

function fvqa_seconds_to_time( $sec ) {
    $h = floor( $sec / 3600 ); $m = floor( ( $sec % 3600 ) / 60 ); $s = $sec % 60;
    return ( $h ? $h . ':' : '' ) . sprintf( '%02d:%02d', $m, $s );
}

function fvqa_parse_vtt( $vtt ) {
    $lines = preg_split( '/\r?\n/', $vtt );
    $cues = array();
    $i = 0; $n = count( $lines );
    while ( $i < $n ) {
        $line = trim( $lines[ $i ] ); $i++;
        if ( $line === '' || preg_match( '/^WEBVTT/i', $line ) ) continue;
        if ( preg_match( '/^(\d{2}:\d{2}:\d{2}\.\d{3}|\d{2}:\d{2}\.\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2}\.\d{3}|\d{2}:\d{2}\.\d{3})/', $line, $m ) ) {
            $start = fvqa_hms_to_seconds( $m[1] );
            $end   = fvqa_hms_to_seconds( $m[2] );
            $text  = '';
            while ( $i < $n && trim( $lines[$i] ) !== '' ) { $text .= trim( $lines[$i] ) . ' '; $i++; }
            $cues[] = array( 'start' => (int) $start, 'end' => (int) $end, 'text' => trim( $text ) );
        }
    }
    return $cues;
}

function fvqa_hms_to_seconds( $hms ) {
    $parts = explode( ':', $hms );
    if ( count( $parts ) === 3 ) { list( $h, $m, $s ) = $parts; }
    else { $h = 0; list( $m, $s ) = $parts; }
    $s = (float) str_replace( ',', '.', $s );
    return (int) round( $h * 3600 + $m * 60 + $s );
}

function fvqa_chunk_text_with_timestamps( $cues, $max_chars = 700 ) {
    $chunks = array();
    $buf = ''; $start = null; $end = null;
    foreach ( $cues as $c ) {
        if ( $start === null ) $start = $c['start'];
        $candidate = trim( $buf . ' ' . $c['text'] );
        if ( strlen( $candidate ) > $max_chars && $buf !== '' ) {
            $chunks[] = array( 'start' => $start, 'end' => ( $end !== null ? $end : $c['end'] ), 'text' => trim( $buf ) );
            $buf = $c['text']; $start = $c['start']; $end = $c['end'];
        } else { $buf = $candidate; $end = $c['end']; }
    }
    if ( $buf !== '' ) $chunks[] = array( 'start' => ( $start !== null ? $start : 0 ), 'end' => ( $end !== null ? $end : 0 ), 'text' => trim( $buf ) );
    return $chunks;
}

function fvqa_cosine( $a, $b ) {
    $dot = 0.0; $na = 0.0; $nb = 0.0; $n = min( count( $a ), count( $b ) );
    for ( $i = 0; $i < $n; $i++ ) { $dot += $a[$i] * $b[$i]; $na += $a[$i] * $a[$i]; $nb += $b[$i] * $b[$i]; }
    if ( $na == 0 || $nb == 0 ) return 0.0;
    return $dot / ( sqrt( $na ) * sqrt( $nb ) );
}

function fvqa_mbsubstr( $text, $start, $len ) {
    return function_exists('mb_substr') ? mb_substr( $text, $start, $len ) : substr( $text, $start, $len );
}

function fvqa_log( $msg ) {
    $s = fvqa_get_settings();
    if ( ! empty( $s['debug_logs'] ) ) {
        error_log( '[FVQA] ' . ( is_string( $msg ) ? $msg : wp_json_encode( $msg ) ) );
    }
}

// Time parsing helpers
function fvqa_parse_time_reference( $text ) {
    $q = strtolower( trim( $text ) );

    // HH:MM:SS
    if ( preg_match( '/\b(?:(\d{1,2}):)?(\d{1,2}):(\d{2})\b/', $q, $m ) ) {
        $h = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 0;
        $m_ = (int)$m[2];
        $s  = (int)$m[3];
        return $h * 3600 + $m_ * 60 + $s;
    }
    // MM:SS
    if ( preg_match( '/\b(\d{1,2}):(\d{2})\b/', $q, $m ) ) {
        $m_ = (int)$m[1];
        $s  = (int)$m[2];
        return $m_ * 60 + $s;
    }
    // explicit seconds
    if ( preg_match( '/\b(\d{1,5})\s*(?:sec|secs|s)\b/', $q, $m ) ) {
        return (int)$m[1];
    }
    // "12m 15s"
    if ( preg_match( '/\b(\d{1,3})\s*(?:m|min|mins|minute|minutes)\s*(\d{1,2})\s*(?:s|sec|secs|second|seconds)\b/', $q, $m ) ) {
        return ( (int)$m[1] ) * 60 + ( (int)$m[2] );
    }
    // minutes only
    if ( preg_match( '/\b(?:minute|min|mins)\s*(\d{1,3})\b/', $q, $m ) ) {
        return (int)$m[1] * 60;
    }
    if ( preg_match( '/\b(\d{1,3})(?:st|nd|rd|th)?\s*minute\b/', $q, $m ) ) {
        return (int)$m[1] * 60;
    }
    if ( preg_match( '/\bat\s*(\d{1,3})\s*(?:min|mins|minute|minutes)\b/', $q, $m ) ) {
        return (int)$m[1] * 60;
    }
    // bare number with context ("at 735")
    if ( preg_match( '/\b(?:at|around|about|~)\s*(\d{2,5})\b/', $q, $m ) ) {
        $val = (int)$m[1];
        return $val >= 100 ? $val : $val * 60;
    }
    return null;
}

function fvqa_format_timestamp( $sec ) {
    $sec = max(0, (int)$sec);
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    $s = $sec % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}
