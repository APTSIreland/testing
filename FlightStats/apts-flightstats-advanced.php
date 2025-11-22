<?php
/*
Plugin Name: APTS FlightStats Advanced
Description: Advanced FlightStats integration for APTS with cost tracking, monthly budget control, and corporate-style dashboard.
Version: 3.0.0
Author: APTS
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Basic constants.
 */
define( 'APTS_FS_ADV_VERSION', '3.0.0' );
define( 'APTS_FS_ADV_PATH', plugin_dir_path( __FILE__ ) );
define( 'APTS_FS_ADV_URL', plugin_dir_url( __FILE__ ) );
define( 'APTS_FS_ADV_SLUG', 'apts_fs_adv' );

/**
 * Small helper to require files safely.
 *
 * @param string $relative_path Path relative to plugin root.
 */
function apts_fs_adv_require( $relative_path ) {
    $file = APTS_FS_ADV_PATH . ltrim( $relative_path, '/\\' );
    if ( file_exists( $file ) ) {
        require_once $file;
    }
}

/**
 * Load core classes.
 */
apts_fs_adv_require( 'includes/class-apts-fs-db.php' );
apts_fs_adv_require( 'includes/class-apts-fs-logger.php' );
apts_fs_adv_require( 'includes/class-apts-fs-api.php' );
apts_fs_adv_require( 'admin/class-apts-fs-admin.php' );

/**
 * Activation hook – creates DB table when APTS_FS_DB is available.
 */
function apts_fs_adv_activate() {
    if ( class_exists( 'APTS_FS_DB' ) ) {
        APTS_FS_DB::install();
    } else {
        $db_file = APTS_FS_ADV_PATH . 'includes/class-apts-fs-db.php';
        if ( file_exists( $db_file ) ) {
            require_once $db_file;
            if ( class_exists( 'APTS_FS_DB' ) ) {
                APTS_FS_DB::install();
            }
        }
    }
}
register_activation_hook( __FILE__, 'apts_fs_adv_activate' );

/**
 * Main plugin bootstrap class.
 */
class APTS_FlightStats_Advanced {

    public function __construct() {
        // Admin area menu.
        if ( is_admin() && class_exists( 'APTS_FS_Admin' ) ) {
            add_action( 'admin_menu', array( 'APTS_FS_Admin', 'register_menu' ) );
        }

        // Front-end.
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // AJAX for front-end flight fetch.
        add_action( 'wp_ajax_apts_fs_fetch_flights', array( $this, 'ajax_fetch_flights' ) );
        add_action( 'wp_ajax_nopriv_apts_fs_fetch_flights', array( $this, 'ajax_require_login' ) );
    }

    /**
     * Register shortcodes.
     */
    public function register_shortcodes() {
        add_shortcode( 'apts_fs_flights', array( $this, 'render_flights_shortcode' ) );
    }

    /**
     * Enqueue CSS/JS for front-end corporate airline-style table.
     */
    public function enqueue_frontend_assets() {
        if ( ! is_user_logged_in() ) {
            // Front-end tool is internal only.
            return;
        }

        wp_enqueue_style(
            'apts-fs-frontend',
            APTS_FS_ADV_URL . 'assets/css/frontend.css',
            array(),
            APTS_FS_ADV_VERSION
        );

        wp_enqueue_script(
            'apts-fs-frontend',
            APTS_FS_ADV_URL . 'assets/js/frontend.js',
            array( 'jquery' ),
            APTS_FS_ADV_VERSION,
            true
        );

        wp_localize_script(
            'apts-fs-frontend',
            'APTS_FS_FRONTEND',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'apts_fs_adv_nonce' ),
            )
        );
    }

    /**
     * Shortcode callback for [apts_fs_flights].
     */
    public function render_flights_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>You must be logged in to view flight status.</p>';
        }

        ob_start();

        $template = APTS_FS_ADV_PATH . 'templates/front-table.php';
        if ( file_exists( $template ) ) {
            include $template;
        } else {
            echo '<p>APTS FlightStats front-end template is not yet installed.</p>';
        }

        return ob_get_clean();
    }

    /**
     * AJAX: require login for unauthenticated users.
     */
    public function ajax_require_login() {
        wp_send_json_error(
            array(
                'message' => 'You must be logged in to use this tool.',
            )
        );
    }

    /**
     * AJAX: fetch flight statuses (currently via placeholder method).
     *
     * POST parameters:
     *  - nonce   : security nonce
     *  - flights : raw textarea string (comma/newline separated)
     */
    public function ajax_fetch_flights() {
        // Security check.
        check_ajax_referer( 'apts_fs_adv_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error(
                array(
                    'message' => 'You must be logged in to use this tool.',
                )
            );
        }

        if ( ! class_exists( 'APTS_FS_API' ) ) {
            wp_send_json_error(
                array(
                    'message' => 'FlightStats API helper not available.',
                )
            );
        }

        $raw = isset( $_POST['flights'] ) ? wp_unslash( $_POST['flights'] ) : '';
        $raw = trim( $raw );

        if ( '' === $raw ) {
            wp_send_json_error(
                array(
                    'message' => 'Please enter at least one flight number.',
                )
            );
        }

        // Split by comma and/or newline.
        $parts = preg_split( '/[\r\n,]+/', $raw );
        $flights = array();

        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( '' !== $part ) {
                $flights[] = $part;
            }
        }

        if ( empty( $flights ) ) {
            wp_send_json_error(
                array(
                    'message' => 'Please enter at least one valid flight number.',
                )
            );
        }

        // In a later step, we will:
        // - Use can_make_calls() to enforce budget.
        // - Call real FlightStats endpoints.
        // For now, use the placeholder that returns "🛬 Check DAA" rows.
        $data = APTS_FS_API::fetch_flight_statuses_live( $flights );

        wp_send_json_success( $data );
    }
}

/**
 * Bootstrap plugin.
 */
function apts_fs_adv_bootstrap() {
    new APTS_FlightStats_Advanced();
}
add_action( 'plugins_loaded', 'apts_fs_adv_bootstrap' );
