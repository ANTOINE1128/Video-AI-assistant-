<?php
if ( ! defined('ABSPATH') ) { exit; }

class FVQA_Install {
    public static function install(){ self::maybe_install(); }

    public static function maybe_install(){
        global $wpdb;
        $chunks = $wpdb->prefix.'fvqa_chunks';
        $logs   = $wpdb->prefix.'fvqa_logs';

        $have_chunks = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($chunks)) );
        $have_logs   = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($logs))   );

        if ($have_chunks === $chunks && $have_logs === $logs) return;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE $chunks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            video_id VARCHAR(20) NOT NULL,
            start_sec INT UNSIGNED NOT NULL,
            end_sec INT UNSIGNED NOT NULL,
            text LONGTEXT NOT NULL,
            embedding LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY vid_idx (video_id),
            KEY time_idx (video_id, start_sec)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE $logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            video_id VARCHAR(20) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            question LONGTEXT NOT NULL,
            answer LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY vidlog_idx (video_id, created_at)
        ) $charset_collate;";

        dbDelta($sql1);
        dbDelta($sql2);
    }
}
