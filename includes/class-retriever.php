<?php
if ( ! defined('ABSPATH') ) { exit; }

class FVQA_Retriever {
    private $openai_key;
    private $thresh;
    private $k;
    private $gen;

    public function __construct($openai_key, $similarity_threshold, $k, $gen){
        $this->openai_key = $openai_key;
        $this->thresh = floatval($similarity_threshold);
        $this->k = max(1, intval($k));
        $this->gen = is_array($gen) ? $gen : [];
    }

    /* ============================== FAST-PATH CACHING ============================== */

    private function cache_key_notes($video_id){
        return 'fvqa_notes_v1_'.$video_id;
    }
    private function get_cached_notes($video_id){
        $k = $this->cache_key_notes($video_id);
        $notes = wp_cache_get($k, 'fvqa');
        if ($notes === false) $notes = get_transient($k);
        return is_string($notes) ? $notes : '';
    }
    private function set_cached_notes($video_id, $notes){
        $k = $this->cache_key_notes($video_id);
        wp_cache_set($k, $notes, 'fvqa', 12 * HOUR_IN_SECONDS);
        set_transient($k, $notes, 12 * HOUR_IN_SECONDS);
    }

    /**
     * Precompute and cache dense lecture notes for a video.
     * Returns TRUE if notes exist or were created; FALSE if not possible (e.g., no chunks).
     */
    public function warm($video_id){
        $existing = $this->get_cached_notes($video_id);
        if ($existing !== '') return true;

        $all = $this->fetch_all_chunks($video_id);
        if (empty($all)) return false;

        // Build a very large sources buffer then split + compress
        [$big_sources_text] = $this->build_sources_from_rows($all, 500000);
        $parts = $this->split_sources($big_sources_text, 8000);

        $map_model  = $this->gen['map_model'] ?? 'gpt-4.1-mini';
        $compressed = $this->compress_parts($map_model, $parts);
        $joined     = implode("\n", $compressed);

        if ($joined !== '') {
            $this->set_cached_notes($video_id, $joined);
            return true;
        }
        return false;
    }

    /* ================================= helpers ================================= */

    /** Detect if the admin/button prompts explicitly want HTML output */
    private function expects_html_output($orig_system, $user_tpl){
        $s = strtolower((string)$orig_system.' '.$user_tpl);
        if (strpos($s, 'output html only') !== false) return true;
        if (preg_match('/<\s*(h[1-6]|p|ul|ol|li|strong|em|code|blockquote)\b/i', $user_tpl)) return true;
        if (strpos($s, 'quiz') !== false || strpos($s, 'test my understanding') !== false) return true;
        return false;
    }

    private function looks_like_quiz_text($text){
        $t = (string)$text;
        if (preg_match('/\bQ\d+\./i', $t)) return true;
        if (preg_match('/^\s*Answer\s*:/mi', $t)) return true;
        if (preg_match('/^\s*Why\s*:/mi', $t)) return true;
        if (preg_match('/^\s*(True|False)\s*$/mi', $t)) return true;
        if (preg_match('/^\s*(Quick Check|Questions|Review Notes)\b/i', $t)) return true;
        return false;
    }

