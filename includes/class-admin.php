<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Admin {
    public function __construct() {
        add_action('admin_menu', array($this,'menu'));
        add_action('admin_init', array($this,'register'));
        add_action('admin_enqueue_scripts', array($this,'assets'));
    }

    public function menu() {
        add_menu_page(
            'Farhat Video Q&A',
            'Farhat Q&A',
            'manage_options',
            'farhat-video-qa',
            array($this,'render'),
            'dashicons-format-chat',
            59
        );
    }

    public function assets($hook) {
        if ( $hook !== 'toplevel_page_farhat-video-qa' ) return;
        wp_enqueue_style('fvqa-admin', FVQA_URL.'assets/css/admin.css', array(), FVQA_VERSION);
    }

    public function register() {
        register_setting( 'fvqa_settings_group', 'fvqa_settings', array($this,'sanitize') );

        add_settings_section('fvqa_keys', 'API Keys', '__return_false', 'farhat-video-qa');
        add_settings_field('vimeo_token','Vimeo API Token', array($this,'field_text'), 'farhat-video-qa', 'fvqa_keys', array('key'=>'vimeo_token','type'=>'password'));
        add_settings_field('openai_key','OpenAI API Key', array($this,'field_text'), 'farhat-video-qa', 'fvqa_keys', array('key'=>'openai_key','type'=>'password'));

        add_settings_section('fvqa_model', 'Chat Model & Tuning', '__return_false', 'farhat-video-qa');
        add_settings_field('openai_model','OpenAI Model', array($this,'field_model'), 'farhat-video-qa', 'fvqa_model');
        add_settings_field('temperature','Temperature', array($this,'field_number'), 'farhat-video-qa', 'fvqa_model', array('key'=>'temperature','min'=>0,'max'=>2,'step'=>0.01));
        add_settings_field('top_p','Top-p', array($this,'field_number'), 'farhat-video-qa', 'fvqa_model', array('key'=>'top_p','min'=>0,'max'=>1,'step'=>0.01));
        add_settings_field('max_tokens','Max Tokens', array($this,'field_number'), 'farhat-video-qa', 'fvqa_model', array('key'=>'max_tokens','min'=>1,'max'=>8192,'step'=>1));

        add_settings_section('fvqa_prompts', 'Prompts', '__return_false', 'farhat-video-qa');
        add_settings_field('system_prompt','System Prompt', array($this,'field_textarea'), 'farhat-video-qa', 'fvqa_prompts', array('key'=>'system_prompt','rows'=>5,'placeholder'=>"You are Farhat Lectures' teaching assistant..."));
        add_settings_field('user_prompt','User Prompt Template', array($this,'field_textarea_help'), 'farhat-video-qa', 'fvqa_prompts', array(
            'key'=>'user_prompt',
            'rows'=>8,
            'placeholder'=>"Question: {question}\n\nRelevant transcript excerpts:\n{sources}\n\nInstructions: ...",
            'help'=>'Available placeholders: {question}, {sources} (a bulleted list with timestamps).'
        ));

        add_settings_section('fvqa_retrieval', 'Retrieval Settings', '__return_false', 'farhat-video-qa');
        add_settings_field('similarity_threshold','Similarity Threshold', array($this,'field_number'), 'farhat-video-qa', 'fvqa_retrieval', array('key'=>'similarity_threshold','min'=>0,'max'=>1,'step'=>0.01));
        add_settings_field('max_chunks','Max Chunks (k)', array($this,'field_number'), 'farhat-video-qa', 'fvqa_retrieval', array('key'=>'max_chunks','min'=>1,'max'=>20,'step'=>1));

        add_settings_section('fvqa_misc', 'Misc', '__return_false', 'farhat-video-qa');
        add_settings_field('log_enabled','Keep Q&A Logs', array($this,'field_checkbox'), 'farhat-video-qa', 'fvqa_misc', array('key'=>'log_enabled','label'=>'Store user questions & answers (DB)'));
        add_settings_field('debug_logs','Debug Logs', array($this,'field_checkbox'), 'farhat-video-qa', 'fvqa_misc', array('key'=>'debug_logs','label'=>'Write debug info to PHP error_log'));
        add_settings_field('disable_whisper','Disable Whisper Fallback', array($this,'field_checkbox'), 'farhat-video-qa', 'fvqa_misc', array('key'=>'disable_whisper','label'=>'Never send audio to Whisper when no captions'));
    }

    public function sanitize( $input ) {
        $s = fvqa_get_settings(); // start from defaults

        // Core fields
        $s['vimeo_token']   = isset($input['vimeo_token'])   ? trim( (string)$input['vimeo_token'] )   : $s['vimeo_token'];
        $s['openai_key']    = isset($input['openai_key'])    ? trim( (string)$input['openai_key'] )    : $s['openai_key'];

        // Model + sampling
        $s['openai_model']  = isset($input['openai_model'])  ? sanitize_text_field($input['openai_model']) : $s['openai_model'];
        $s['temperature']   = isset($input['temperature'])   ? floatval($input['temperature']) : $s['temperature'];
        $s['top_p']         = isset($input['top_p'])         ? floatval($input['top_p'])       : $s['top_p'];
        $s['max_tokens']    = isset($input['max_tokens'])    ? intval($input['max_tokens'])    : $s['max_tokens'];

        // Prompts
        $s['system_prompt'] = isset($input['system_prompt']) ? wp_kses_post( $input['system_prompt'] ) : $s['system_prompt'];
        $s['user_prompt']   = isset($input['user_prompt'])   ? wp_kses_post( $input['user_prompt'] )   : $s['user_prompt'];

        // Retrieval
        $s['similarity_threshold'] = isset($input['similarity_threshold']) ? floatval($input['similarity_threshold']) : $s['similarity_threshold'];
        $s['max_chunks']           = isset($input['max_chunks']) ? intval($input['max_chunks']) : $s['max_chunks'];

        // Flags
        $s['log_enabled']     = ! empty( $input['log_enabled'] ) ? 1 : 0;
        $s['debug_logs']      = ! empty( $input['debug_logs'] ) ? 1 : 0;
        $s['disable_whisper'] = ! empty( $input['disable_whisper'] ) ? 1 : 0;

        // Return; clamping happens in fvqa_get_settings()
        return $s;
    }

    /** Helpers */
    private function val($key){
        $opt = fvqa_get_settings();
        return isset($opt[$key]) ? $opt[$key] : '';
    }

    public function field_text($args){
        $key = esc_attr($args['key']);
        $type = !empty($args['type']) ? $args['type'] : 'text';
        $val = esc_attr($this->val($key));
        printf('<input type="%s" class="regular-text" name="fvqa_settings[%s]" value="%s" autocomplete="off" />', $type, $key, $val);
    }

    public function field_model(){
        $key = 'openai_model';
        $current = esc_attr($this->val($key));
        // 👇 Added GPT-5 option
        $models = array(
            'gpt-4o-mini' => 'GPT-4o mini (fast/affordable)',
            'gpt-4o'      => 'GPT-4o',
            'gpt-4.1-mini'=> 'GPT-4.1 mini',
            'o3-mini'     => 'o3-mini (reasoning; Responses API)',
            'gpt-5'       => 'GPT-5', // NEW
        );
        echo '<select name="fvqa_settings[openai_model]">';
        foreach($models as $id=>$label){
            printf('<option value="%s" %s>%s</option>', esc_attr($id), selected($current,$id,false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="description">Note: o3-mini uses the Responses API automatically. Others (including GPT-5) use Chat Completions unless detected otherwise.</p>';
    }

    public function field_number($args){
        $key = esc_attr($args['key']);
        $min = isset($args['min']) ? $args['min'] : '';
        $max = isset($args['max']) ? $args['max'] : '';
        $step= isset($args['step'])? $args['step']: '';
        $val = esc_attr($this->val($key));
        printf('<input type="number" name="fvqa_settings[%s]" value="%s" min="%s" max="%s" step="%s" />', $key, $val, $min, $max, $step);
    }

    public function field_textarea($args){
        $key = esc_attr($args['key']);
        $rows= isset($args['rows'])? intval($args['rows']) : 5;
        $ph  = isset($args['placeholder'])? esc_attr($args['placeholder']) : '';
        $val = esc_textarea($this->val($key));
        printf('<textarea name="fvqa_settings[%s]" rows="%d" class="large-text" placeholder="%s">%s</textarea>', $key, $rows, $ph, $val);
    }

    public function field_textarea_help($args){
        $this->field_textarea($args);
        if (!empty($args['help'])) {
            echo '<p class="description">'.wp_kses_post($args['help']).'</p>';
        }
    }

    public function field_checkbox($args){
        $key = esc_attr($args['key']);
        $label = esc_html($args['label'] ?? '');
        $checked = $this->val($key) ? 'checked' : '';
        printf('<label><input type="checkbox" name="fvqa_settings[%s]" value="1" %s /> %s</label>', $key, $checked, $label);
    }

    public function render() {
        if ( ! current_user_can('manage_options') ) { return; }
        ?>
        <div class="wrap fvqa-admin">
            <h1>Farhat Video Q&amp;A – Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('fvqa_settings_group');
                do_settings_sections('farhat-video-qa');
                submit_button('Save Settings');
                ?>
            </form>
            <hr/>
            <p><strong>Template vars:</strong> In the <em>User Prompt Template</em>, you can use <code>{question}</code> and <code>{sources}</code>. The plugin fills those each time a user asks a question.</p>
        </div>
        <?php
    }
}
new FVQA_Admin();
