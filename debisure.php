<?php
/**
 * Plugin Name: Debisure Integration
 * Description: Integrates Debisure services with WordPress.
 * Version: 0.0.1
 * Author: Debisure
 * Author URI: https://www.debisure.com
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'DEBISURE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEBISURE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register Activation Hook to create custom tracking table
 */
register_activation_hook( __FILE__, 'debisure_create_database_table' );

function debisure_create_database_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'debisure_mandates';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        account_reference varchar(100) NOT NULL,
        mandate_name varchar(255) NOT NULL,
        client_name varchar(255) NOT NULL,
        amount decimal(10,2) NOT NULL,
        status varchar(50) DEFAULT 'submitted' NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY account_reference (account_reference)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

// Load modular component files
require_once DEBISURE_PLUGIN_DIR . 'includes/class-encryption.php';
require_once DEBISURE_PLUGIN_DIR . 'includes/class-admin.php';
require_once DEBISURE_PLUGIN_DIR . 'includes/class-api.php';
require_once DEBISURE_PLUGIN_DIR . 'includes/class-form.php';
require_once DEBISURE_PLUGIN_DIR . 'includes/class-thankyou.php';

// =========================================================================
// Explicit GitHub Updater Configuration
// =========================================================================

// 1. Load the primary library initialization script
require_once plugin_dir_path( __FILE__ ) . 'updater/plugin-update-checker.php';

// 2. Import the explicit, production-grade GitHub VCS engine classes
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi;

try {
    // 3. Force create the specialized GitHub API handler instance
    $githubApiEngine = new GitHubApi(
        'https://github.com'
    );

    // 4. Manually bind the engine to your local plugin file and folder slug
    $myUpdateChecker = new PluginUpdateChecker(
        $githubApiEngine,
        __FILE__,
        'debisure' // Your true local folder directory name
    );

    // 5. Safely invoke the release asset pipeline
    $myUpdateChecker->getVcsApi()->enableReleaseAssets();

} catch (\Throwable $e) {
    // Graceful error fallback to prevent future dashboard lockout loops
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Debisure Updater Sync Error: ' . $e->getMessage() );
    }
}
