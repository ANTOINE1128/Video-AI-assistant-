<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Retriever {
    private $openai_key;
    private $similarity_threshold;
    private $top_k;

    public function __construct( $openai_key, $similarity_threshold = 0.60, $top_k = 5 ) {
        $this->openai_key = $openai_key;
        $this->similarity_threshold = $similarity_threshold;
        $this->top_k = $top_k;
    }

    public function answer( $video_id, $question ) {
        $sec = fvqa_parse_time_reference( $question );

        if ( $sec !== null ) {
            $hit = $this->retrieve_by_time( $video_id, $sec );
            if ( $hit ) {
                return $this->answer_from_chunks( $question, array( $hit ), array( fvqa_format_timestamp( $hit['start_sec'] ) ) );
            }
        }

        $chunks = $this->retrieve_semantic( $video_id, $question, $this->top_k, $this->similarity_threshold );
        if ( empty( $chunks ) ) {
            return array(
                'answer'  => "I couldn't find that in this video.",
                'sources' => array()
            );
        }

        $sources = array();
        foreach ( $chunks as $c ) {
            $sources[] = fvqa_format_timestamp( (int) $c['start_sec'] );
        }

        return $this->answer_from_chunks( $question, $chunks, $sources );
    }

    private function retrieve_by_time( $video_id, $sec, $pad = 10 ) {
        global $wpdb; $tbl = $wpdb->prefix . 'fvqa_chunks';
        $sec = (int) $sec; $pad = (int) $pad;

        // Exact containment
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, start_sec, end_sec, text FROM $tbl WHERE video_id=%s AND start_sec <= %d AND end_sec >= %d ORDER BY ABS((start_sec+end_sec)/2 - %d) ASC LIMIT 1",
                $video_id, $sec, $sec, $sec
            ), ARRAY_A
        );
        if ( $row ) return $row;

        // Nearest overlap within ±pad
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, start_sec, end_sec, text FROM $tbl WHERE video_id=%s AND start_sec <= %d AND end_sec >= %d ORDER BY ABS((start_sec+end_sec)/2 - %d) ASC LIMIT 1",
                $video_id, $sec + $pad, $sec - $pad, $sec
            ), ARRAY_A
        );
        if ( $row ) return $row;

        // Nearest neighbor
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, start_sec, end_sec, text FROM $tbl WHERE video_id=%s ORDER BY ABS((start_sec+end_sec)/2 - %d) ASC LIMIT 1",
                $video_id, $sec
            ), ARRAY_A
        );
        return $row ?: null;
    }

    private function retrieve_semantic( $video_id, $query, $k = 5, $threshold = 0.60 ) {
        global $wpdb; $tbl = $wpdb->prefix . 'fvqa_chunks';
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, start_sec, end_sec, text, embedding FROM $tbl WHERE video_id=%s", $video_id ),
            ARRAY_A
        );
        if ( empty( $rows ) ) return array();

        $qvec = $this->embed_text( $query );
        if ( empty( $qvec ) ) return array();

        $scored = array();
        foreach ( $rows as $r ) {
            $emb = json_decode( $r['embedding'], true );
            if ( empty( $emb ) || ! is_array( $emb ) ) continue;
            $sim = fvqa_cosine( $qvec, $emb );
            if ( $sim >= $threshold ) { $r['_score'] = $sim; $scored[] = $r; }
        }

        if ( empty( $scored ) ) {
            foreach ( $rows as $r ) {
                $emb = json_decode( $r['embedding'], true );
                if ( empty( $emb ) || ! is_array( $emb ) ) continue;
                $sim = fvqa_cosine( $qvec, $emb );
                $r['_score'] = $sim;
                $scored[] = $r;
            }
        }

        usort( $scored, function($a, $b){
            if ( $a['_score'] === $b['_score'] ) { return ($a['start_sec'] <=> $b['start_sec']); }
            return ($a['_score'] > $b['_score']) ? -1 : 1;
        });

        return array_slice( $scored, 0, max(1, (int)$k) );
    }

    private function answer_from_chunks( $question, $chunks, $sources = array() ) {
        $context = '';
        foreach ( $chunks as $c ) {
            $context .= "\n[" . fvqa_format_timestamp( (int) $c['start_sec'] ) . " – " . fvqa_format_timestamp( (int) $c['end_sec'] ) . "] " . $c['text'];
        }

        $prompt = "You are a helpful teaching assistant. Answer ONLY using the transcript excerpts below. "
                . "If the answer is not clearly present, say you cannot find it in the video.\n\n"
                . "Transcript excerpts:\n"
                . $context
                . "\n\nQuestion: " . $question . "\nAnswer:";

        $resp = $this->openai_chat( $prompt );

        return array(
            'answer'  => $resp ?: "I couldn't find that in this video.",
            'sources' => $sources
        );
    }

    private function embed_text( $text ) {
        $res = fvqa_http_with_retry( 'POST', 'https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->openai_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model' => 'text-embedding-3-small',
                'input' => $text
            ) ),
            'timeout' => 60,
        ), 3 );

        if ( is_wp_error( $res ) ) { fvqa_log($res->get_error_message()); return array(); }
        if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
            fvqa_log('Embeddings error: ' . wp_remote_retrieve_body($res));
            return array();
        }
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        return $json['data'][0]['embedding'] ?? array();
    }

    private function openai_chat( $prompt ) {
        $res = fvqa_http_with_retry( 'POST', 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->openai_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'    => 'gpt-4o-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => 'You answer strictly from provided transcript. Cite nothing external.'),
                    array('role' => 'user',   'content' => $prompt),
                ),
                'temperature' => 0.2,
            ) ),
            'timeout' => 60,
        ), 3 );

        if ( is_wp_error( $res ) ) { fvqa_log($res->get_error_message()); return ''; }
        if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
            fvqa_log('Chat error: ' . wp_remote_retrieve_body($res));
            return '';
        }
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        return $json['choices'][0]['message']['content'] ?? '';
    }
}
