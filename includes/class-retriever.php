<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * FVQA_Retriever
 * - Retrieves top-K transcript chunks from DB
 * - Uses Chat Completions for standard models (gpt-4o/4.1, etc.)
 * - Uses Responses API for reasoning models (o3/o4/gpt-5 family)
 */
class FVQA_Retriever {
    private $key;
    private $threshold;
    private $k;
    private $gen; // model + params + prompts

    public function __construct( $openai_key, $similarity_threshold = 0.6, $k = 5, $gen = array() ) {
        $this->key = $openai_key;
        $this->threshold = max(0, min(1, (float)$similarity_threshold));
        $this->k = max(1, (int)$k);
        $defaults = array(
            'model'         => 'gpt-4o-mini',
            'temperature'   => 0.2,
            'top_p'         => 1.0,
            'max_tokens'    => 600,
            'system_prompt' => "You are Farhat Lectures' teaching assistant. Answer using only the provided transcript excerpts. If unsure, say you don’t know. Be concise and precise.",
            'user_prompt'   => "Question: {question}\n\nRelevant transcript excerpts:\n{sources}",
        );
        $this->gen = array_merge($defaults, is_array($gen) ? $gen : array());
    }

    public function answer( $video_id, $question ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'fvqa_chunks';

        // Fetch candidates
        $rows = $wpdb->get_results( $wpdb->prepare("SELECT id, start_sec, end_sec, text, embedding FROM $tbl WHERE video_id=%s", $video_id), ARRAY_A );
        if ( empty($rows) ) {
            return array('answer'=>"I couldn't find that in this video.", 'sources'=>array());
        }

        // Embed the question
        $q_emb = $this->embed( $question );
        if ( is_wp_error($q_emb) ) {
            return array('answer'=>"Embedding error: ".$q_emb->get_error_message(), 'sources'=>array());
        }

        // Score rows by cosine similarity
        $scored = array();
        foreach ( $rows as $r ) {
            $emb = json_decode( $r['embedding'], true );
            if ( ! is_array($emb) ) continue;
            $score = fvqa_cosine( $q_emb, $emb );
            if ( $score >= $this->threshold ) {
                $scored[] = array(
                    'score' => $score,
                    'start'=> (int)$r['start_sec'],
                    'end'  => (int)$r['end_sec'],
                    'text' => (string)$r['text']
                );
            }
        }

        // Fall back to top scored even if under threshold
        if ( empty($scored) ) {
            foreach ($rows as $r) {
                $emb = json_decode( $r['embedding'], true );
                if ( ! is_array($emb) ) continue;
                $scored[] = array(
                    'score' => fvqa_cosine( $q_emb, $emb ),
                    'start'=> (int)$r['start_sec'],
                    'end'  => (int)$r['end_sec'],
                    'text' => (string)$r['text']
                );
            }
        }

        usort($scored, function($a,$b){ return $a['score'] < $b['score'] ? 1 : -1; });
        $top = array_slice($scored, 0, $this->k);

        // Build {sources} block with timestamps
        $src_lines = array();
        $src_tags  = array();
        foreach ($top as $t) {
            $tag = '[' . fvqa_format_timestamp($t['start']) . ']';
            $src_lines[] = "- {$tag} " . $t['text'];
            $src_tags[]  = $tag;
        }
        $sources_md = implode("\n", $src_lines);

        // Compose prompts
        $system = (string)$this->gen['system_prompt'];
        $user   = (string) str_replace(
            array('{question}','{sources}'),
            array($question, $sources_md),
            (string)$this->gen['user_prompt']
        );

        // Call OpenAI with the right API depending on the model
        $model = (string)$this->gen['model'];
        if ( $this->is_reasoning_model($model) ) {
            $resp = $this->responses_api( $system, $user );     // o3/o4/gpt-5 family
        } else {
            $resp = $this->chat_api( $system, $user );          // GPT-4o, 4.1, etc.
        }

        if ( is_wp_error($resp) ) {
            return array('answer'=>"Error: ".$resp->get_error_message(), 'sources'=>$src_tags);
        }
        return array('answer'=>$resp, 'sources'=>$src_tags);
    }

    /* ===================== OpenAI Calls ===================== */

    /** Chat Completions for standard models (uses max_tokens) */
    private function chat_api( $system, $user ) {
        $payload = array(
            'model' => $this->gen['model'],
            'messages' => array(
                array('role'=>'system','content'=>$system),
                array('role'=>'user','content'=>$user),
            ),
            'temperature' => $this->gen['temperature'],
            'top_p'       => $this->gen['top_p'],
            'max_tokens'  => $this->gen['max_tokens'],
        );

        $res = fvqa_http_with_retry( 'POST', 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 60,
        ), 2 );

