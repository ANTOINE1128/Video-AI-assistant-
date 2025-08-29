<?php
if ( ! defined('ABSPATH') ) { exit; }

class FVQA_Logger {
    public static function log($video_id, $user_id, $q, $a){
        global $wpdb; $table=$wpdb->prefix.'fvqa_logs';
        $wpdb->insert($table, [
            'video_id' => (string)$video_id,
            'user_id'  => intval($user_id),
            'question' => (string)$q,
            'answer'   => (string)$a,
        ], ['%s','%d','%s','%s']);
    }
}
