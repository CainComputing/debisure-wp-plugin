<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'debisure_admin_menus' );
function debisure_admin_menus() {
    $plugin_page = add_menu_page(
        'Debisure',
        'Debisure',
        'manage_options',
        'debisure-splash',
        'debisure_splash_page_html',
        'dashicons-shield',
        100
    );

    $settings_page = add_submenu_page(
        'debisure-splash',
        'Debisure Settings',
        'Settings',
        'manage_options',
        'debisure-settings',
        'debisure_settings_page_html'
    );

    add_action( 'load-' . $settings_page, 'debisure_add_help_tabs' );
}

function debisure_add_help_tabs() {
    $screen = get_current_screen();

    $screen->add_help_tab( array(
        'id'      => 'debisure_help_overview',
        'title'   => 'Overview',
        'content' => '<p><strong>Debisure Integration Settings:</strong> Enter your credentials. Entering whitespace (spaces) into any key field will clear it.</p>',
    ) );
}

add_action( 'admin_init', 'debisure_register_settings' );
function debisure_register_settings() {
    register_setting( 'debisure_settings_group', 'debisure_client_id', 'sanitize_text_field' );
    register_setting( 'debisure_settings_group', 'debisure_callback_page_id', 'absint' );
    
    register_setting( 'debisure_settings_group', 'debisure_api_token', 'debisure_validate_and_encrypt_token' );
    register_setting( 'debisure_settings_group', 'debisure_service_key', 'debisure_encrypt_service_key' );
    register_setting( 'debisure_settings_group', 'debisure_vendor_key', 'debisure_encrypt_vendor_key' );
}

/**
 * Encrypt and save Netcash Service Key. If spaces or empty, clears it.
 */
function debisure_encrypt_service_key( $new_key ) {
    $trimmed_key = trim( $new_key );
    $old_key     = get_option( 'debisure_service_key' );

    // If explicitly cleared with spaces or empty string (while leaving field blank means keep old)
    if ( $new_key !== '' && $trimmed_key === '' ) {
        add_settings_error( 'debisure_service_key', 'key_cleared', 'Netcash Service Key has been cleared.', 'updated' );
        return '';
    }

    if ( empty( $new_key ) && ! empty( $old_key ) ) {
        return $old_key;
    }

    if ( empty( $new_key ) ) {
        return '';
    }

    return debisure_encrypt_data( sanitize_text_field( $new_key ) );
}

/**
 * Encrypt and save Netcash Vendor Key. If spaces or empty, clears it.
 */
function debisure_encrypt_vendor_key( $new_key ) {
    $trimmed_key = trim( $new_key );
    $old_key     = get_option( 'debisure_vendor_key' );

    if ( $new_key !== '' && $trimmed_key === '' ) {
        add_settings_error( 'debisure_vendor_key', 'key_cleared', 'Netcash Vendor Key has been cleared.', 'updated' );
        return '';
    }

    if ( empty( $new_key ) && ! empty( $old_key ) ) {
        return $old_key;
    }

    if ( empty( $new_key ) ) {
        return '';
    }

    return debisure_encrypt_data( sanitize_text_field( $new_key ) );
}

/**
 * Validates credentials against API before saving. If invalid or cleared with spaces, clears the key.
 */
function debisure_validate_and_encrypt_token( $new_token ) {
    $trimmed_token = trim( $new_token );
    $old_token     = get_option( 'debisure_api_token' );
    
    // If user typed spaces to clear the key
    if ( $new_token !== '' && $trimmed_token === '' ) {
        add_settings_error(
            'debisure_api_token',
            'api_token_cleared',
            'Debisure API Key has been cleared.',
            'updated'
        );
        return '';
    }

    // Gather inputs from POST
    $client_id   = isset( $_POST['debisure_client_id'] ) ? sanitize_text_field( $_POST['debisure_client_id'] ) : get_option( 'debisure_client_id' );
    
    // Evaluate service/vendor keys (checking if user cleared them with spaces during this post)
    $posted_service = $_POST['debisure_service_key'] ?? '';
    $posted_vendor  = $_POST['debisure_vendor_key'] ?? '';
    
    $service_key = ( trim( $posted_service ) !== '' ) ? sanitize_text_field( $posted_service ) : ( $posted_service !== '' ? '' : debisure_get_service_key() );
    $vendor_key  = ( trim( $posted_vendor ) !== '' ) ? sanitize_text_field( $posted_vendor ) : ( $posted_vendor !== '' ? '' : debisure_get_vendor_key() );
    
    // Determine which token to test
    $token_to_test = ( $trimmed_token !== '' ) ? sanitize_text_field( $new_token ) : debisure_get_api_token();

    if ( empty( $token_to_test ) ) {
        return '';
    }

    if ( empty( $new_token ) && ! empty( $old_token ) ) {
        return $old_token;
    }

    $validation = debisure_validate_api_credentials( $client_id, $service_key, $vendor_key, $token_to_test );

    if ( is_wp_error( $validation ) ) {
        add_settings_error(
            'debisure_api_token',
            'api_validation_failed',
            'Credential Validation Failed: ' . $validation->get_error_message(),
            'error'
        );
        return '';
    }

    add_settings_error(
        'debisure_api_token',
        'api_validation_success',
        'API Credentials successfully verified and saved!',
        'success'
    );

    return debisure_encrypt_data( $token_to_test );
}

