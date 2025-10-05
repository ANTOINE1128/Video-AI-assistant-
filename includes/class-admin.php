<?php
if ( ! defined('ABSPATH') ) { exit; }

class FVQA_Admin {
    public function __construct(){
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_init', [$this,'register']);
        add_action('admin_enqueue_scripts', [$this,'assets']);
    }

    public function menu(){
        add_menu_page('Farhat Video Q&A','Farhat.AI','manage_options','farhat-video-qa',[$this,'render'],'dashicons-format-chat',59);
    }

    public function assets($hook){
        if ($hook !== 'toplevel_page_farhat-video-qa') return;
        wp_enqueue_style('fvqa-admin', FVQA_URL.'assets/css/admin.css', [], FVQA_VERSION);
    }

    public function register(){
        register_setting('fvqa_settings_group','fvqa_settings',[$this,'sanitize']);

        add_settings_section('fvqa_keys','API Keys','__return_false','farhat-video-qa');
        add_settings_field('vimeo_token','Vimeo API Token',[$this,'field_text'],'farhat-video-qa','fvqa_keys',['key'=>'vimeo_token','type'=>'password']);
        add_settings_field('openai_key','OpenAI API Key',[$this,'field_text'],'farhat-video-qa','fvqa_keys',['key'=>'openai_key','type'=>'password']);

        add_settings_section('fvqa_model','Chat Model & Tuning (chat bubble)', '__return_false','farhat-video-qa');
        add_settings_field('openai_model','OpenAI Model',[$this,'field_model'],'farhat-video-qa','fvqa_model');
        add_settings_field('temperature','Temperature',[$this,'field_number'],'farhat-video-qa','fvqa_model',['key'=>'temperature','min'=>0,'max'=>2,'step'=>0.01]);
        add_settings_field('top_p','Top-p',[$this,'field_number'],'farhat-video-qa','fvqa_model',['key'=>'top_p','min'=>0,'max'=>1,'step'=>0.01]);
        add_settings_field('max_tokens','Max Tokens',[$this,'field_number'],'farhat-video-qa','fvqa_model',['key'=>'max_tokens','min'=>1,'max'=>8192,'step'=>1]);

        add_settings_section('fvqa_prompts','Chat Prompts (chat bubble)','__return_false','farhat-video-qa');
        add_settings_field('system_prompt','System Prompt',[$this,'field_textarea'],'farhat-video-qa','fvqa_prompts',['key'=>'system_prompt','rows'=>5]);
        add_settings_field('user_prompt','User Prompt Template',[$this,'field_textarea_help'],'farhat-video-qa','fvqa_prompts',[
            'key'=>'user_prompt','rows'=>8,
            'help'=>'Placeholders: {question}, {sources}, {timestamp}'
        ]);

        add_settings_section('fvqa_actions','Video Q&A Buttons (appear in widget)','__return_false','farhat-video-qa');
        add_settings_field('action_buttons','Buttons',[$this,'field_actions'],'farhat-video-qa','fvqa_actions');

        add_settings_section('fvqa_retrieval','Retrieval','__return_false','farhat-video-qa');
        add_settings_field('similarity_threshold','Similarity Threshold',[$this,'field_number'],'farhat-video-qa','fvqa_retrieval',['key'=>'similarity_threshold','min'=>0,'max'=>1,'step'=>0.01]);
        add_settings_field('max_chunks','Max Chunks (k)',[$this,'field_number'],'farhat-video-qa','fvqa_retrieval',['key'=>'max_chunks','min'=>1,'max'=>20,'step'=>1]);

        add_settings_section('fvqa_misc','Misc','__return_false','farhat-video-qa');
        add_settings_field('log_enabled','Keep Q&A Logs',[$this,'field_checkbox'],'farhat-video-qa','fvqa_misc',['key'=>'log_enabled','label'=>'Store user questions & answers']);
        add_settings_field('debug_logs','Debug Logs',[$this,'field_checkbox'],'farhat-video-qa','fvqa_misc',['key'=>'debug_logs','label'=>'Write debug info to error_log']);
        add_settings_field('disable_whisper','Disable Whisper Fallback',[$this,'field_checkbox'],'farhat-video-qa','fvqa_misc',['key'=>'disable_whisper','label'=>'Never send audio to Whisper']);
    }

