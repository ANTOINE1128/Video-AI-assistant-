<?php
if ( ! defined('ABSPATH') ) { exit; }

class FVQA_Install {

    /** Public: call on activation */
    public static function install(){
        self::maybe_install(true);
    }

    /**
     * Create/repair tables if missing.
     * @param bool $verbose  when true, return an array with status & errors instead of void.
     */
    public static function maybe_install($verbose = false){
        global $wpdb;

        $out = array(
            'prefix'          => $wpdb->prefix,
            'chunks_table'    => $wpdb->prefix.'fvqa_chunks',
            'logs_table'      => $wpdb->prefix.'fvqa_logs',
            'charset_collate' => '',
            'created'         => array(),
            'errors'          => array(),
        );

        $chunks = $out['chunks_table'];
        $logs   = $out['logs_table'];

        // Detect presence
        $have_chunks = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $chunks) );
        $have_logs   = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $logs) );

        if ( $have_chunks === $chunks && $have_logs === $logs ) {
            if ($verbose) { $out['created'] = array(); return $out; }
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $out['charset_collate'] = $charset_collate;

        $sql_chunks = "CREATE TABLE {$chunks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            video_id VARCHAR(20) NOT NULL,
            start_sec INT UNSIGNED NOT NULL,
            end_sec INT UNSIGNED NOT NULL,
            text LONGTEXT NOT NULL,
            embedding LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY vid_idx (video_id),
            KEY time_idx (video_id, start_sec)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            video_id VARCHAR(20) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            question LONGTEXT NOT NULL,
            answer LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY vidlog_idx (video_id, created_at)
        ) {$charset_collate};";

        $wpdb->suppress_errors(false);

        try { dbDelta($sql_chunks); dbDelta($sql_logs); }
        catch (\Throwable $e) { $out['errors'][] = 'dbDelta throw: '.$e->getMessage(); }

        // Recheck existence
        $have_chunks = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $chunks) );
        $have_logs   = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $logs) );

        // Fallback: direct CREATE TABLE IF NOT EXISTS
        if ( $have_chunks !== $chunks ) {
            $fallback = "CREATE TABLE IF NOT EXISTS {$chunks} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                video_id VARCHAR(20) NOT NULL,
                start_sec INT UNSIGNED NOT NULL,
                end_sec INT UNSIGNED NOT NULL,
                text LONGTEXT NOT NULL,
                embedding LONGTEXT NULL,
                PRIMARY KEY (id),
                KEY vid_idx (video_id),
                KEY time_idx (video_id, start_sec)
            ) {$charset_collate};";
            $r = $wpdb->query($fallback);
            if ($r === false) $out['errors'][] = 'Chunks fallback error: '.$wpdb->last_error;
            else $out['created'][] = 'fvqa_chunks';
        }

        if ( $have_logs !== $logs ) {
            $fallback = "CREATE TABLE IF NOT EXISTS {$logs} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                video_id VARCHAR(20) NOT NULL,
                user_id BIGINT UNSIGNED NULL,
                question LONGTEXT NOT NULL,
                answer LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY vidlog_idx (video_id, created_at)
            ) {$charset_collate};";
            $r = $wpdb->query($fallback);
            if ($r === false) $out['errors'][] = 'Logs fallback error: '.$wpdb->last_error;
            else $out['created'][] = 'fvqa_logs';
        }

        if ($verbose) {
            $out['final_chunks_ok'] = ($wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $chunks) ) === $chunks);
            $out['final_logs_ok']   = ($wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $logs) ) === $logs);
            $out['db_last_error']   = $wpdb->last_error;
            return $out;
        }
    }
}