        if ( is_wp_error($res) ) return $res;
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error('openai_http', 'OpenAI error: '.$code.' '.wp_remote_retrieve_body($res));
        }
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty($data['choices'][0]['message']['content']) ) {
            return new WP_Error('openai_no_content','No content returned from model.');
        }
        return (string)$data['choices'][0]['message']['content'];
    }

    /**
     * Responses API for reasoning models (o3/o4/gpt-5).
     * Uses max_output_tokens.
     * Tries both messages-shaped input and simple string input.
     */
    private function responses_api( $system, $user ) {
        // Attempt #1: messages-shaped input
        $payload_msg = array(
            'model' => $this->gen['model'],
            'input' => array(
                array(
                    'role'    => 'system',
                    'content' => array( array('type'=>'text','text'=>$system) )
                ),
                array(
                    'role'    => 'user',
                    'content' => array( array('type'=>'text','text'=>$user) )
                ),
            ),
            'max_output_tokens' => (int)$this->gen['max_tokens'],
            'temperature' => $this->gen['temperature'],
            'top_p'       => $this->gen['top_p'],
        );

        $text = $this->responses_call_and_extract($payload_msg);
        if ( is_string($text) && $text !== '' ) { return $text; }

        // Attempt #2: simple string input fallback
        $payload_str = array(
            'model' => $this->gen['model'],
            'input' => "SYSTEM:\n".$system."\n\nUSER:\n".$user,
            'max_output_tokens' => (int)$this->gen['max_tokens'],
            'temperature' => $this->gen['temperature'],
            'top_p'       => $this->gen['top_p'],
        );

        $text2 = $this->responses_call_and_extract($payload_str);
        if ( is_string($text2) && $text2 !== '' ) { return $text2; }

        return new WP_Error('openai_no_content','No content returned from reasoning model.');
    }

    /** Do the HTTP call and extract text robustly from various response shapes */
    private function responses_call_and_extract( $payload ) {
        $res = fvqa_http_with_retry( 'POST', 'https://api.openai.com/v1/responses', array(
            'headers' => array(
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 60,
        ), 2 );

        if ( is_wp_error($res) ) return $res;
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code < 200 || $code >= 300 ) {
            // Retry with minimal payload if parameters are rejected
            if ( $code === 400 ) {
                $minimal = $payload;
                unset($minimal['temperature'], $minimal['top_p']);
                $res = fvqa_http_with_retry( 'POST', 'https://api.openai.com/v1/responses', array(
                    'headers' => array(
                        'Authorization' => 'Bearer '.$this->key,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'    => wp_json_encode( $minimal ),
                    'timeout' => 60,
                ), 1 );
                if ( is_wp_error($res) ) return $res;
                $code = (int) wp_remote_retrieve_response_code( $res );
                if ( $code < 200 || $code >= 300 ) {
                    return new WP_Error('openai_http', 'OpenAI error: '.$code.' '.wp_remote_retrieve_body($res));
                }
            } else {
                return new WP_Error('openai_http', 'OpenAI error: '.$code.' '.wp_remote_retrieve_body($res));
            }
        }

        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        $text = $this->extract_text_from_responses($data);
        return $text !== '' ? $text : new WP_Error('openai_no_content','No content returned from reasoning model.');
    }

    /** Try all known output shapes from the Responses API */
    private function extract_text_from_responses( $data ) {
        // 1) Preferred: output_text
        if ( isset($data['output_text']) && is_string($data['output_text']) ) {
            return trim($data['output_text']);
        }
        // 2) choices[].message.content
        if ( !empty($data['choices'][0]['message']['content']) ) {
            return (string)$data['choices'][0]['message']['content'];
        }
        // 3) output[].content[].text
        if ( !empty($data['output']) && is_array($data['output']) ) {
            $buf = '';
            foreach ($data['output'] as $o) {
                if ( !empty($o['content']) && is_array($o['content']) ) {
                    foreach ($o['content'] as $part) {
                        if ( isset($part['text']) && is_string($part['text']) ) {
                            $buf .= $part['text'];
                        } elseif ( !empty($part['content']) && is_array($part['content']) ) {
                            foreach ($part['content'] as $inner) {
                                if ( isset($inner['text']) && is_string($inner['text']) ) {
                                    $buf .= $inner['text'];
                                }
                            }
                        }
                    }
                }
            }
            if ( trim($buf) !== '' ) return trim($buf);
        }
        // 4) message.content[].text
        if ( !empty($data['message']['content']) && is_array($data['message']['content']) ) {
            $buf = '';
            foreach ($data['message']['content'] as $c) {
                if ( isset($c['text']) ) $buf .= $c['text'];
            }
            if ( trim($buf) !== '' ) return trim($buf);
        }
        // 5) generic DFS fallback
        $buf = $this->dfs_collect_text($data);
        if ( trim($buf) !== '' ) return trim($buf);
        return '';
    }

    private function dfs_collect_text($node) {
        $out = '';
        if ( is_array($node) ) {
            foreach ($node as $k=>$v) {
                if ( $k === 'text' && is_string($v) ) { $out .= $v; }
                else { $out .= $this->dfs_collect_text($v); }
            }
        }
        return $out;
    }

    /**
     * Identify reasoning models that need the Responses API.
     * Extend to gpt-5 family.
     */
    private function is_reasoning_model( $model ) {
        $m = strtolower( (string)$model );
        return (
            strpos($m, 'o3') === 0 ||
            strpos($m, 'o4') === 0 ||
            strpos($m, 'gpt-5') === 0
        );
    }

    /* ===================== Embeddings ===================== */

    private function embed( $text ) {
        $payload = array(
            'input' => (string)$text,
            'model' => 'text-embedding-3-small'
        );
        $res = fvqa_http_with_retry( 'POST', 'https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 45,
        ), 2 );

        if ( is_wp_error($res) ) return $res;
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error('openai_embed_http', 'Embedding error: '.$code.' '.wp_remote_retrieve_body($res));
        }
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty($data['data'][0]['embedding']) || ! is_array($data['data'][0]['embedding']) ) {
            return new WP_Error('openai_embed_empty','No embedding returned.');
        }
        return array_map('floatval', $data['data'][0]['embedding']);
    }
}
