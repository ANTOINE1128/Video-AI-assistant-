<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Frontend {
    public function __construct() {
        add_shortcode('farhat_video_qa', array($this,'shortcode'));
        add_action('wp_enqueue_scripts', array($this,'assets'));
        add_action('wp_footer', array($this,'maybe_floating_bubble'));
    }

    public function assets() {
        wp_register_style ('fvqa-chat', FVQA_URL.'assets/css/chat.css', array(), FVQA_VERSION);
        wp_register_script('fvqa-chat', FVQA_URL.'assets/js/chat.js', array('jquery'), FVQA_VERSION, true);
    }

    public function shortcode($atts) {
        ob_start();
        $this->render_widget(true);
        return ob_get_clean();
    }

    public function maybe_floating_bubble() {
        // Show only on LearnDash Topic pages (adjust if needed)
        if ( is_singular('sfwd-topic') ) {
            $this->render_widget(false);
        }
    }

    private function render_widget($inline) {
        $opt = fvqa_get_settings();
        wp_enqueue_style ('fvqa-chat');
        wp_enqueue_script('fvqa-chat');

        // Prepare action definitions for the client (no system prompt for security)
        $actions = array();
        foreach ( $opt['action_buttons'] as $row ) {
            $actions[] = array(
                'id'          => $row['id'],
                'label'       => $row['label'],
                'user_prompt' => $row['user_prompt'],
                'model'       => $row['model'],
            );
        }

        wp_localize_script('fvqa-chat', 'FVQA_CFG', array(
            'rest'       => array(
                'url'  => esc_url_raw( rest_url('farhat-qa/v1/ask') ),
                'nonce'=> wp_create_nonce('wp_rest')
            ),
            'ui'         => array(
                'title'      => 'Ask about this video',
                'thinking'   => 'Thinking…',
                'sendLabel'  => 'Send',
                'fullscreen' => 'Fullscreen',
                'close'      => 'Close',
            ),
            'actions'    => $actions,
        ));
        ?>
        <div class="fvqa-widget <?php echo $inline?'fvqa-inline':'fvqa-floating'; ?>" aria-live="polite">
            <div class="fvqa-header">
                <span class="fvqa-title">Farhat Video Q&amp;A</span>
                <div class="fvqa-header-btns">
                    <button type="button" class="fvqa-btn fvqa-fullscreen" aria-label="Fullscreen">⛶</button>
                    <button type="button" class="fvqa-btn fvqa-close" aria-label="Close">✕</button>
                </div>
            </div>

            <?php if ( !empty($actions) ) : ?>
            <div class="fvqa-actions" role="toolbar" aria-label="Quick actions">
                <?php foreach ($actions as $a): ?>
                    <button type="button" class="fvqa-action-btn" data-id="<?php echo esc_attr($a['id']); ?>">
                        <?php echo esc_html($a['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="fvqa-body">
                <div class="fvqa-messages"></div>
                <div class="fvqa-thinking" hidden><?php echo esc_html($opt['thinking_text'] ?? 'Thinking…'); ?></div>
                <div class="fvqa-input">
                    <textarea class="fvqa-text" rows="2" placeholder="Ask about this lecture… (you can include a time like 12:15)"></textarea>
                    <button class="fvqa-send"><?php echo esc_html__('Send','farhat-qa'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
new FVQA_Frontend();