    public function sanitize($input){
        $s=fvqa_get_settings();

        $s['vimeo_token']   = isset($input['vimeo_token'])? trim((string)$input['vimeo_token']) : $s['vimeo_token'];
        $s['openai_key']    = isset($input['openai_key']) ? trim((string)$input['openai_key'])  : $s['openai_key'];

        $s['openai_model']  = isset($input['openai_model'])? sanitize_text_field($input['openai_model']) : $s['openai_model'];
        $s['temperature']   = isset($input['temperature']) ? floatval($input['temperature']) : $s['temperature'];
        $s['top_p']         = isset($input['top_p'])       ? floatval($input['top_p'])       : $s['top_p'];
        $s['max_tokens']    = isset($input['max_tokens'])  ? intval($input['max_tokens'])    : $s['max_tokens'];
        $s['system_prompt'] = isset($input['system_prompt'])? wp_kses_post($input['system_prompt']) : $s['system_prompt'];
        $s['user_prompt']   = isset($input['user_prompt'])  ? wp_kses_post($input['user_prompt'])   : $s['user_prompt'];

        $s['similarity_threshold'] = isset($input['similarity_threshold']) ? floatval($input['similarity_threshold']) : $s['similarity_threshold'];
        $s['max_chunks'] = isset($input['max_chunks']) ? intval($input['max_chunks']) : $s['max_chunks'];

        $s['log_enabled']     = !empty($input['log_enabled']) ? 1 : 0;
        $s['debug_logs']      = !empty($input['debug_logs'])  ? 1 : 0;
        $s['disable_whisper'] = !empty($input['disable_whisper']) ? 1 : 0;

        $rows = isset($input['action_buttons']) ? fvqa_sanitize_action_buttons($input['action_buttons']) : [];
        // ensure unique ids
        $seen=[];
        foreach($rows as &$r){ if(empty($r['id']) || isset($seen[$r['id']])) $r['id']=wp_generate_uuid4(); $seen[$r['id']]=true; }
        unset($r);
        $s['action_buttons']=$rows;

        return $s;
    }

    private function val($k){ $o=fvqa_get_settings(); return $o[$k]??''; }

    public function field_text($args){
        $key=esc_attr($args['key']); $type=esc_attr($args['type']??'text');
        $val=esc_attr($this->val($key));
        printf('<input type="%s" class="regular-text" name="fvqa_settings[%s]" value="%s" autocomplete="off" />',$type,$key,$val);
    }

    public function field_model(){
        $cur=esc_attr($this->val('openai_model')); $models=fvqa_model_choices();
        echo '<select name="fvqa_settings[openai_model]">';
        foreach($models as $id=>$lbl){
            printf('<option value="%s" %s>%s</option>',esc_attr($id), selected($cur,$id,false), esc_html($lbl));
        }
        echo '</select><p class="description">Default model for the floating chat bubble. Each button can override.</p>';
    }

    public function field_number($args){
        $k=esc_attr($args['key']); $val=esc_attr($this->val($k));
        $min=isset($args['min'])?$args['min']:''; $max=isset($args['max'])?$args['max']:''; $step=isset($args['step'])?$args['step']:'';
        printf('<input type="number" name="fvqa_settings[%s]" value="%s" min="%s" max="%s" step="%s" />',$k,$val,$min,$max,$step);
    }

    public function field_textarea($args){
        $k=esc_attr($args['key']); $rows=intval($args['rows']??5); $val=esc_textarea($this->val($k));
        printf('<textarea name="fvqa_settings[%s]" rows="%d" class="large-text code">%s</textarea>',$k,$rows,$val);
    }

    public function field_textarea_help($args){
        $this->field_textarea($args);
        if (!empty($args['help'])) echo '<p class="description">'.wp_kses_post($args['help']).'</p>';
    }

    public function field_checkbox($args){
        $k=esc_attr($args['key']); $label=esc_html($args['label']??'');
        $chk=$this->val($k)?'checked':''; printf('<label><input type="checkbox" name="fvqa_settings[%s]" value="1" %s /> %s</label>',$k,$chk,$label);
    }

