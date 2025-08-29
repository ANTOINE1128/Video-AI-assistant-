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
        $this->gen = $gen;
    }

    /**
     * Fetch approximately N chunks evenly spaced across the whole transcript
     * to provide broad coverage (used when question/time hint is empty).
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
            // fetch 1 row at this offset
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
        // if rounding resulted in fewer than N, top up from the end
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
                $tail = array_reverse($tail); // keep chronological
                $rows = array_merge($rows, $tail);
            }
        }
        return $rows;
    }

    public function answer($video_id, $question, $timeHint=null){
        global $wpdb; 
        $table = $wpdb->prefix . 'fvqa_chunks';

        // Strategy:
        // 1) If timeHint provided → select ±90s window around it (up to ~30 cues).
        // 2) Else keyword LIKE search on question terms.
        // 3) Else (empty question/time) → uniform sampling across whole transcript.
        // 4) Final fallback → earliest segment.

        $chunks = [];

        // Windowed selection if timestamp provided
        if ($timeHint !== null){
            $win  = 90; 
            $minS = max(0, $timeHint - $win); 
            $maxS = $timeHint + $win;
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

        // Keyword search if no chunks yet and question has terms
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
                      LIMIT 80",
                ARRAY_A );
            }
        }

        // Uniform sampling when it's a global task (empty question & no timeHint)
        if (empty($chunks)){
            if (trim((string)$question) === '' && $timeHint === null){
                $chunks = $this->fetch_uniform_chunks($video_id, max(10, $this->k));
            }
        }

        // Final fallback: earliest segment (larger than before for breadth)
        if (empty($chunks)){
            $chunks = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, start_sec, end_sec, text 
                   FROM $table 
                  WHERE video_id=%s 
               ORDER BY start_sec ASC 
                  LIMIT %d",
                $video_id, max(40, $this->k * 3)
            ), ARRAY_A );
        }

        if (empty($chunks)){
            return ['answer'=>"I couldn't find that in this video.", 'sources'=>[]];
        }

        // Build sources text and the visible citations list
        $snippets = []; 
        $sources  = [];
        $cap      = 9000; // increased budget for broader context
        $len      = 0;

        foreach($chunks as $c){
            $tag  = '['.$this->format_timestamp($c['start_sec']).']';
            $line = $tag.' '.$c['text'];
            $snippets[] = $line;
            $sources[]  = $tag;
            $len += strlen($line) + 1;
            if ($len > $cap) break;
        }

        $sources = array_values(array_unique($sources));
        $sources = array_slice($sources, 0, 10);

        $user = strtr($this->gen['user_prompt'], [
            '{question}'  => (string)$question,
            '{sources}'   => implode("\n", $snippets),
            '{timestamp}' => ($timeHint !== null ? $this->format_timestamp($timeHint) : ''),
        ]);

        $answer = $this->openai_generate($this->gen['model'], $this->gen['system_prompt'], $user, $this->gen);

        if ( is_wp_error($answer) ) {
            return ['answer'=>'Error: OpenAI error: '.$answer->get_error_message(), 'sources'=>$sources];
        }

        $text = trim((string)$answer);
        if ($text==='') $text='I couldn’t find that in this video.';
        return ['answer'=>$text, 'sources'=>$sources];
    }

    /** Format seconds to MM:SS */
    private function format_timestamp($sec){
        $sec = max(0, intval($sec));
        return sprintf('%02d:%02d', floor($sec/60), $sec%60);
    }

    /** Call OpenAI with compatibility for Chat vs Responses API */
    private function openai_generate($model, $system_prompt, $user_prompt, $gen){
        $key = trim((string)$this->openai_key);
        if ($key==='') return new \WP_Error('openai_key','OpenAI key is not set');

        $headers = [
            'Authorization' => 'Bearer '.$key,
            'Content-Type'  => 'application/json',
        ];

        // Models that must use Responses API (o3 family, gpt-5, etc.)
        $use_responses = preg_match('/^(o[3-9]|gpt-5)/i', $model) === 1;

        if ($use_responses) {
            // Responses API
            $payload = [
                'model' => $model,
                // You can pass messages as input for Responses API
                'input' => [
                    ['role'=>'system','content'=>$system_prompt],
                    ['role'=>'user','content'=>$user_prompt],
                ],
                'temperature' => floatval($gen['temperature']),
                'top_p'       => floatval($gen['top_p']),
                // IMPORTANT: Responses API uses max_output_tokens
                'max_output_tokens' => intval($gen['max_tokens']),
            ];

            $r = fvqa_http_with_retry('POST', 'https://api.openai.com/v1/responses', [
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

            // Extract text
            if (!empty($body['output_text'])) return $body['output_text'];
            if (!empty($body['content']) && is_array($body['content'])) {
                $txt='';
                foreach($body['content'] as $p){
                    if (isset($p['text'])) $txt.=$p['text'];
                }
                return $txt!=='' ? $txt : '';
            }
            return '';
        }

        // Chat Completions API (gpt-4o, 4o-mini, 4.1-mini)
        $payload = [
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>$system_prompt],
                ['role'=>'user','content'=>$user_prompt],
            ],
            'temperature' => floatval($gen['temperature']),
            'top_p'       => floatval($gen['top_p']),
            'max_tokens'  => intval($gen['max_tokens']),
        ];

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
