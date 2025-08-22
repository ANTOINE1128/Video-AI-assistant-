<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Frontend {
    public function __construct() {
        add_shortcode('farhat_video_qa', array($this,'shortcode'));
        add_action('wp_enqueue_scripts', array($this,'register_assets'));
        add_action('wp_footer', array($this,'maybe_render_topic_widget'));
    }

    public function register_assets() {
        wp_register_style('fvqa-styles', FVQA_URL.'assets/css/fvqa.css', array(), FVQA_VERSION);
        wp_register_script('fvqa-chat', FVQA_URL.'assets/js/chat.js', array('jquery'), FVQA_VERSION, true);

        $is_logged_in = is_user_logged_in();
        wp_localize_script('fvqa-chat', 'FVQA', array(
            'rest'          => esc_url_raw( rest_url('farhat-video-qa/v1/ask') ),
            'wpNonce'       => $is_logged_in ? wp_create_nonce('wp_rest') : '',
            'publicNonce'   => ! $is_logged_in ? wp_create_nonce('fvqa_public') : '',
            'isLoggedIn'    => $is_logged_in ? 1 : 0
        ));
    }

    public function maybe_render_topic_widget() {
        if ( ! is_singular() ) return;

        $post = get_post();
        if ( ! $post || $post->post_type !== 'sfwd-topic' ) return; // LearnDash Topic only

        $video_id = $this->detect_vimeo_id_for_topic( $post->ID );
        if ( ! $video_id ) {
            $video_id = fvqa_extract_vimeo_id_from_html( $post->post_content );
        }
        if ( ! $video_id ) {
            $video_id = $this->scan_common_video_meta( $post->ID );
        }
        if ( ! $video_id ) return;

        wp_enqueue_style('fvqa-styles');
        wp_enqueue_script('fvqa-chat');
        echo $this->render_widget_markup( $video_id );
    }

    private function detect_vimeo_id_for_topic( $post_id ) {
        $sfwd = get_post_meta( $post_id, '_sfwd-topic', true );
        if ( ! empty( $sfwd ) && is_array( $sfwd ) ) {
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
            foreach ( $sfwd as $v ) {
                if ( is_string( $v ) ) {
                    $vid = fvqa_extract_vimeo_id_from_html( $v );
                    if ( $vid ) return $vid;
                }
            }
        }
        $direct_keys = array( 'lesson_video_url', 'topic_video_url', 'video_url' );
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
        $all_meta = get_post_meta( $post_id );
        if ( empty( $all_meta ) ) return '';
        foreach ( $all_meta as $vals ) {
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
        $video_attr = esc_attr( $video_id );
        ob_start(); ?>
        <div class="fvqa-widget" data-video-id="<?php echo $video_attr; ?>" aria-live="polite">
            <button type="button" class="fvqa-bubble" aria-expanded="false" aria-controls="fvqa-panel">Ask about this video</button>
            <div class="fvqa-panel" id="fvqa-panel" hidden role="dialog" aria-modal="true" aria-label="Farhat Q&A">
                <div class="fvqa-header">
                    <div class="fvqa-title">Farhat Q&amp;A</div>
                    <div class="fvqa-actions">
                        <button type="button" class="fvqa-fullscreen" aria-pressed="false" aria-label="Enter fullscreen" title="Fullscreen">
                            <span class="fvqa-icon fs-enter" aria-hidden="true"></span>
                        </button>
                        <button type="button" class="fvqa-close" aria-label="Close Q&amp;A" title="Close">&times;</button>
                    </div>
                </div>
                <div class="fvqa-body">
                    <div class="fvqa-messages" role="log" aria-live="polite" aria-relevant="additions"></div>
                </div>
                <div class="fvqa-input">
                    <label class="screen-reader-text" for="fvqa-text">Your question</label>
                    <input id="fvqa-text" type="text" autocomplete="off" maxlength="800" placeholder="Ask about this lecture (e.g., what’s at 12:15?)" />
                    <button type="button" class="fvqa-send">Send</button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // Shortcode remains for non-topic pages
    public function shortcode( $atts, $content = '' ) {
        $atts = shortcode_atts( array( 'video_id' => '' ), $atts );
        $video_id = $atts['video_id'];
        if ( ! $video_id ) {
            $post = get_post();
            if ( $post ) { $video_id = fvqa_extract_vimeo_id_from_html( $post->post_content ) ?: ''; }
        }
        if ( ! $video_id ) return '';
        wp_enqueue_style('fvqa-styles');
        wp_enqueue_script('fvqa-chat');
        return $this->render_widget_markup( $video_id );
    }
}
new FVQA_Frontend();
