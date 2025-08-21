<?php
/**
 * Plugin Name: Farhat Video Q&A
 * Description: Per-video Q&A for Vimeo lectures. Fetches captions (.vtt), indexes chunks, and answers questions (time-aware).
 * Version: 0.5.0
 * Author: Farhat-Lectures
 */

if ( ! defined('ABSPATH') ) { exit; }

define('FVQA_VERSION', '0.5.0');
define('FVQA_PATH', plugin_dir_path(__FILE__));
define('FVQA_URL', plugin_dir_url(__FILE__));

// Includes
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/install.php';
require_once __DIR__ . '/includes/class-vimeo-client.php';
require_once __DIR__ . '/includes/class-transcriber.php';
require_once __DIR__ . '/includes/class-indexer.php';
require_once __DIR__ . '/includes/class-retriever.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-frontend.php';

register_activation_hook( __FILE__, 'fvqa_install_tables' );
add_action( 'admin_init', 'fvqa_maybe_install_tables' );

// Admin settings page
add_action('admin_menu', function(){
    add_menu_page(
        'Video Q&A',
        'Video Q&A',
        'manage_options',
        'fvqa-settings',
        'fvqa_render_settings_page',
        'dashicons-format-video',
        58
    );
});

function fvqa_render_settings_page(){
    if ( ! current_user_can('manage_options') ) return;
    if ( isset($_POST['fvqa_settings_nonce']) && wp_verify_nonce($_POST['fvqa_settings_nonce'], 'fvqa_save_settings') ) {
        $opt = array(
            'vimeo_token'     => sanitize_text_field($_POST['vimeo_token'] ?? ''),
            'openai_key'      => sanitize_text_field($_POST['openai_key'] ?? ''),
            'log_enabled'     => isset($_POST['log_enabled']) ? 1 : 0,
            'disable_whisper' => isset($_POST['disable_whisper']) ? 1 : 0,
            'debug_logs'      => isset($_POST['debug_logs']) ? 1 : 0,
        );
        update_option('fvqa_settings', $opt);
        echo '<div class="updated"><p>Saved.</p></div>';
    }
    $s = fvqa_get_settings();
    ?>
    <div class="wrap">
      <h1>Farhat Video Q&A — Settings</h1>
      <form method="post">
        <?php wp_nonce_field('fvqa_save_settings', 'fvqa_settings_nonce'); ?>
        <table class="form-table">
          <tr>
            <th><label for="vimeo_token">Vimeo API Token</label></th>
            <td><input type="text" id="vimeo_token" name="vimeo_token" value="<?php echo esc_attr($s['vimeo_token']); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="openai_key">OpenAI API Key</label></th>
            <td><input type="text" id="openai_key" name="openai_key" value="<?php echo esc_attr($s['openai_key']); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th>Logs</th>
            <td><label><input type="checkbox" name="log_enabled" <?php checked($s['log_enabled']); ?>/> Keep Q&A logs</label></td>
          </tr>
          <tr>
            <th>Disable Whisper Fallback</th>
            <td><label><input type="checkbox" name="disable_whisper" <?php checked($s['disable_whisper']); ?>/> Only ingest captions / manual transcripts</label></td>
          </tr>
          <tr>
            <th>Debug Logs</th>
            <td><label><input type="checkbox" name="debug_logs" <?php checked($s['debug_logs']); ?>/> Emit debug to wp-content/debug.log</label></td>
          </tr>
        </table>
        <p><button class="button button-primary">Save Settings</button></p>
      </form>
      <hr/>
      <h2>Shortcode</h2>
      <p>Use <code>[farhat_video_qa video_id="827640407"]</code> on a lecture page. If video_id is omitted, the plugin will try to detect the first Vimeo ID from the page content.</p>
    </div>
    <?php
}

// Health endpoint (simple)
add_action('rest_api_init', function(){
    register_rest_route('farhat-video-qa/v1', '/health', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function(){
            $s = fvqa_get_settings();
            return array(
                'ok' => true,
                'vimeo_key_present' => (bool) $s['vimeo_token'],
                'openai_key_present'=> (bool) $s['openai_key'],
                'version' => FVQA_VERSION
            );
        }
    ));
});