    public function field_actions(){
        $rows = $this->val('action_buttons'); if(!is_array($rows)) $rows=[];
        $models = fvqa_model_choices();

        echo '<p class="description">Placeholders in User Prompt: <code>{question}</code>, <code>{sources}</code>, <code>{timestamp}</code></p>';
        echo '<table class="widefat striped fvqa-actions-table"><thead><tr>';
        echo '<th style="width:180px">Label</th><th style="width:180px">Model</th><th>System Prompt</th><th>User Prompt</th><th style="width:80px">Remove</th>';
        echo '</tr></thead><tbody id="fvqa-actions-body">';

        foreach($rows as $i=>$r){
            $id=esc_attr($r['id']??''); $label=esc_attr($r['label']??''); $model=esc_attr($r['model']??'gpt-4o-mini');
            $sys=esc_textarea($r['system_prompt']??''); $usr=esc_textarea($r['user_prompt']??'');
            echo '<tr class="fvqa-row">';
            printf('<td><input type="text" name="fvqa_settings[action_buttons][%1$s][label]" value="%2$s" class="regular-text" />
                    <input type="hidden" name="fvqa_settings[action_buttons][%1$s][id]" value="%3$s" /></td>', $i,$label,$id?:wp_generate_uuid4());
            echo '<td><select name="fvqa_settings[action_buttons]['.$i.'][model]">';
            foreach($models as $mid=>$ml){ printf('<option value="%s" %s>%s</option>',esc_attr($mid), selected($model,$mid,false), esc_html($ml)); }
            echo '</select></td>';
            printf('<td><textarea name="fvqa_settings[action_buttons][%1$s][system_prompt]" rows="5" class="large-text code">%2$s</textarea></td>',$i,$sys);
            printf('<td><textarea name="fvqa_settings[action_buttons][%1$s][user_prompt]" rows="5" class="large-text code">%2$s</textarea></td>',$i,$usr);
            echo '<td><button type="button" class="button link-delete fvqa-del-row">Delete</button></td>';
            echo '</tr>';
        }

        $proto='{{row}}';
        echo '<tr class="fvqa-row fvqa-proto" style="display:none">';
        echo '<td><input type="text" name="fvqa_settings[action_buttons]['.$proto.'][label]" value="" class="regular-text" />';
        echo '<input type="hidden" name="fvqa_settings[action_buttons]['.$proto.'][id]" value="" /></td>';
        echo '<td><select name="fvqa_settings[action_buttons]['.$proto.'][model]">';
        foreach($models as $mid=>$ml){ printf('<option value="%s">%s</option>',esc_attr($mid),esc_html($ml)); }
        echo '</select></td>';
        echo '<td><textarea name="fvqa_settings[action_buttons]['.$proto.'][system_prompt]" rows="5" class="large-text code"></textarea></td>';
        echo '<td><textarea name="fvqa_settings[action_buttons]['.$proto.'][user_prompt]" rows="5" class="large-text code"></textarea></td>';
        echo '<td><button type="button" class="button link-delete fvqa-del-row">Delete</button></td>';
        echo '</tr>';

        echo '</tbody></table>';
        echo '<p><button type="button" class="button button-secondary" id="fvqa-add-action">+ Add Button</button></p>';
        ?>
        <script>
        (function(){
          const tbody=document.getElementById('fvqa-actions-body');
          const add=document.getElementById('fvqa-add-action');
          function nextIndex(){ return tbody.querySelectorAll('tr.fvqa-row:not(.fvqa-proto)').length; }
          function uuidv4(){
            if (window.crypto && window.crypto.getRandomValues){
              const b=new Uint8Array(16); crypto.getRandomValues(b);
              b[6]=(b[6]&0x0f)|0x40; b[8]=(b[8]&0x3f)|0x80;
              const h=Array.from(b).map(x=>('0'+x.toString(16)).slice(-2)).join('');
              return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,
              c=>((Math.random()*16|0)&(c==='x'?15:3)| (c==='x'?0:8)).toString(16));
          }
          add.addEventListener('click', function(){
            const proto=document.querySelector('tr.fvqa-proto');
            const clone=proto.cloneNode(true); clone.style.display=''; clone.classList.remove('fvqa-proto');
            const i=String(nextIndex()); clone.innerHTML=clone.innerHTML.replaceAll('<?php echo $proto; ?>',i);
            tbody.appendChild(clone);
            const idInput=clone.querySelector('input[type="hidden"][name*="[id]"]'); if(idInput) idInput.value=uuidv4();
          });
          tbody.addEventListener('click', function(e){
            if (e.target && e.target.classList.contains('fvqa-del-row')){
              e.preventDefault(); const tr=e.target.closest('tr'); if(tr) tr.remove();
            }
          });
        })();
        </script>
        <?php
    }

    public function render(){
        if ( ! current_user_can('manage_options') ) return;
        echo '<div class="wrap fvqa-admin"><h1>Farhat Video Q&amp;A – Settings</h1><form method="post" action="options.php">';
        settings_fields('fvqa_settings_group');
        do_settings_sections('farhat-video-qa');
        submit_button('Save Settings');
        echo '</form><hr/><p><strong>Note:</strong> Widget renders on LearnDash <code>sfwd-topic</code> pages only.</p></div>';
    }
}
new FVQA_Admin();
