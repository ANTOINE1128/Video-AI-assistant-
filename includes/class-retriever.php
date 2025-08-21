
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FVQA_Retriever {
    private $openai_key;
    public function __construct( $openai_key ) { $this->openai_key = $openai_key; }

    private function embed_query( $q ) {
        $res = wp_remote_post( 'https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->openai_key,
                'Content-Type'  => 'application/json'
            ),
            'body' => json_encode( array(
                'model' => 'text-embedding-3-small',
                'input' => $q
            ) ),
            'timeout' => 30,
        ) );
        if ( is_wp_error( $res ) ) return array();
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        return isset( $json['data'][0]['embedding'] ) ? $json['data'][0]['embedding'] : array();
    }

    public function retrieve( $video_id, $question, $k = 5 ) {
        global $wpdb; $tbl = $wpdb->prefix . 'fvqa_chunks';
        $qemb = $this->embed_query( $question );
        if ( ! $qemb ) return array();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, start_sec, end_sec, text, embedding FROM $tbl WHERE video_id=%s", $video_id ) );
        $scored = array();
        foreach ( $rows as $r ) {
            $emb = json_decode( $r->embedding, true ) ?: array();
            $score = fvqa_cosine( $qemb, $emb );
            $scored[] = array(
                'id'        => (int) $r->id,
                'start_sec' => (int) $r->start_sec,
                'end_sec'   => (int) $r->end_sec,
                'text'      => $r->text,
                'score'     => $score
            );
        }
        usort( $scored, function( $a, $b ) { return $b['score'] <=> $a['score']; } );
        return array_slice( $scored, 0, $k );
    }

    public function answer( $video_id, $question, $topk = 5 ) {
        $chunks = $this->retrieve( $video_id, $question, $topk );
        $context = '';
        $cites = array();
        foreach ( $chunks as $c ) {
            $context .= "\n[" . fvqa_seconds_to_time( $c['start_sec'] ) . '-' . fvqa_seconds_to_time( $c['end_sec'] ) . "] " . $c['text'];
            $cites[] = array(
                'start' => $c['start_sec'],
                'end'   => $c['end_sec'],
                'text'  => mb_substr( $c['text'], 0, 140 ) . '…'
            );
        }

        $prompt = "You are a strict teaching assistant. Answer ONLY using the context from this video's transcript. If the answer is not contained, say: 'I couldn't find that in this video.' Keep it concise. Provide no external facts.\n\nContext:\n" . $context . "\n\nQuestion: " . $question;

        $res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->openai_key,
                'Content-Type'  => 'application/json'
            ),
            'body' => json_encode( array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array('role'=>'system','content'=>'You answer only from the provided context. Language: English.'),
                    array('role'=>'user','content'=>$prompt)
                ),
                'temperature' => 0.2
            ) ),
            'timeout' => 30,
        ) );
        if ( is_wp_error( $res ) ) return $res;
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) return new WP_Error( 'openai_chat', 'Answering failed: ' . wp_remote_retrieve_body( $res ) );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        $answer = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
        return array( 'answer' => $answer, 'citations' => $cites );
    }
}
