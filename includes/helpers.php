
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fvqa_get_settings() {
    $defaults = array(
        'vimeo_token' => '',
        'openai_key'  => '',
        'log_enabled' => 1,
    );
    $opt = get_option( 'fvqa_settings', array() );
    return wp_parse_args( $opt, $defaults );
}

function fvqa_extract_vimeo_id_from_html( $html ) {
    if ( preg_match( '#player\.vimeo\.com/video/(\d+)#', $html, $m ) ) return $m[1];
    if ( preg_match( '#vimeo\.com/(\d+)#', $html, $m ) ) return $m[1];
    return null;
}

function fvqa_seconds_to_time( $sec ) {
    $h = floor( $sec / 3600 ); $m = floor( ( $sec % 3600 ) / 60 ); $s = $sec % 60;
    return ( $h ? $h . ':' : '' ) . sprintf( '%02d:%02d', $m, $s );
}

// Very small VTT parser -> array of [start_sec, end_sec, text]
function fvqa_parse_vtt( $vtt ) {
    $lines = preg_split( '/\r?\n/', $vtt );
    $cues = array();
    $i = 0; $n = count( $lines );
    while ( $i < $n ) {
        $line = trim( $lines[ $i ] );
        $i++;
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
            $buf = $c['text'];
            $start = $c['start'];
            $end = $c['end'];
        } else {
            $buf = $candidate;
            $end = $c['end'];
        }
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
