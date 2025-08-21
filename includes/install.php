<?php
if ( ! defined('ABSPATH') ) { exit; }

function fvqa_install_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $tbl_chunks = $wpdb->prefix . 'fvqa_chunks';
    $tbl_logs   = $wpdb->prefix . 'fvqa_logs';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql_chunks = "CREATE TABLE $tbl_chunks (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        video_id VARCHAR(64) NOT NULL,
        start_sec INT UNSIGNED NOT NULL DEFAULT 0,
        end_sec   INT UNSIGNED NOT NULL DEFAULT 0,
        text LONGTEXT NOT NULL,
        embedding LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY video_id (video_id),
        KEY start_sec (start_sec),
        KEY end_sec (end_sec)
    ) $charset_collate;";
    dbDelta($sql_chunks);

    $sql_logs = "CREATE TABLE $tbl_logs (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        video_id VARCHAR(64) NOT NULL,
        question LONGTEXT NOT NULL,
        answer LONGTEXT NULL,
        citations LONGTEXT NULL,
        ip VARCHAR(64) NULL,
        ua VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY video_id (video_id),
        KEY created_at (created_at)
    ) $charset_collate;";
    dbDelta($sql_logs);
}

function fvqa_maybe_install_tables() {
    global $wpdb;
    $tbl = $wpdb->prefix . 'fvqa_chunks';
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $tbl) );
    if ( $exists !== $tbl ) {
        fvqa_install_tables();
    }
}
