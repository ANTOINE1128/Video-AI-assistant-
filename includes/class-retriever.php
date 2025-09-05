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

    /**
     * Fetch approximately N chunks evenly spaced across the whole transcript.
     * Used when question/time hint is empty to cover the *entire* video.
     */
    private function fetch_uniform_chunks($video_id, $n){
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
        // Top up tail if rounding under-shot
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

    public function answer($video_id, $question, $timeHint=null){
        global $wpdb; 
        $table = $wpdb->prefix . 'fvqa_chunks';

        // ————————— Retrieval —————————
        $chunks = [];

        // 1) Windowed selection if timestamp provided
        if ($timeHint !== null){
            $win  = 90; 
            $minS = max(0, intval($timeHint) - $win); 
            $maxS = intval($timeHint) + $win;
            $chunks = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, start_sec, end_sec, text 
                   FROM $table 
                  WHERE video_id=%s 
                    AND start_sec BETWEEN %d AND %d 
               ORDER BY start_sec ASC 
                  LIMIT 60",
                $video_id, $minS, $maxS
            ), ARRAY_A );
        }

        // 2) Keyword search if no chunks yet and question has terms
        if (empty($chunks)){
            $terms = array_values( array_filter(
                preg_split('/[^a-z0-9]+/i', strtolower((string)$question)),
                function($w){ return strlen($w) >= 3; }
            ) );
            if (!empty($terms)){
                $wheres = [];
                foreach($terms as $t){
                    $wheres[] = $wpdb->prepare("text LIKE %s", '%'.$wpdb->esc_like($t).'%');
                }
                $where = implode(' OR ', $wheres);
                $chunks = $wpdb->get_results(
                    "SELECT id, start_sec, end_sec, text 
                       FROM $table 
                      WHERE video_id='".esc_sql($video_id)."' 
                        AND ($where) 
                   ORDER BY start_sec ASC 
                      LIMIT 120",
                ARRAY_A );
            }
        }

        // 3) Whole-video sampling (no question & no time → cover the entire lecture)
        if (empty($chunks)){
            if (trim((string)$question) === '' && $timeHint === null){
                // Heavier uniform sampling for better accuracy over the *whole* video
                $targetN = min(200, max(60, $this->k * 6));
                $chunks  = $this->fetch_uniform_chunks($video_id, $targetN);
            }
        }

        // 4) Final fallback: earliest segment
        if (empty($chunks)){
            $chunks = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, start_sec, end_sec, text 
                   FROM $table 
                  WHERE video_id=%s 
               ORDER BY start_sec ASC 
                  LIMIT %d",
                $video_id, max(60, $this->k * 4)
            ), ARRAY_A );
        }

        if (empty($chunks)){
            return ['answer'=>"I couldn't find that in this video.", 'sources'=>[]];
        }

        // ————————— Build sources text and the visible citations —————————
        $snippets = []; 
        $sources  = [];
        // Larger cap so GPT-5 can see more context while staying safe
        $cap      = 24000;
        $len      = 0;

        foreach($chunks as $c){
            $tag  = '['.$this->format_timestamp($c['start_sec']).']';
            // keep tags only inside sources text (for traceability),
            // the model is told NOT to echo them in output
            $line = $tag.' '.$c['text'];
            $snippets[] = $line;
            $sources[]  = $tag;
            $len += strlen($line) + 1;
            if ($len > $cap) break;
        }

        $sources = array_values(array_unique($sources));
        $sources = array_slice($sources, 0, 10);

        // ————————— Prompt assembly —————————
        $orig_system = (string)($this->gen['system_prompt'] ?? '');
        // Enforce house rules across *all* buttons
        $system_prompt = rtrim($orig_system)."\n\n".
            "CRITICAL OUTPUT RULES:\n".
            "- Do NOT include timestamps such as [MM:SS] or [H:MM:SS] in the answer.\n".
            "- Write like a patient teacher: clear, concise explanations, short bullet points when helpful.\n".
            "- Base everything strictly on the provided excerpts; if evidence is insufficient, say so.\n";

        $user = strtr($this->gen['user_prompt'] ?? '{sources}', [
            '{question}'  => (string)$question,
            '{sources}'   => implode("\n", $snippets),
            '{timestamp}' => ($timeHint !== null ? $this->format_timestamp($timeHint) : ''),
        ]);

        // ————————— Call OpenAI —————————
        $answer = $this->openai_generate($this->gen['model'] ?? 'gpt-4o-mini', $system_prompt, $user, $this->gen);

        if ( is_wp_error($answer) ) {
            return ['answer'=>'Error: OpenAI error: '.$answer->get_error_message(), 'sources'=>$sources];
        }

        // ————————— Clean up model output (strip any timestamp echoes) —————————
        $text = $this->strip_timestamps( trim((string)$answer) );
        if ($text==='') $text='I couldn’t find that in this video.';

        return ['answer'=>$text, 'sources'=>$sources];
    }

    /** Format seconds to MM:SS (ignore hours for display in citations) */
    private function format_timestamp($sec){
        $sec = max(0, intval($sec));
        return sprintf('%02d:%02d', floor($sec/60), $sec%60);
    }

    /** Remove [MM:SS] or [H:MM:SS] blocks (and tidy surrounding punctuation/space). */
    private function strip_timestamps($s){
        // remove [H:MM:SS] and [MM:SS]
        $s = preg_replace('/\s*\[(?:\d{1,2}:)?\d{1,2}:\d{2}\]\s*/', ' ', $s);
        // collapse multiple spaces/newlines
        $s = preg_replace('/[ \t]{2,}/', ' ', $s);
        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        // clean stray " - " or "—" left by tag removal
        $s = preg_replace('/\s*[-–—]\s*(?=\n|$)/', '', $s);
        return trim($s);
    }

    /** Some models disallow temperature/top_p on Responses API (e.g., gpt-5 family). */
    private function model_supports_sampling_params($model){
        $allow = array(
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4.1',
            'gpt-4.1-mini',
            'gpt-4-turbo',
            'gpt-3.5-turbo'
        );
        foreach ($allow as $a){
            if (stripos($model, $a) === 0) return true;
        }
        return false;
    }

    /**
     * Extract assistant text from a Responses API body (no metadata).
     */
    private function extract_responses_text($body){
        if (!is_array($body)) return '';

        if (!empty($body['output_text']) && is_string($body['output_text'])) {
            return trim($body['output_text']);
        }

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
                            if (isset($c['text']) && is_string($c['text'])) {
                                $parts[] = $c['text'];
                            }
                        }
                    }
                }
                if (empty($parts) && isset($item['type'], $item['text']) && is_string($item['text'])) {
                    if (in_array((string)$item['type'], array('output_text','summary_text'), true)) {
                        $parts[] = $item['text'];
                    }
                }
            }
        }

        if (empty($parts) && !empty($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $c) {
                if (!is_array($c)) continue;
                $type = isset($c['type']) ? (string)$c['type'] : '';
                if (in_array($type, array('output_text','summary_text'), true)) {
                    if (isset($c['text']) && is_string($c['text'])) {
                        $parts[] = $c['text'];
                    }
                }
            }
        }

        $txt = trim(implode("\n\n", array_map('trim', $parts)));
        return $txt;
    }

    /**
     * OpenAI call with compatibility for Chat vs Responses API.
     */
    private function openai_generate($model, $system_prompt, $user_prompt, $gen){
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
                'max_output_tokens' => max(1, intval($gen['max_tokens'] ?? 1024)),
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

            // Optional concise debug when empty but success
            $o = function_exists('fvqa_get_settings') ? fvqa_get_settings() : array('debug_logs'=>0);
            if (!empty($o['debug_logs'])) {
                $keys = is_array($body) ? implode(',', array_slice(array_keys($body),0,8)) : '';
                error_log('[FVQA '.$model.'] Responses success but no assistant text. keys='.$keys.'; size='.strlen($raw));
            }

            // Auto-fallback to chat so user still gets an answer
            $fallback_model = 'gpt-4.1-mini';
            $payload2 = [
                'model' => $fallback_model,
                'messages' => [
                    ['role'=>'system','content'=>(string)$system_prompt],
                    ['role'=>'user','content'=>(string)$user_prompt],
                ],
                'max_tokens'  => max(1, intval($gen['max_tokens'] ?? 1024)),
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

        // Chat Completions API (gpt-4o, 4o-mini, 4.1, etc.)
        $payload = [
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>(string)$system_prompt],
                ['role'=>'user','content'=>(string)$user_prompt],
            ],
            'max_tokens'  => max(1, intval($gen['max_tokens'] ?? 1024)),
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
