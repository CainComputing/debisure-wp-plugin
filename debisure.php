<?php
/**
 * Plugin Name: Debisure Integration
 * Description: Integrates Debisure services with WordPress.
 * Version: 1.0.8
 * Requires PHP: 8.3
 * Author: Debisure
 * Author URI: https://www.debisure.com
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants safely
if ( ! defined( 'DEBISURE_PLUGIN_DIR' ) ) {
    define( 'DEBISURE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'DEBISURE_PLUGIN_URL' ) ) {
    define( 'DEBISURE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Register Activation Hook to create custom tracking table
 */
register_activation_hook( __FILE__, 'debisure_create_database_table' );
register_deactivation_hook( __FILE__, 'debisure_clear_cron_events' );
add_action( 'init', 'debisure_schedule_pending_mandate_cleanup' );
add_action( 'debisure_cleanup_pending_mandates', 'debisure_mark_stale_pending_mandates_incomplete' );

function debisure_create_database_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'debisure';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        account_reference varchar(100) NOT NULL,
        mandate_name varchar(255) NOT NULL DEFAULT '',
        is_individual tinyint(1) NOT NULL DEFAULT 1,
        first_name varchar(255) NOT NULL DEFAULT '',
        surname varchar(255) NOT NULL DEFAULT '',
        business_account_name varchar(255) NOT NULL DEFAULT '',
        business_account_reg_no varchar(100) NOT NULL DEFAULT '',
        business_account_reg_name varchar(255) NOT NULL DEFAULT '',
        mobile_no varchar(100) NOT NULL DEFAULT '',
        email_address varchar(255) NOT NULL DEFAULT '',
        building varchar(255) NOT NULL DEFAULT '',
        street varchar(255) NOT NULL DEFAULT '',
        city varchar(255) NOT NULL DEFAULT '',
        province varchar(255) NOT NULL DEFAULT '',
        postal_code varchar(50) NOT NULL DEFAULT '',
        debit_day varchar(50) NOT NULL DEFAULT '',
        amount decimal(10,2) NOT NULL,
        agreement_date varchar(50) NOT NULL DEFAULT '',
        mandate_reference varchar(100) NOT NULL DEFAULT '',
        reason_for_decline text NOT NULL,
        mandate_pdf text NOT NULL,
        status varchar(50) DEFAULT 'submitted' NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY account_reference (account_reference)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

function debisure_schedule_pending_mandate_cleanup() {
    if ( ! wp_next_scheduled( 'debisure_cleanup_pending_mandates' ) ) {
        wp_schedule_event( time(), 'hourly', 'debisure_cleanup_pending_mandates' );
    }
    if ( wp_next_scheduled( 'debisure_cron_status_check' ) ) {
        wp_clear_scheduled_hook( 'debisure_cron_status_check' );
    }
}

function debisure_clear_cron_events() {
    wp_clear_scheduled_hook( 'debisure_cleanup_pending_mandates' );
    wp_clear_scheduled_hook( 'debisure_cron_status_check' );
    delete_option( 'debisure_cron_run_history' );
}

function debisure_mark_stale_pending_mandates_incomplete() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'debisure';
    $result = $wpdb->query(
        "UPDATE $table_name
        SET status = 'incomplete'
        WHERE status = 'pending'
        AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
    );

    if ( false === $result ) {
        error_log( 'Debisure pending mandate cleanup failed: ' . $wpdb->last_error );
    }
}

// Load modular component files safely
if ( file_exists( DEBISURE_PLUGIN_DIR . 'includes/class-encryption.php' ) ) {
    require_once DEBISURE_PLUGIN_DIR . 'includes/class-encryption.php';
    require_once DEBISURE_PLUGIN_DIR . 'includes/class-admin.php';
    require_once DEBISURE_PLUGIN_DIR . 'includes/class-api.php';
    require_once DEBISURE_PLUGIN_DIR . 'includes/class-form.php';
    require_once DEBISURE_PLUGIN_DIR . 'includes/class-thankyou.php';
}

// =========================================================================
// Official Plugin Update Checker Configuration (PucFactory Model)
// =========================================================================

$debisure_puc_file = plugin_dir_path( __FILE__ ) . 'updater/plugin-update-checker.php';

if ( file_exists( $debisure_puc_file ) ) {
    require_once $debisure_puc_file;

    $myUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/CainComputing/debisure-wp-plugin/releases/latest/download/plugin.json',
        __FILE__,
        'debisure' // Must match your plugin folder slug in wp-content/plugins/debisure/
    );
}

// =========================================================================
// Cache Flush Diagnostic (Visit your site with ?debug_debisure_update=1)
// =========================================================================
add_action( 'admin_init', function() {
    if ( isset( $_GET['debug_debisure_update'] ) ) {
        global $wpdb;

        delete_site_transient( 'update_plugins' );
        delete_transient( 'update_plugins' );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_puc_%' OR option_name LIKE '_transient_puc_%'" );

        echo '<div style="background:#fff; color:#000; padding:20px; font-family:monospace; border:3px solid #000; z-index:99999; position:relative; max-width:600px; margin:40px auto;">';
        echo '<h2>✔ Success: All WordPress & PUC Cache Transients Wiped!</h2>';
        echo '<p>The update checker cache has been cleared. You can now return to the plugins page.</p>';
        echo '<p><a href="' . esc_url(admin_url('plugins.php')) . '" style="display:inline-block; background:#0073aa; color:#fff; padding:10px 15px; text-decoration:none; border-radius:3px;">Return to Plugins Panel</a></p>';
        echo '</div>';
        exit;
    }
});