function debisure_splash_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Welcome to Debisure</h1>
        <p>Manage your integration settings or visit our main site below.</p>
        <div style="background: #fff; border: 1px solid #ccc; padding: 1px; margin-top: 15px;">
            <iframe src="https://debisure.com" width="100%" height="700px" frameborder="0"></iframe>
        </div>
    </div>
    <?php
}

function debisure_settings_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    $has_token       = get_option( 'debisure_api_token' ) ? true : false;
    $has_service_key = get_option( 'debisure_service_key' ) ? true : false;
    $has_vendor_key  = get_option( 'debisure_vendor_key' ) ? true : false;
    
    $selected_page_id = get_option( 'debisure_callback_page_id' );
    $callback_url     = $selected_page_id ? get_permalink( $selected_page_id ) : '';
    ?>
    <div class="wrap">
        <h1>Debisure Settings</h1>

        <?php settings_errors(); ?>

        <div class="notice notice-info inline" style="margin: 15px 0 20px 0; padding: 12px;">
            <h3>Credentials Verification & Callbacks</h3>
            <p>Type spaces into any key field and save to clear it. Valid keys are encrypted and tested against the <code>/api/v1/auth/validate</code> endpoint.</p>
        </div>

        <form action="options.php" method="post">
            <?php settings_fields( 'debisure_settings_group' ); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="debisure_callback_page_id">Callback Page</label></th>
                    <td>
                        <?php
                        wp_dropdown_pages( array(
                            'name'              => 'debisure_callback_page_id',
                            'selected'          => $selected_page_id,
                            'show_option_none'  => '-- Select a WordPress Page --',
                            'option_none_value' => '0',
                        ) );
                        ?>
                        <?php if ( $callback_url ) : ?>
                            <p class="description" style="margin-top: 5px;">
                                <strong>Active Callback URL:</strong> <code><?php echo esc_url( $callback_url ); ?></code>
                            </p>
                        <?php else : ?>
                            <p class="description"><em>Create a WordPress page first, then select it here. The permalink of that page will become your callback webhook URL.</em></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="debisure_client_id">Client ID</label></th>
                    <td>
                        <input type="text" id="debisure_client_id" name="debisure_client_id" value="<?php echo esc_attr( get_option('debisure_client_id') ); ?>" class="regular-text" autocomplete="off" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="debisure_api_token">Debisure API Key</label></th>
                    <td>
                        <input type="text" id="debisure_api_token" name="debisure_api_token" value="" placeholder="<?php echo $has_token ? '******** (Saved & Verified)' : ''; ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                        <p class="description">
                            <?php if ( $has_token ) : ?>
                                <strong>Credentials are verified and securely saved.</strong> Enter a new key to update, or type a space to clear.
                            <?php else : ?>
                                Enter your API key. It will be tested against the validation endpoint before saving.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="debisure_service_key">Netcash Service Key</label></th>
                    <td>
                        <input type="text" id="debisure_service_key" name="debisure_service_key" value="" placeholder="<?php echo $has_service_key ? '******** (Saved)' : ''; ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                        <p class="description">
                            <?php if ( $has_service_key ) : ?>
                                <strong>Securely saved.</strong> Enter a new key to update, or type a space to clear.
                            <?php else : ?>
                                Enter your Netcash Service Key.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="debisure_vendor_key">Netcash Vendor Key</label></th>
                    <td>
                        <input type="text" id="debisure_vendor_key" name="debisure_vendor_key" value="" placeholder="<?php echo $has_vendor_key ? '******** (Saved)' : ''; ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                        <p class="description">
                            <?php if ( $has_vendor_key ) : ?>
                                <strong>Securely saved.</strong> Enter a new key to update, or type a space to clear.
                            <?php else : ?>
                                Enter your Netcash Vendor Key.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}