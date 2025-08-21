<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Frontend {
    public function __construct() {
        // Shortcode still available if you want it on other pages
        add_shortcode('farhat_video_qa', array($this,'shortcode'));

        // Assets
        add_action('wp_enqueue_scripts', array($this,'register_assets'));

        // Auto-inject ONLY on LearnDash Topic pages (/topic/...), post type is sfwd-topic
        add_action('wp_footer', array($this,'maybe_render_topic_widget'));
    }

    public function register_assets() {
        wp_register_script('fvqa-chat', FVQA_URL.'assets/js/chat.js', array('jquery'), FVQA_VERSION, true);
        wp_localize_script('fvqa-chat', 'FVQA', array(
            'rest'  => esc_url_raw( rest_url('farhat-video-qa/v1/ask') ),
            'nonce' => wp_create_nonce('wp_rest')
        ));
    }

    /**
     * Auto-inject on LearnDash Topic pages:
     * - Detects Vimeo ID from:
     *   1) LearnDash meta (_sfwd-topic → lesson_video_url or any vimeo url),
     *   2) post_content (iframe or URL),
     *   3) rendered content fallback (buffered) if needed.
     */
    public function maybe_render_topic_widget() {
        if ( ! is_singular() ) return;

        $post = get_post();
        if ( ! $post ) return;

        // Only on LearnDash Topic post type (pretty permalink base is /topic/)
        $is_ld_topic = ( $post->post_type === 'sfwd-topic' );
        if ( ! $is_ld_topic ) return;

        // Try detect Vimeo ID from LearnDash settings meta
        $video_id = $this->detect_vimeo_id_for_topic( $post->ID );

        // If not found in meta, try post content (raw)
        if ( ! $video_id ) {
            $video_id = fvqa_extract_vimeo_id_from_html( $post->post_content );
        }

        // If still not found, try to inspect known LearnDash/BuddyBoss video URL metas
        if ( ! $video_id ) {
            $video_id = $this->scan_common_video_meta( $post->ID );
        }

        if ( ! $video_id ) {
            // No video detected → do not render the widget
            return;
        }

        // Enqueue and print the widget HTML in footer
        wp_enqueue_script('fvqa-chat');
        echo $this->render_widget_markup( $video_id );
    }

    private function detect_vimeo_id_for_topic( $post_id ) {
        // LearnDash stores settings in a serialized array at _sfwd-topic
        $sfwd = get_post_meta( $post_id, '_sfwd-topic', true );
        if ( ! empty( $sfwd ) && is_array( $sfwd ) ) {
            // Common LearnDash keys for video settings
            $candidates = array(
                'lesson_video_url',
                'topic_video_url',
                'sfwd-topic_lesson_video_url',
                'sfwd-topic_topic_video_url',
                'video_url',
            );
            foreach ( $candidates as $key ) {
                if ( ! empty( $sfwd[ $key ] ) && is_string( $sfwd[ $key ] ) ) {
                    $vid = fvqa_extract_vimeo_id_from_html( $sfwd[ $key ] );
                    if ( $vid ) return $vid;
                }
            }
            // In some setups LD nests values deeper
            foreach ( $sfwd as $k => $v ) {
                if ( is_string( $v ) ) {
                    $vid = fvqa_extract_vimeo_id_from_html( $v );
                    if ( $vid ) return $vid;
                }
            }
        }

        // BuddyBoss/other themes sometimes store a direct meta
        $direct_keys = array(
            'lesson_video_url',
            'topic_video_url',
            'video_url',
        );
        foreach ( $direct_keys as $k ) {
            $val = get_post_meta( $post_id, $k, true );
            if ( $val && is_string( $val ) ) {
                $vid = fvqa_extract_vimeo_id_from_html( $val );
                if ( $vid ) return $vid;
            }
        }

        return '';
    }

    private function scan_common_video_meta( $post_id ) {
        // A best-effort scan for any meta value that looks like a Vimeo URL
        $all_meta = get_post_meta( $post_id );
        if ( empty( $all_meta ) ) return '';

        foreach ( $all_meta as $key => $vals ) {
            if ( ! is_array( $vals ) ) continue;
            foreach ( $vals as $v ) {
                if ( is_string( $v ) && ( stripos( $v, 'vimeo.com' ) !== false || stripos( $v, 'player.vimeo.com' ) !== false ) ) {
                    $vid = fvqa_extract_vimeo_id_from_html( $v );
                    if ( $vid ) return $vid;
                }
            }
        }
        return '';
    }

    private function render_widget_markup( $video_id ) {
        ob_start(); ?>
        <div class="fvqa-widget" data-video-id="<?php echo esc_attr($video_id); ?>">
            <button class="fvqa-bubble">Q&A</button>
            <div class="fvqa-panel" style="display:none;">
                <div class="fvqa-body">
                    <div class="fvqa-messages"></div>
                </div>
                <div class="fvqa-input">
                    <input type="text" placeholder="Ask about this video (try: what’s at 12:15?)" />
                    <button class="fvqa-send">Send</button>
                </div>
            </div>
        </div>
        <style>
            .fvqa-widget{position:fixed;right:20px;bottom:20px;z-index:9999;font-family:system-ui,-apple-system,Segoe UI,Roboto;}
            .fvqa-bubble{background:#111;color:#fff;border:none;border-radius:999px;padding:12px 18px;box-shadow:0 8px 20px rgba(0,0,0,.2);cursor:pointer}
            .fvqa-panel{position:fixed;right:20px;bottom:80px;width:340px;max-height:60vh;background:#fff;border-radius:12px;box-shadow:0 12px 24px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden;border:1px solid #eee}
            .fvqa-body{flex:1;overflow:auto;padding:12px}
            .fvqa-messages .msg{white-space:pre-wrap;margin:8px 0;padding:10px 12px;border-radius:10px;border:1px solid #eee;background:#fafafa}
            .fvqa-input{display:flex;border-top:1px solid #eee}
            .fvqa-input input{flex:1;border:none;padding:10px 12px;outline:none}
            .fvqa-input button{border:none;background:#111;color:#fff;padding:0 14px;cursor:pointer}
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode remains for non-topic pages, or if you want to force a specific video.
     * [farhat_video_qa video_id="123456789"]
     */
    public function shortcode( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'video_id' => ''
        ), $atts );
        $video_id = $atts['video_id'];

        if ( ! $video_id ) {
            $post = get_post();
            if ( $post ) {
                // Try to detect from the current content
                $video_id = fvqa_extract_vimeo_id_from_html( $post->post_content ) ?: '';
            }
        }

        if ( ! $video_id ) return ''; // Don’t render if we cannot detect

        wp_enqueue_script('fvqa-chat');
        return $this->render_widget_markup( $video_id );
    }
}
new FVQA_Frontend();
