<?php
/**
 * Plugin Name:       Farhat Video Q&A
 * Description:       Per-video Q&A from Vimeo transcripts using RAG. English only. Floating chat bubble on video pages.
 * Version:           0.1.0
 * Author:            Farhat Lectures
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ---- Constants
define( 'FVQA_VER', '0.1.0' );
define( 'FVQA_DIR', plugin_dir_path( __FILE__ ) );
define( 'FVQA_URL', plugin_dir_url( __FILE__ ) );

// ---- Includes
require_once FVQA_DIR . 'includes/helpers.php';
require_once FVQA_DIR . 'includes/class-settings.php';
require_once FVQA_DIR . 'includes/class-vimeo-client.php';
require_once FVQA_DIR . 'includes/class-transcriber.php';
require_once FVQA_DIR . 'includes/class-indexer.php';
require_once FVQA_DIR . 'includes/class-retriever.php';
require_once FVQA_DIR . 'includes/class-rest.php';

// ---- Activation: create DB tables
register_activation_hook( __FILE__, function() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $tbl1 = $wpdb->prefix . 'fvqa_video_index';
    $sql1 = "CREATE TABLE IF NOT EXISTS $tbl1 (
        video_id VARCHAR(64) PRIMARY KEY,
        title TEXT NULL,
        duration INT NULL,
        has_captions TINYINT(1) DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";

    $tbl2 = $wpdb->prefix . 'fvqa_chunks';
    $sql2 = "CREATE TABLE IF NOT EXISTS $tbl2 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        video_id VARCHAR(64) NOT NULL,
        start_sec INT NOT NULL,
        end_sec INT NOT NULL,
        text MEDIUMTEXT NOT NULL,
        embedding MEDIUMTEXT NULL,
        INDEX(video_id)
    ) $charset;";

    $tbl3 = $wpdb->prefix . 'fvqa_logs';
    $sql3 = "CREATE TABLE IF NOT EXISTS $tbl3 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        video_id VARCHAR(64) NOT NULL,
        question TEXT NOT NULL,
        answer MEDIUMTEXT NOT NULL,
        citations MEDIUMTEXT NULL,
        tokens_in INT NULL,
        tokens_out INT NULL,
        cost DECIMAL(10,4) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(video_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( array( $sql1, $sql2, $sql3 ) );
} );

// ---- Assets + auto-inject widget on pages containing Vimeo iframe
add_action( 'wp_enqueue_scripts', function() {
    wp_register_style( 'fvqa-widget', FVQA_URL . 'assets/css/widget.css', array(), FVQA_VER );
    wp_register_script( 'fvqa-widget', FVQA_URL . 'assets/js/widget.js', array('jquery'), FVQA_VER, true );

    $settings = fvqa_get_settings();
    wp_localize_script( 'fvqa-widget', 'FVQA', array(
        'rest' => array(
            'url'   => esc_url_raw( rest_url( 'farhat-video-qa/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ),
        'ui' => array(
            'bubbleLabel' => 'Ask this video',
        ),
        'opts' => array(
            'log' => ! empty( $settings['log_enabled'] ) ? 1 : 0,
        )
    ) );
} );

// Append bubble container if Vimeo iframe present
add_filter( 'the_content', function( $content ) {
    if ( is_admin() || is_feed() ) return $content;

    if ( strpos( $content, 'player.vimeo.com/video/' ) !== false ) {
        $video_id = fvqa_extract_vimeo_id_from_html( $content );
        if ( $video_id ) {
            wp_enqueue_style( 'fvqa-widget' );
            wp_enqueue_script( 'fvqa-widget' );
            $bubble  = '<div class="fvqa-bubble" data-video-id="' . esc_attr( $video_id ) . '">Ask this video</div>';
            $bubble .= '<div class="fvqa-panel" data-video-id="' . esc_attr( $video_id ) . '">';
            $bubble .= '<div class="fvqa-header">Q&A for this video</div>';
            $bubble .= '<div class="fvqa-messages"></div>';
            $bubble .= '<div class="fvqa-input"><input type="text" placeholder="Type your question…" />';
            $bubble .= '<button class="fvqa-send">Ask</button></div></div>';
            return $content . $bubble;
        }
    }
    return $content;
}, 20 );

// Shortcode: [farhat_video_qa id="123456789"]
add_shortcode( 'farhat_video_qa', function( $atts ) {
    $a = shortcode_atts( array( 'id' => '' ), $atts );
    if ( ! $a['id'] ) return '';
    wp_enqueue_style( 'fvqa-widget' );
    wp_enqueue_script( 'fvqa-widget' );
    $html  = '<div class="fvqa-bubble" data-video-id="' . esc_attr( $a['id'] ) . '">Ask this video</div>';
    $html .= '<div class="fvqa-panel" data-video-id="' . esc_attr( $a['id'] ) . '">';
    $html .= '<div class="fvqa-header">Q&A for this video</div>';
    $html .= '<div class="fvqa-messages"></div>';
    $html .= '<div class="fvqa-input"><input type="text" placeholder="Type your question…" />';
    $html .= '<button class="fvqa-send">Ask</button></div></div>';
    return $html;
} );

// Init REST routes
add_action( 'rest_api_init', function() {
    ( new FVQA_REST() )->register_routes();
} );