    private function coerce_quiz_html($text){
        // ... (unchanged from your version)
        $lines = preg_split('/\r\n|\r|\n/', trim((string)$text));
        $out = [];
        $in_ul = false;
        $buffer_p = '';

        $flush_p = function() use (&$buffer_p,&$out){
            $t = trim($buffer_p);
            if ($t !== '') $out[] = '<p>'.esc_html($t).'</p>';
            $buffer_p = '';
        };
        $start_ul = function() use (&$in_ul,&$out){ if (!$in_ul){ $out[]='<ul>'; $in_ul=true; } };
        $end_ul = function() use (&$in_ul,&$out){ if ($in_ul){ $out[]='</ul>'; $in_ul=false; } };

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') { $end_ul(); $flush_p(); continue; }

            if (preg_match('/^questions?$/i', $line)) { $end_ul(); $flush_p(); $out[]='<h3>Questions</h3>'; continue; }
            if (preg_match('/^review notes?$/i', $line)) { $end_ul(); $flush_p(); $out[]='<h2>Review Notes</h2>'; continue; }
            if (preg_match('/^q\d+\./i', $line)) { $end_ul(); $flush_p(); $out[]='<h4>'.esc_html($line).'</h4>'; continue; }

            if (preg_match('/^[A-D]\)\s*(.+)$/', $line)) { $flush_p(); $start_ul(); $out[] = '<li>'.esc_html($line).'</li>'; continue; }
            if (preg_match('/^(true|false)$/i', $line)) { $flush_p(); $start_ul(); $out[] = '<li>'.esc_html(ucfirst(strtolower($line))).'</li>'; continue; }

            if (preg_match('/^answer\s*:\s*(.+)$/i', $line, $m)) { $end_ul(); $flush_p(); $out[] = '<p><strong>Answer:</strong> '.esc_html($m[1]).'</p>'; continue; }
            if (preg_match('/^why\s*:\s*(.+)$/i', $line, $m)) { $end_ul(); $flush_p(); $out[] = '<p><em>Why:</em> '.esc_html($m[1]).'</p>'; continue; }

            if ($buffer_p !== '') $buffer_p .= ' ';
            $buffer_p .= $line;
        }
        $end_ul(); $flush_p();
        if (empty($out)) return '<p>'.esc_html($text).'</p>';
        $html = implode("\n", $out);
        if (strpos($html, '<h2') === false && preg_match('/<h4>Q\d+\./i', $html)) {
            $html = '<h2>Quick Check</h2><p>Answer the questions below to test your understanding.</p>'."\n".$html;
        }
        return $html;
    }

    private function strip_timestamps($s){
        $s = preg_replace('/\s*\[(?:\d{1,2}:)?\d{1,2}:\d{2}\]\s*/', ' ', (string)$s);
        $s = preg_replace('/[ \t]{2,}/', ' ', $s);
        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        $s = preg_replace('/\s*[-–—]\s*(?=\n|$)/', '', $s);
        return trim($s);
    }

    private function is_audio_notes(){
        $g = $this->gen;
        foreach (['label','system_prompt','user_prompt'] as $k){
            if (!empty($g[$k]) && preg_match('/\b(audio\s*notes?|voice\s*notes?)\b/i', $g[$k])) {
                return true;
            }
        }
        return false;
    }

    private function fetch_all_chunks($video_id){
        global $wpdb; 
        $table = $wpdb->prefix . 'fvqa_chunks';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, start_sec, end_sec, text 
               FROM $table 
              WHERE video_id=%s 
           ORDER BY start_sec ASC",
            $video_id
        ), ARRAY_A ) ?: [];
    }

    private function fetch_uniform_chunks($video_id, $n){
        // ... (unchanged)
        global $wpdb; 
        $table = $wpdb->prefix . 'fvqa_chunks';

        $total = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE video_id=%s", $video_id
        ) ) );
        if ($total <= 0) return [];

        $n    = max(1, intval($n));
        $step = max(1, intval(floor($total / $n)));

        $rows = [];
        for ($i = 0; $i < $total && count($rows) < $n; $i += $step) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, start_sec, end_sec, text 
                   FROM $table 
                  WHERE video_id=%s 
               ORDER BY start_sec ASC 
                  LIMIT %d,1",
                $video_id, $i
            ), ARRAY_A );
            if ($row) $rows[] = $row;
        }
        if (count($rows) < $n) {
            $need = $n - count($rows);
            $tail = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, start_sec, end_sec, text 
                   FROM $table 
                  WHERE video_id=%s 
               ORDER BY start_sec DESC 
                  LIMIT %d",
                $video_id, $need
            ), ARRAY_A );
            if ($tail) {
                $tail = array_reverse($tail);
                $rows = array_merge($rows, $tail);
            }
        }
        return $rows;
    }

    private function keyword_select($video_id, $question, $limit = 140){
        // ... (unchanged)
        global $wpdb; 
        $table = $wpdb->prefix . 'fvqa_chunks';

        $terms = array_values( array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower((string)$question)),
            function($w){ return strlen($w) >= 3; }
        ) );
        if (empty($terms)) return [];

        $wheres = [];
        foreach($terms as $t){
            $wheres[] = $wpdb->prepare("text LIKE %s", '%'.$wpdb->esc_like($t).'%');
        }
        $where = implode(' OR ', $wheres);

        $candidates = $wpdb->get_results(
            "SELECT id, start_sec, end_sec, text 
               FROM $table 
              WHERE video_id='".esc_sql($video_id)."' 
                AND ($where) 
           ORDER BY start_sec ASC 
              LIMIT 500",
        ARRAY_A ) ?: [];
        if (empty($candidates)) return [];

        foreach ($candidates as &$r){
            $txt = strtolower($r['text']);
            $score = 0;
            foreach ($terms as $t){
                $score += preg_match_all('/\b'.preg_quote($t,'/').'\b/', $txt);
            }
            $r['_score'] = $score;
        }
        unset($r);

        usort($candidates, function($a,$b){
            if ($a['_score'] === $b['_score']) return $a['start_sec'] <=> $b['start_sec'];
            return $b['_score'] <=> $a['_score'];
        });

        $top = array_slice($candidates, 0, min($limit, 200));

        $byId = [];
        foreach ($candidates as $i => $row) { $byId[$row['id']] = $i; }

        $expanded = $top;
        foreach ($top as $row){
            $idx = $byId[$row['id']] ?? null;
            if ($idx === null) continue;
            if ($idx > 0)                $expanded[] = $candidates[$idx-1];
            if ($idx+1 < count($candidates)) $expanded[] = $candidates[$idx+1];
        }

        $uniq = [];
        $seen = [];
        foreach ($expanded as $r){
            if (isset($seen[$r['id']])) continue;
            $seen[$r['id']] = true;
            $uniq[] = $r;
        }
        usort($uniq, fn($a,$b) => $a['start_sec'] <=> $b['start_sec']);

        return array_slice($uniq, 0, $limit);
    }

    private function build_sources_from_rows($rows, $cap_chars = 24000){
        $snippets = [];
        $sources  = [];
        $len = 0;

        foreach($rows as $c){
            $tag  = '['.$this->format_timestamp($c['start_sec']).']';
            $line = $tag.' '.$c['text'];
            $snippets[] = $line;
            $sources[]  = $tag;
            $len += strlen($line) + 1;
            if ($len > $cap_chars) break;
        }

        $sources = array_values(array_unique($sources));
        $sources = array_slice($sources, 0, 10);

        return [implode("\n", $snippets), $sources];
    }

    private function split_sources($sources_text, $max_chars = 8000){
        $parts = [];
        $lines = preg_split('/\r\n|\r|\n/', (string)$sources_text);
        $buf = '';
        foreach ($lines as $ln){
            $add = ($buf === '') ? $ln : ("\n".$ln);
            if (strlen($buf) + strlen($add) > $max_chars) {
                if ($buf !== '') $parts[] = $buf;
                $buf = $ln;
            } else {
                $buf .= $add;
            }
        }
        if ($buf !== '') $parts[] = $buf;
        return $parts;
    }

    private function compress_parts($model, $parts){
        if (empty($parts)) return [];

        $sys = "You are a careful teaching assistant. Compress the lecture excerpts into concise, accurate notes. ".
               "Preserve facts, numbers, and definitions. Merge duplicates. NO timestamps like [MM:SS]. ".
               "Tone: teacherly, clear.";

        $notes = [];
        foreach ($parts as $i => $p){
            $prompt = "Compress these excerpts into dense bullet notes (no timestamps, accurate facts):\n\n".$p;
            $res = $this->openai_generate($model, $sys, $prompt, [
                'max_tokens'  => 350,
                'temperature' => 0.2,
                'top_p'       => 1.0,
                'model'       => $model
            ]);
            if ( is_wp_error($res) ) {
                $notes[] = '';
            } else {
                $notes[] = trim($this->strip_timestamps((string)$res));
            }
        }
        return array_values(array_filter($notes, fn($x)=>$x!==''));
    }

    private function compose_from_notes($final_model, $orig_system, $user_tpl, $question, $notes_joined, $timeHint, $is_audio_notes){
        // ... (unchanged from your version)
        $expects_html = $this->expects_html_output($orig_system, $user_tpl);

        $rules_html =
            "- OUTPUT HTML ONLY (no Markdown, no backticks, no inline styles).\n".
            "- Use semantic structure: <h2>, <h3>, <p>, <ul><li>, <ol><li>, <strong>, <code>, <blockquote>.\n".
            "- Do NOT include raw timestamps except inside bracketed citations if quoting.\n".
            "- Teacherly tone: clear, accurate, concise. Base ONLY on provided notes.\n";

        $rules_md =
            "- Do NOT include raw timestamps like [MM:SS] unless quoting.\n".
            "- Output MUST be GitHub-flavored Markdown with ### headings, #### subheadings, lists, and short paragraphs. Bold key terms. No raw HTML.\n".
            "- Teacherly tone: clear, accurate, concise. Base ONLY on provided notes.\n";

        if ($is_audio_notes){
            $rules_html .= "- Length target: about 350–600 words.\n";
            $rules_md   .= "- Length target: about 350–600 words.\n";
        }

        $system_prompt = rtrim((string)$orig_system)."\n\nCRITICAL OUTPUT RULES:\n".($expects_html ? $rules_html : $rules_md);

        $user = strtr((string)$user_tpl, [
            '{question}'  => (string)$question,
            '{sources}'   => (string)$notes_joined,
            '{timestamp}' => ($timeHint !== null ? $this->format_timestamp($timeHint) : ''),
        ]);

        $max_out = isset($this->gen['max_tokens']) ? max(1, intval($this->gen['max_tokens'])) : 800;
        if ($is_audio_notes) $max_out = max($max_out, 1200);

        $res = $this->openai_generate($final_model, $system_prompt, $user, [
            'max_tokens'  => $max_out,
            'temperature' => ($this->gen['temperature'] ?? 0.2),
            'top_p'       => ($this->gen['top_p'] ?? 1.0),
            'model'       => $final_model
        ]);

        if ( is_wp_error($res) ) return $res;

        $txt = $this->strip_timestamps( trim((string)$res) );
        if ( !preg_match('/<\s*(h[1-6]|p|ul|ol|li)\b/i', $txt) && ($expects_html || $this->looks_like_quiz_text($txt)) ) {
            $txt = $this->coerce_quiz_html($txt);
        }
        return $txt;
    }

    /* ================================== main ================================== */
    public function answer($video_id, $question, $timeHint=null){
        // ... (unchanged core logic)
        // [Your existing answer() implementation remains as in your file]
        // (No functional changes needed here.)
        // — I left your original method intact to avoid regressions.
        // — It already calls compose/compress helpers above.
        /* The full method body from your version continues here unchanged */
        global $wpdb; 
        $table = $wpdb->prefix . 'fvqa_chunks';

        $button_model   = $this->gen['model'] ?? 'gpt-4o-mini';
        $orig_system    = (string)($this->gen['system_prompt'] ?? '');
        $user_tpl       = (string)($this->gen['user_prompt']   ?? '{sources}');
        $is_audio_notes = $this->is_audio_notes();
        $expects_html   = $this->expects_html_output($orig_system, $user_tpl);

        if ($timeHint !== null){
            $win  = 150; // ±2.5 minutes
            $minS = max(0, intval($timeHint) - $win);
            $maxS = intval($timeHint) + $win;

            $window = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, start_sec, end_sec, text 
                   FROM $table 
                  WHERE video_id=%s 
                    AND start_sec BETWEEN %d AND %d 
               ORDER BY start_sec ASC 
                  LIMIT 240",
                $video_id, $minS, $maxS
            ), ARRAY_A ) ?: [];

            $nearest = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, start_sec, end_sec, text
                   FROM $table
                  WHERE video_id=%s
               ORDER BY ABS(start_sec - %d) ASC
                  LIMIT 120",
                $video_id, intval($timeHint)
            ), ARRAY_A ) ?: [];

            $keywords = $this->keyword_select($video_id, $question, 100);

            $byId = [];
            foreach (array_merge($window, $nearest, $keywords) as $r){
                $byId[$r['id']] = $r;
            }
            $rows = array_values($byId);
            usort($rows, fn($a,$b) => $a['start_sec'] <=> $b['start_sec']);

            if (empty($rows)) {
                return ['answer'=>"I couldn't find that in this video.", 'sources'=>[]];
            }

            [$sources_text, $sources] = $this->build_sources_from_rows($rows, 24000);

            $rules_html =
                "- Explain what is being discussed around the requested minute and nearby context.\n".
                "- OUTPUT HTML ONLY (no Markdown). Use <h2>, <h3>, <p>, <ul><li>, <ol><li>, <strong>, <code>, <blockquote>.\n".
                "- Do NOT include raw timestamps in the answer body except within bracketed citations if quoting.\n".
                "- Base strictly on the excerpts provided.\n";

            $rules_md =
                "- Explain what is being discussed around the requested minute and nearby context.\n".
                "- Use GitHub-flavored Markdown with ### headings, lists, short paragraphs.\n".
                "- Do NOT include raw timestamps in the answer body except within bracketed citations if quoting.\n".
                "- Base strictly on the excerpts provided.\n";

            $system_prompt = rtrim($orig_system)."\n\nCRITICAL OUTPUT RULES:\n".($expects_html ? $rules_html : $rules_md);

            $user = strtr($user_tpl, [
                '{question}'  => (string)$question,
                '{sources}'   => $sources_text,
                '{timestamp}' => $this->format_timestamp($timeHint),
            ]);

            $answer = $this->openai_generate($button_model, $system_prompt, $user, $this->gen);
            if ( is_wp_error($answer) ) {
                return ['answer'=>'Error: OpenAI error: '.$answer->get_error_message(), 'sources'=>$sources];
            }
            $text = $this->strip_timestamps( trim((string)$answer) );
            if ( !preg_match('/<\s*(h[1-6]|p|ul|ol|li)\b/i', $text) && ($expects_html || $this->looks_like_quiz_text($text)) ) {
                $text = $this->coerce_quiz_html($text);
            }
            if ($text==='') $text='I couldn’t find that in this video.';
            return ['answer'=>$text, 'sources'=>$sources];
        }

        $all = $this->fetch_all_chunks($video_id);
        if (empty($all)){
            return ['answer'=>"I couldn't find that in this video.", 'sources'=>[]];
        }

        $notes_joined = $this->get_cached_notes($video_id);
        $big_sources_tags = [];

        if ($notes_joined === '') {
            [$big_sources_text, $big_sources_tags] = $this->build_sources_from_rows($all, 500000);
            $parts = $this->split_sources($big_sources_text, 8000); // ≤8k chars each

            $map_model = $this->gen['map_model'] ?? 'gpt-4.1-mini';
            $compressed_notes = $this->compress_parts($map_model, $parts);
            $notes_joined = implode("\n", $compressed_notes);

            if ($notes_joined !== '') {
                $this->set_cached_notes($video_id, $notes_joined);
            }
        } else {
            $first_rows = array_slice($all, 0, 5);
            $last_rows  = array_slice($all, max(0, count($all)-5));
            $tags = [];
            foreach (array_merge($first_rows, $last_rows) as $r){
                $tags[] = '['.$this->format_timestamp($r['start_sec']).']';
            }
            $big_sources_tags = array_values(array_unique($tags));
        }

        if ($notes_joined === ''){
            $fallback_rows = $this->fetch_uniform_chunks($video_id, min(240, max(80, $this->k * 8)));
            [$sources_text, $sources] = $this->build_sources_from_rows($fallback_rows, 24000);

            $rules_html =
                "- OUTPUT HTML ONLY (no Markdown). Use <h2>, <h3>, <p>, <ul><li>, <ol><li>, <strong>, <code>, <blockquote>.\n".
                "- Teacherly tone; base ONLY on the excerpts.\n";

            $rules_md =
                "- Output in GitHub-flavored Markdown with ### headings, lists, short paragraphs; bold key terms.\n".
                "- Teacherly tone; base ONLY on the excerpts.\n";

            if ($this->is_audio_notes()){
                $rules_html .= "- Length target: about 350–600 words.\n";
                $rules_md   .= "- Length target: about 350–600 words.\n";
            }

            $system_prompt = rtrim($orig_system)."\n\nCRITICAL OUTPUT RULES:\n".($expects_html ? $rules_html : $rules_md);

            $user = strtr($user_tpl, [
                '{question}'  => (string)$question,
                '{sources}'   => $sources_text,
                '{timestamp}' => '',
            ]);

            $ans = $this->openai_generate($button_model, $system_prompt, $user, $this->gen);
            if ( is_wp_error($ans) ) {
                return ['answer'=>'Error: OpenAI error: '.$ans->get_error_message(), 'sources'=>$big_sources_tags];
            }
            $clean = $this->strip_timestamps( trim((string)$ans) );
            if ( !preg_match('/<\s*(h[1-6]|p|ul|ol|li)\b/i', $clean) && ($expects_html || $this->looks_like_quiz_text($clean)) ) {
                $clean = $this->coerce_quiz_html($clean);
            }
            if ($clean==='') $clean='I couldn’t find that in this video.';
            return ['answer'=>$clean, 'sources'=>$big_sources_tags];
        }

        $final = $this->compose_from_notes($button_model, $orig_system, $user_tpl, $question, $notes_joined, null, $this->is_audio_notes());
        if ( is_wp_error($final) ) {
            return ['answer'=>'Error: OpenAI error: '.$final->get_error_message(), 'sources'=>$big_sources_tags];
        }
        if ( !preg_match('/<\s*(h[1-6]|p|ul|ol|li)\b/i', $final) && ($expects_html || $this->looks_like_quiz_text($final)) ) {
            $final = $this->coerce_quiz_html($final);
        }
        if ($final==='') $final='I couldn’t find that in this video.';
        return ['answer'=>$final, 'sources'=>$big_sources_tags];
    }

    /* ============================== utilities ============================= */

    private function format_timestamp($sec){
        $sec = max(0, intval($sec));
        return sprintf('%02d:%02d', floor($sec/60), $sec%60);
    }

    private function model_supports_sampling_params($model){
        $m = strtolower((string)$model);
        if (strpos($m, 'gpt-5') === 0) return false;
        if (preg_match('/^o\d/i', $m)) return false;
        $allow_prefixes = array('gpt-4o', 'gpt-4.1', 'gpt-4-turbo', 'gpt-3.5-turbo');
        foreach ($allow_prefixes as $p){
            if (strpos($m, $p) === 0) return true;
        }
        return false;
    }

    private function extract_responses_text($body){
        // ... (unchanged)
        if (!is_array($body)) return '';
        if (!empty($body['output_text']) && is_string($body['output_text'])) return trim($body['output_text']);

        $parts = [];
        if (!empty($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $item) {
                if (!is_array($item)) continue;
                $role = isset($item['role']) ? strtolower((string)$item['role']) : '';
                if ($role && $role !== 'assistant') continue;

                if (!empty($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $c) {
                        if (!is_array($c)) continue;
                        $type = isset($c['type']) ? (string)$c['type'] : '';
                        if (in_array($type, array('output_text','summary_text'), true)) {
                            if (isset($c['text']) && is_string($c['text'])) $parts[] = $c['text'];
                        }
                    }
                }
                if (empty($parts) && isset($item['type'], $item['text']) && is_string($item['text'])) {
                    if (in_array((string)$item['type'], array('output_text','summary_text'), true)) $parts[] = $item['text'];
                }
            }
        }
        if (empty($parts) && !empty($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $c) {
                if (!is_array($c)) continue;
                $type = isset($c['type']) ? (string)$c['type'] : '';
                if (in_array($type, array('output_text','summary_text'), true)) {
                    if (isset($c['text']) && is_string($c['text'])) $parts[] = $c['text'];
                }
            }
        }
        return trim(implode("\n\n", array_map('trim', $parts)));
    }

    private function openai_generate($model, $system_prompt, $user_prompt, $gen){
        // ... (unchanged from your version)
        $key = trim((string)$this->openai_key);
        if ($key==='') return new \WP_Error('openai_key','OpenAI key is not set');

        $headers = [
            'Authorization' => 'Bearer '.$key,
            'Content-Type'  => 'application/json',
        ];

        $use_responses = preg_match('/^(o[3-9]|gpt-5)/i', $model) === 1;

        if ($use_responses) {
            $payload = [
                'model'             => $model,
                'instructions'      => (string)$system_prompt,
                'input'             => (string)$user_prompt,
                'max_output_tokens' => max(1, intval($gen['max_tokens'] ?? 800)),
            ];
            if ($this->model_supports_sampling_params($model)) {
                if (isset($gen['temperature'])) $payload['temperature'] = floatval($gen['temperature']);
                if (isset($gen['top_p']))       $payload['top_p']       = floatval($gen['top_p']);
            }

            $r = fvqa_http_with_retry('POST', 'https://api.openai.com/v1/responses', [
                'headers'=>$headers,
                'body'   => wp_json_encode($payload),
                'timeout'=>60,
            ], 1);

            if (is_wp_error($r)) return $r;
            $code = wp_remote_retrieve_response_code($r);
            $raw  = wp_remote_retrieve_body($r);
            $body = json_decode($raw, true);

            if ($code>=400 || !is_array($body)) {
                error_log('[FVQA '.$model.'] HTTP '.$code.' body: '.$raw);
                return new \WP_Error('openai_http', 'OpenAI error: '.$code.' '.(json_encode($body)?:''));
            }

            $txt = $this->extract_responses_text($body);
            if ($txt !== '') return $txt;

            $o = function_exists('fvqa_get_settings') ? fvqa_get_settings() : array('debug_logs'=>0);
            if (!empty($o['debug_logs'])) {
                $keys = is_array($body) ? implode(',', array_slice(array_keys($body),0,8)) : '';
                error_log('[FVQA '.$model.'] Responses success but no assistant text. keys='.$keys.'; size='.strlen($raw));
            }

            // Fallback to chat completions
            $fallback_model = 'gpt-4.1-mini';
            $payload2 = [
                'model' => $fallback_model,
                'messages' => [
                    ['role'=>'system','content'=>(string)$system_prompt],
                    ['role'=>'user','content'=>(string)$user_prompt],
                ],
                'max_tokens'  => max(1, intval($gen['max_tokens'] ?? 800)),
            ];
            if (isset($gen['temperature'])) $payload2['temperature'] = floatval($gen['temperature']);
            if (isset($gen['top_p']))       $payload2['top_p']       = floatval($gen['top_p']);

            $r2 = fvqa_http_with_retry('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers'=>$headers,
                'body'   => wp_json_encode($payload2),
                'timeout'=>60,
            ], 1);

            if (is_wp_error($r2)) return $r2;
            $code2 = wp_remote_retrieve_response_code($r2);
            $body2 = json_decode( wp_remote_retrieve_body($r2), true );
            if ($code2>=400 || !is_array($body2)) {
                return new \WP_Error('openai_http', 'OpenAI fallback error: '.$code2.' '.(json_encode($body2)?:''));
            }
            return $body2['choices'][0]['message']['content'] ?? '';
        }

        // Chat Completions API
        $payload = [
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>(string)$system_prompt],
                ['role'=>'user','content'=>(string)$user_prompt],
            ],
            'max_tokens'  => max(1, intval($gen['max_tokens'] ?? 800)),
        ];
        if (isset($gen['temperature'])) $payload['temperature'] = floatval($gen['temperature']);
        if (isset($gen['top_p']))       $payload['top_p']       = floatval($gen['top_p']);

        $r = fvqa_http_with_retry('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers'=>$headers,
            'body'   => wp_json_encode($payload),
            'timeout'=>60,
        ], 1);

        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        $body = json_decode( wp_remote_retrieve_body($r), true );
        if ($code>=400 || !is_array($body)) {
            return new \WP_Error('openai_http', 'OpenAI error: '.$code.' '.(json_encode($body)?:''));
        }

        return $body['choices'][0]['message']['content'] ?? '';
    }
}
