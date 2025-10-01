<?php
if ( ! defined('ABSPATH') ) { exit; }

class FVQA_Indexer {
    private $openai_key;
    private $opt;

    public function __construct($openai_key, $opt){ $this->openai_key=$openai_key; $this->opt=$opt; }

    /** Ensure we have transcript chunks in DB for this video */
    public function ensure_indexed($video_id){
        global $wpdb; $table=$wpdb->prefix.'fvqa_chunks';
        $cnt = intval( $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE video_id=%s", $video_id) ) );
        if ($cnt>0) return;

        // 1) Ask Vimeo for texttracks
        $token = trim((string)$this->opt['vimeo_token']);
        if ($token==='') throw new \Exception('Missing Vimeo API token.');
        $url = "https://api.vimeo.com/videos/{$video_id}/texttracks";

        $resp = fvqa_http_with_retry('GET',$url,[
            'headers'=>[
                'Authorization' => 'Bearer '.$token,
                'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
            ],
            'timeout'=>30,
        ],1);
        if ( is_wp_error($resp) ) throw new \Exception('Vimeo error: '.$resp->get_error_message());
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode( wp_remote_retrieve_body($resp), true );
        if ($code>=400 || !is_array($body)) throw new \Exception('Vimeo error: '.$code);

        // 2) Prefer English tracks; accept both "captions" and "subtitles"
        $pick = function($rows, $want_en=true){
            foreach ($rows as $t){
                $type = strtolower($t['type'] ?? '');
                if ($type !== 'captions' && $type !== 'subtitles') continue;

                $lang = strtolower($t['language'] ?? '');
                $is_en = ($lang === 'en') || (strpos($lang, 'en-') === 0); // matches en, en-us, en-x-autogen, etc.

                if ($want_en && !$is_en) continue;
                if (!empty($t['link'])) return $t['link'];
            }
            return null;
        };

        $link = null;
        if (!empty($body['data']) && is_array($body['data'])){
            // English first (captions or subtitles)
            $link = $pick($body['data'], true);
            // fallback: any language (still captions or subtitles)
            if (!$link) $link = $pick($body['data'], false);
        }
        if (!$link) throw new \Exception('No suitable caption/subtitle track found on Vimeo.');

        // 3) Download VTT
        $resp2 = fvqa_http_with_retry('GET', $link, ['timeout'=>30], 1);
        if ( is_wp_error($resp2) ) throw new \Exception('VTT download error: '.$resp2->get_error_message());
        $code2= wp_remote_retrieve_response_code($resp2);
        $vtt  = (string) wp_remote_retrieve_body($resp2);
        if ($code2>=400 || stripos($vtt,'WEBVTT')===false) throw new \Exception('VTT not valid or expired.');

        $this->index_from_vtt($video_id, $vtt);
    }

    /** Parse VTT into rows */
    private function index_from_vtt($video_id, $vtt){
        global $wpdb; $table=$wpdb->prefix.'fvqa_chunks';

        // Normalize newlines
        $v = preg_replace("/\r\n|\r/","\n",$vtt);
        $parts = preg_split('/\n\n+/', trim($v));

        $rows=[];
        foreach($parts as $block){
            // Lines like: "00:00:05.000 --> 00:00:07.000" or "00:05.000 --> 00:07.000"
            if (preg_match('/(\d{2}:\d{2}:\d{2}\.\d{3}|\d{2}:\d{2}\.\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}\.\d{3}|\d{2}:\d{2}\.\d{3})/',$block,$m)){
                $start = $this->time_to_sec($m[1]); $end=$this->time_to_sec($m[2]);
                $text  = trim( preg_replace('/^.*-->.*/m','',$block) );
                $text  = preg_replace('/<\/?[^>]*>/', '', $text); // strip tags
                $text  = preg_replace('/\s+/',' ', $text);
                if ($text!==''){
                    $rows[] = ['video_id'=>$video_id,'start_sec'=>$start,'end_sec'=>$end,'text'=>$text];
                }
            }
        }

        // Insert in batches
        foreach($rows as $r){
            $wpdb->insert($table,[
                'video_id'=>$r['video_id'],
                'start_sec'=>intval($r['start_sec']),
                'end_sec'=>intval($r['end_sec']),
                'text'=>$r['text'],
                'embedding'=>null,
            ], ['%s','%d','%d','%s','%s']);
        }
    }

    private function time_to_sec($s){
        // 00:00:05.000 or 00:05.000
        $s=str_replace(',', '.', $s);
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})\.(\d{3})$/',$s,$m))
            return intval($m[1])*3600+intval($m[2])*60+intval($m[3]);
        if (preg_match('/^(\d{2}):(\d{2})\.(\d{3})$/',$s,$m))
            return intval($m[1])*60+intval($m[2]);
        return 0;
        }
}
