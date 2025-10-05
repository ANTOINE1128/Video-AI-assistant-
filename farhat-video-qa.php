<?php
/**
 * Plugin Name: Farhat Video AI
 * Description: Per-video Q&A for Farhat Lectures using Vimeo captions and OpenAI.
 * Version: 1.9.8
 * Author: Antoine Makdessy
 */

if ( ! defined('ABSPATH') ) { exit; }

define('FVQA_VERSION', '1.9.6');
define('FVQA_PATH', plugin_dir_path(__FILE__));
define('FVQA_URL',  plugin_dir_url(__FILE__));

// Core helpers first
require_once FVQA_PATH.'includes/helpers.php';

// Safe includes (avoid fatals if a file is missing)
foreach ([
    'includes/class-install.php',
    'includes/class-admin.php',
    'includes/class-rest.php',
    'includes/class-indexer.php',
    'includes/class-retriever.php',
    'includes/class-logger.php',
] as $rel) {
    $abs = FVQA_PATH.$rel;
    if ( file_exists($abs) ) require_once $abs;
}

// Install/repair tables on activation + at runtime
if ( class_exists('FVQA_Install') ) {
    register_activation_hook(__FILE__, ['FVQA_Install','install']);
    add_action('plugins_loaded', ['FVQA_Install','maybe_install']);
}

// 🔧 Ensure REST routes are actually registered
add_action('plugins_loaded', function () {
    if ( class_exists('FVQA_REST') ) {
        static $once = false;
        if (!$once) { new FVQA_REST(); $once = true; }
    }
});

/** Only render/enqueue on LearnDash Topic pages (sfwd-topic). */
function fvqa_is_topic_page() {
    return ( function_exists('is_singular') && is_singular('sfwd-topic') );
}

/** Enqueue front assets when needed */
function fvqa_enqueue_front() {
    if ( ! fvqa_is_topic_page() ) return;

    wp_enqueue_style('fvqa-chat', FVQA_URL.'assets/css/chat.css', [], FVQA_VERSION);
    wp_enqueue_script('jquery');
    wp_enqueue_script('fvqa-chat', FVQA_URL.'assets/js/chat.js', ['jquery'], FVQA_VERSION, true);

    $rest = [
        'url'   => esc_url_raw( rest_url('fvqa/v1/ask') ),
        'nonce' => wp_create_nonce('wp_rest'),
    ];
    wp_localize_script('fvqa-chat', 'FVQA_CFG', ['rest'=>$rest]);
}
add_action('wp_enqueue_scripts', 'fvqa_enqueue_front');

/** Render floating widget in footer on eligible pages */
function fvqa_render_widget() {
    if ( ! fvqa_is_topic_page() ) return;

    $opt = fvqa_get_settings();
    $buttons = is_array($opt['action_buttons'] ?? null) ? $opt['action_buttons'] : [];
    ?>
    <!-- Added theme class: fvqa-theme--farhat (CSS-only hook) -->
    <div class="fvqa-widget fvqa-theme--farhat" aria-live="polite">
      <div class="fvqa-header">
        <div class="fvqa-title">Farhat.AI</div>
        <div class="fvqa-header-btns">
          <button type="button" class="fvqa-btn fvqa-fullscreen" aria-label="Toggle fullscreen">⤢</button>
          <button type="button" class="fvqa-btn fvqa-close" aria-label="Minimize">×</button>
        </div>
      </div>

      <?php if (!empty($buttons)): ?>
      <div class="fvqa-actions" role="group" aria-label="Quick actions">
        <?php foreach ($buttons as $row):
            $label = esc_html($row['label'] ?? '');
            $id    = esc_attr($row['id'] ?? '');
            if ($label==='' || $id==='') continue;
            $make_audio = !empty($row['make_audio']) || (stripos($label, 'audio') !== false);
            $data_audio_attr = $make_audio ? ' data-audio="1"' : '';
        ?>
          <button type="button" class="fvqa-action-btn" data-id="<?php echo $id; ?>"<?php echo $data_audio_attr; ?>>
            <?php echo $label; ?>
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="fvqa-body">
        <div class="fvqa-messages" aria-live="polite"></div>
        <div class="fvqa-thinking" hidden>thinking…</div>
        <div class="fvqa-input">
          <textarea class="fvqa-text" placeholder="Ask about this lecture (e.g., “what happens at 12:15?”)"></textarea>
          <button class="fvqa-send" type="button">Send</button>
        </div>
      </div>
    </div>
    <?php
}
add_action('wp_footer', 'fvqa_render_widget', 40);
