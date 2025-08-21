
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Vimeo_Client {
    private $token;
    public function __construct( $token ) { $this->token = $token; }

    private function api( $path ) {
        $url = 'https://api.vimeo.com' . $path;
        $res = wp_remote_get( $url, array(
            'headers' => array( 'Authorization' => 'Bearer ' . $this->token ),
            'timeout' => 30,
        ) );
        if ( is_wp_error( $res ) ) return $res;
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code < 200 || $code >= 300 ) return new WP_Error( 'vimeo_http', 'Vimeo API error: ' . $code . ' ' . wp_remote_retrieve_body( $res ) );
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
        if ( empty( $tracks['data'] ) ) return null;
        foreach ( $tracks['data'] as $t ) {
            $lang = strtolower( isset( $t['language'] ) ? $t['language'] : '' );
            $kind = strtolower( isset( $t['type'] ) ? $t['type'] : '' );
            if ( ( $kind === 'subtitles' || $kind === 'captions' ) && ( $lang === 'en' || $lang === 'en-us' || $lang === 'en-gb' ) ) {
                if ( ! empty( $t['link'] ) ) return $t['link'];
            }
        }
        return null;
    }

    public function download_url( $url ) {
        $res = wp_remote_get( $url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $res ) ) return $res;
        if ( wp_remote_retrieve_response_code( $res ) !== 200 ) return new WP_Error( 'http', 'Failed to download resource' );
        return wp_remote_retrieve_body( $res );
    }
}
