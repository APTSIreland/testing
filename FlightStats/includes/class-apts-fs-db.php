<?php
/**
 * Database layer for APTS FlightStats Advanced.
 *
 * Responsible for creating and upgrading the usage logging table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APTS_FS_DB {

    /**
     * Table name without prefix.
     *
     * @var string
     */
    public static $table_name = 'apts_fs_usage_log';

    /**
     * Return the full table name with WordPress prefix.
     *
     * @return string
     */
    public static function get_table() {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    /**
     * Install or upgrade the DB schema.
     *
     * This is called from the plugin activation hook in
     * apts-flightstats-advanced.php.
     */
    public static function install() {
        global $wpdb;

        $table   = self::get_table();
        $charset = $wpdb->get_charset_collate();

        // Basic usage log schema.
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            status_calls INT UNSIGNED NOT NULL DEFAULT 0,
            scheduled_calls INT UNSIGNED NOT NULL DEFAULT 0,
            total_calls INT UNSIGNED NOT NULL DEFAULT 0,
            cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            flights LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY ts (ts)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
