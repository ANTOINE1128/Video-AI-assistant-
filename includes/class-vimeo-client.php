<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Vimeo_Client {
    private $token;
    public function __construct( $token ) { $this->token = $token; }

    private function api( $path ) {
        $url = 'https://api.vimeo.com' . $path;
        $res = fvqa_http_with_retry( 'GET', $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
            ),
            'timeout' => 45,
        ), 3 );

        if ( is_wp_error( $res ) ) return $res;
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error('vimeo_http', 'Vimeo API error: ' . $code . ' ' . wp_remote_retrieve_body( $res ), array('status'=>$code));
        }
        return json_decode( wp_remote_retrieve_body( $res ), true );
    }

    public function fetch_text_tracks( $video_id ) {
        return $this->api( '/videos/' . rawurlencode( $video_id ) . '/texttracks' );
    }

    public function fetch_download_links( $video_id ) {
        return $this->api( '/videos/' . rawurlencode( $video_id ) . '?fields=download' );
    }

    public function get_en_caption_url( $video_id ) {
        $tracks = $this->fetch_text_tracks( $video_id );
        if ( is_wp_error( $tracks ) ) return $tracks;
        if ( empty( $tracks['data'] ) || ! is_array( $tracks['data'] ) ) return null;

        $best = null; $bestScore = -1;
        foreach ( $tracks['data'] as $t ) {
            $lang  = strtolower( $t['language'] ?? '' );
            $type  = strtolower( $t['type'] ?? '' );
            $link  = $t['link'] ?? '';
            $active = ! empty( $t['active'] );
            if ( ! $link ) continue;

            $isEnglish = (
                $lang === 'en' ||
                $lang === 'en-us' ||
                $lang === 'en-gb' ||
                $lang === 'en-x-autogen' ||
                strpos( $lang, 'en-' ) === 0
            );
            if ( ! $isEnglish ) continue;

            $score = 0;
            if ( $active ) $score += 4;
            if ( $type === 'captions' ) $score += 2;
            if ( $lang === 'en' ) $score += 1;

            if ( $score > $bestScore ) { $bestScore = $score; $best = $link; }
        }
        return $best ?: null;
    }

    public function download_url( $url ) {
        $res = fvqa_http_with_retry( 'GET', $url, array( 'timeout' => 60 ), 3 );
        if ( is_wp_error( $res ) ) return $res;
        if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
            return new WP_Error( 'http', 'Failed to download resource', array( 'status' => 400 ) );
        }
        return wp_remote_retrieve_body( $res );
    }
}
