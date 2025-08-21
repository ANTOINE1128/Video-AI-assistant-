
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Settings {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
    }
    public static function menu() {
        add_menu_page( 'Farhat Video Q&A', 'Video Q&A', 'manage_options', 'fvqa', array( __CLASS__, 'page' ), 'dashicons-format-chat', 58 );
    }
    public static function register() {
        register_setting( 'fvqa_settings', 'fvqa_settings', array( __CLASS__, 'sanitize' ) );
        add_settings_section( 'fvqa_api', 'API Keys', '__return_null', 'fvqa' );
        add_settings_field( 'vimeo_token', 'Vimeo Access Token', array( __CLASS__, 'field_text' ), 'fvqa', 'fvqa_api', array( 'key' => 'vimeo_token' ) );
        add_settings_field( 'openai_key', 'OpenAI API Key', array( __CLASS__, 'field_text' ), 'fvqa', 'fvqa_api', array( 'key' => 'openai_key' ) );
        add_settings_section( 'fvqa_opts', 'Options', '__return_null', 'fvqa' );
        add_settings_field( 'log_enabled', 'Keep Q&A logs', array( __CLASS__, 'field_checkbox' ), 'fvqa', 'fvqa_opts', array( 'key' => 'log_enabled' ) );
    }
    public static function sanitize( $in ) {
        return array(
            'vimeo_token' => sanitize_text_field( isset( $in['vimeo_token'] ) ? $in['vimeo_token'] : '' ),
            'openai_key'  => sanitize_text_field( isset( $in['openai_key'] ) ? $in['openai_key'] : '' ),
            'log_enabled' => ! empty( $in['log_enabled'] ) ? 1 : 0,
        );
    }
    public static function field_text( $args ) {
        $opt = fvqa_get_settings();
        $key = esc_attr( $args['key'] );
        $val = esc_attr( isset( $opt[$key] ) ? $opt[$key] : '' );
        echo "<input type='password' style='width:420px' name='fvqa_settings[$key]' value='$val' autocomplete='off' />";
        if ( $key === 'vimeo_token' ) echo '<p class="description">Scopes: <code>public</code>, <code>private</code>, <code>video_files</code>.</p>';
        if ( $key === 'openai_key' ) echo '<p class="description">Used for embeddings + answers (English only).</p>';
    }
    public static function field_checkbox( $args ) {
        $opt = fvqa_get_settings();
        $key = esc_attr( $args['key'] );
        $checked = ! empty( $opt[$key] ) ? 'checked' : '';
        echo "<label><input type='checkbox' name='fvqa_settings[$key]' value='1' $checked> Enable</label>";
    }
    public static function page() {
        echo '<div class="wrap"><h1>Farhat Video Q&A</h1><form method="post" action="options.php">';
        settings_fields( 'fvqa_settings' );
        do_settings_sections( 'fvqa' );
        submit_button( 'Save Settings' );
        echo '</form><hr/>';
        echo '<h2>How to use</h2><ol>';
        echo '<li>Embed a Vimeo video on a page/topic.</li>';
        echo '<li>The plugin auto-adds a floating "Ask this video" bubble. Or use shortcode: <code>[farhat_video_qa id="123456789"]</code>.</li>';
        echo '<li>On first question, the server will fetch captions, index, and answer with citations.</li>';
        echo '</ol></div>';
    }
}
FVQA_Settings::init();
