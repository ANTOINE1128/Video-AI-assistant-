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

        $pick = function($rows, $want_en=true){
            foreach ($rows as $t){
                $type = strtolower($t['type'] ?? '');
                if ($type !== 'captions' && $type !== 'subtitles') continue;

                // Some Vimeo accounts report "language":"en-US" etc.
                $lang = strtolower($t['language'] ?? '');
                $is_en = ($lang === 'en') || (strpos($lang, 'en-') === 0);

                if ($want_en && !$is_en) continue;
                if (isset($t['active']) && !$t['active']) continue; // must be active
                if (!empty($t['link'])) return $t['link'];
            }
            return null;
        };

        $link = null;
        if (!empty($body['data']) && is_array($body['data'])){
            $link = $pick($body['data'], true);      // English first
            if (!$link) $link = $pick($body['data'], false); // else any
        }
        if (!$link) throw new \Exception('No suitable caption/subtitle track found on Vimeo.');

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

        $v = preg_replace("/\r\n|\r/","\n",$vtt);
        $parts = preg_split('/\n\n+/', trim($v));

        foreach($parts as $block){
            if (preg_match('/(\d{2}:\d{2}:\d{2}\.\d{3}|\d{2}:\d{2}\.\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}\.\d{3}|\d{2}:\d{2}\.\d{3})/',$block,$m)){
                $start = $this->time_to_sec($m[1]); $end=$this->time_to_sec($m[2]);
                $text  = trim( preg_replace('/^.*-->.*/m','',$block) );
                $text  = preg_replace('/<\/?[^>]*>/', '', $text);
                $text  = preg_replace('/\s+/',' ', $text);
                if ($text!==''){
                    $wpdb->insert($table,[
                        'video_id'  => $video_id,
                        'start_sec' => intval($start),
                        'end_sec'   => intval($end),
                        'text'      => $text,
                        'embedding' => null,
                    ], ['%s','%d','%d','%s','%s']);
                }
            }
        }
    }

    private function time_to_sec($s){
        $s=str_replace(',', '.', $s);
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})\.(\d{3})$/',$s,$m))
            return intval($m[1])*3600+intval($m[2])*60+intval($m[3]);
        if (preg_match('/^(\d{2}):(\d{2})\.(\d{3})$/',$s,$m))
            return intval($m[1])*60+intval($m[2]);
        return 0;
    }
}
