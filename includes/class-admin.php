<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'debisure_admin_menus' );
add_action( 'admin_post_debisure_resend_mandate', 'debisure_handle_resend_mandate' );
add_action( 'admin_post_debisure_export_mandates', 'debisure_handle_export_mandates' );
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

    $mandates_page = false;
    if ( debisure_has_activation_credentials() ) {
        $mandates_page = add_submenu_page(
            'debisure-splash',
            'Debisure Mandates',
            'Mandates',
            'manage_options',
            'debisure-mandates',
            'debisure_mandates_page_html'
        );
    }

    $settings_page = add_submenu_page(
        'debisure-splash',
        'Debisure Settings',
        'Settings',
        'manage_options',
        'debisure-settings',
        'debisure_settings_page_html'
    );
    add_action( 'load-' . $settings_page, 'debisure_add_help_tabs' );
    add_action( 'load-' . $settings_page, 'debisure_add_help_tabs' );
    add_action(
        'admin_enqueue_scripts',
        function ( $hook_suffix ) use ( $settings_page, $mandates_page ) {
            if ( ! in_array( $hook_suffix, array( $settings_page, $mandates_page ), true ) ) {
                return;
            }

            $stylesheet = DEBISURE_PLUGIN_DIR . 'assets/css/admin.css';
            wp_enqueue_style(
                'debisure-admin',
                DEBISURE_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                file_exists( $stylesheet ) ? (string) filemtime( $stylesheet ) : '1.0.8'
            );
        }
    );
}

function debisure_add_help_tabs() {
    $screen = get_current_screen();

    $screen->add_help_tab( array(
        'id'      => 'debisure_help_overview',
        'title'   => 'Overview',
        'content' => '<p>Please enter your Client ID and API Key to activate this plugin.</p>',
    ) );
}

add_action( 'admin_init', 'debisure_register_settings' );
function debisure_register_settings() {
    register_setting( 'debisure_settings_group', 'debisure_client_id', 'sanitize_text_field' );
    register_setting(
        'debisure_settings_group',
        'debisure_reference_prefix',
        array(
            'sanitize_callback' => 'debisure_sanitize_reference_prefix',
            'default'           => 'WEB',
        )
    );
    
    register_setting( 'debisure_settings_group', 'debisure_api_token', 'debisure_validate_and_encrypt_token' );
    register_setting( 'debisure_settings_group', 'debisure_service_key', 'debisure_encrypt_service_key' );
    register_setting( 'debisure_settings_group', 'debisure_vendor_key', 'debisure_encrypt_vendor_key' );
    register_setting(
        'debisure_form_builder_group',
        'debisure_form_fields',
        array(
            'sanitize_callback' => 'debisure_sanitize_form_fields',
            'default'           => debisure_default_form_fields(),
        )
    );
    register_setting(
        'debisure_form_builder_group',
        'debisure_custom_form_fields',
        array(
            'sanitize_callback' => 'debisure_sanitize_custom_form_fields',
            'default'           => debisure_default_custom_form_fields(),
        )
    );
    register_setting(
        'debisure_form_builder_group',
        'debisure_form_amounts',
        array(
            'sanitize_callback' => 'debisure_sanitize_form_amounts',
            'default'           => debisure_default_form_amounts(),
        )
    );
    register_setting(
        'debisure_form_builder_group',
        'debisure_form_debit_days',
        array(
            'sanitize_callback' => 'debisure_sanitize_form_debit_days',
            'default'           => array_keys( debisure_default_form_debit_days() ),
        )
    );
    register_setting(
        'debisure_form_builder_group',
        'debisure_recaptcha_settings',
        array(
            'sanitize_callback' => 'debisure_sanitize_recaptcha_settings',
            'default'           => array( 'site_key' => '', 'secret_key' => '' ),
        )
    );
}

function debisure_sanitize_recaptcha_settings( $input ) {
    $current = get_option( 'debisure_recaptcha_settings', array() );
    $current = is_array( $current ) ? $current : array();
    $current_secret = is_string( $current['secret_key'] ?? null ) ? $current['secret_key'] : '';
    $current_site_key = is_string( $current['site_key'] ?? null ) ? $current['site_key'] : '';

    if ( ! is_array( $input ) ) {
        return array( 'site_key' => $current_site_key, 'secret_key' => $current_secret );
    }

    if ( isset( $input['clear_site'] ) && is_scalar( $input['clear_site'] ) && '1' === (string) $input['clear_site'] ) {
        $site_key = '';
    } elseif ( isset( $input['site_key'] ) && is_scalar( $input['site_key'] ) && '' !== trim( (string) $input['site_key'] ) ) {
        $site_key = sanitize_text_field( trim( (string) $input['site_key'] ) );
    } else {
        $site_key = $current_site_key;
    }

    if ( isset( $input['clear_secret'] ) && is_scalar( $input['clear_secret'] ) && '1' === (string) $input['clear_secret'] ) {
        $secret_key = '';
    } elseif ( isset( $input['secret_key'] ) && is_string( $input['secret_key'] ) && '' !== trim( $input['secret_key'] ) ) {
        $secret_key = debisure_encrypt_data( sanitize_text_field( trim( $input['secret_key'] ) ) );
    } else {
        $secret_key = $current_secret;
    }

    return array(
        'site_key'   => $site_key,
        'secret_key' => $secret_key,
    );
}

function debisure_sanitize_form_debit_days( $input ) {
    $available = debisure_default_form_debit_days();
    $selected = array();
    if ( is_array( $input ) ) {
        foreach ( $available as $value => $label ) {
            $is_selected_list_value = in_array( $value, $input, true );
            $is_selected_legacy_value = isset( $input[ $value ] ) && '1' === (string) $input[ $value ];
            if ( $is_selected_list_value || $is_selected_legacy_value ) {
                $selected[] = $value;
            }
        }
    }

    if ( empty( $selected ) ) {
        add_settings_error( 'debisure_form_debit_days', 'debisure_debit_day_required', 'Select at least one Debit Day option.', 'error' );
        return array_keys( debisure_get_form_debit_days() );
    }

    return $selected;
}

function debisure_sanitize_form_amounts( $input ) {
    $current = debisure_get_form_amounts();
    if ( ! is_array( $input ) || ! isset( $input['options'], $input['custom_default'] ) || ! is_array( $input['options'] ) || 3 !== count( $input['options'] ) ) {
        add_settings_error( 'debisure_form_amounts', 'invalid_amount_options', 'Enter three different preset amounts and a custom default of at least R1.00.', 'error' );
        return $current;
    }

    $amounts = array();
    foreach ( array_values( $input['options'] ) as $amount ) {
        if ( ! is_scalar( $amount ) || ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount < 1 ) {
            add_settings_error( 'debisure_form_amounts', 'invalid_amount_options', 'Each preset amount must be a number of at least R1.00.', 'error' );
            return $current;
        }
        $amounts[] = number_format( (float) $amount, 2, '.', '' );
    }

    if ( count( array_unique( $amounts ) ) !== 3 ) {
        add_settings_error( 'debisure_form_amounts', 'duplicate_amount_options', 'The three preset amounts must be different.', 'error' );
        return $current;
    }

    $custom_default = $input['custom_default'];
    if ( ! is_scalar( $custom_default ) || ! is_numeric( $custom_default ) || ! is_finite( (float) $custom_default ) || (float) $custom_default < 1 ) {
        add_settings_error( 'debisure_form_amounts', 'invalid_custom_amount', 'The default custom amount must be a number of at least R1.00.', 'error' );
        return $current;
    }

    return array(
        'options'        => $amounts,
        'custom_default' => number_format( (float) $custom_default, 2, '.', '' ),
    );
}

function debisure_sanitize_reference_prefix( $prefix ) {
    $prefix = trim( sanitize_text_field( $prefix ) );
    return '' !== $prefix ? $prefix : 'WEB';
}

function debisure_encrypt_service_key( $new_key ) {
    $trimmed_key = trim( $new_key );
    $old_key     = get_option( 'debisure_service_key' );

    if ( ! empty( $_POST['debisure_clear_service_key'] ) || ( $new_key !== '' && $trimmed_key === '' ) ) {
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

function debisure_encrypt_vendor_key( $new_key ) {
    $trimmed_key = trim( $new_key );
    $old_key     = get_option( 'debisure_vendor_key' );

    if ( ! empty( $_POST['debisure_clear_vendor_key'] ) || ( $new_key !== '' && $trimmed_key === '' ) ) {
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

function debisure_validate_and_encrypt_token( $new_token ) {
    $trimmed_token = trim( $new_token );
    $old_token     = get_option( 'debisure_api_token' );
    
    if ( ! empty( $_POST['debisure_clear_api_token'] ) || ( $new_token !== '' && $trimmed_token === '' ) ) {
        add_settings_error(
            'debisure_api_token',
            'api_token_cleared',
            'Debisure API Key has been cleared.',
            'updated'
        );
        return '';
    }

    $client_id   = isset( $_POST['debisure_client_id'] ) ? sanitize_text_field( $_POST['debisure_client_id'] ) : get_option( 'debisure_client_id' );
    
    $posted_service = $_POST['debisure_service_key'] ?? '';
    $posted_vendor  = $_POST['debisure_vendor_key'] ?? '';
    
    $service_key = ! empty( $_POST['debisure_clear_service_key'] )
        ? ''
        : ( ( trim( $posted_service ) !== '' ) ? sanitize_text_field( $posted_service ) : ( $posted_service !== '' ? '' : debisure_get_service_key() ) );
    $vendor_key = ! empty( $_POST['debisure_clear_vendor_key'] )
        ? ''
        : ( ( trim( $posted_vendor ) !== '' ) ? sanitize_text_field( $posted_vendor ) : ( $posted_vendor !== '' ? '' : debisure_get_vendor_key() ) );
    
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

function debisure_health_endpoint_is_online( $url ) {
    $response = wp_remote_get(
        $url,
        array(
            'timeout'     => 5,
            'redirection' => 2,
            'headers'     => array( 'Accept' => 'application/json' ),
        )
    );

    return ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
}

function debisure_build_mandate_resubmission_payload( $mandate, $client_id ) {
    $is_individual = ! empty( $mandate->is_individual );
    $data = array(
        'accountReference' => $mandate->account_reference,
        'isIndividual'     => $is_individual,
        'mandateName'       => ! empty( $mandate->mandate_name )
            ? $mandate->mandate_name
            : ( $is_individual
                ? trim( $mandate->first_name . ' ' . $mandate->surname )
                : $mandate->business_account_name ),
        'clientId'          => $client_id,
    );

    $stored_fields = array(
        'firstName'              => 'first_name',
        'surname'                => 'surname',
        'businessAccountName'    => 'business_account_name',
        'businessAccountRegNo'   => 'business_account_reg_no',
        'businessAccountRegName' => 'business_account_reg_name',
        'mobileNo'               => 'mobile_no',
        'emailAddress'           => 'email_address',
        'building'               => 'building',
        'street'                 => 'street',
        'city'                   => 'city',
        'province'               => 'province',
        'postalCode'             => 'postal_code',
        'debitDay'               => 'debit_day',
    );

    foreach ( $stored_fields as $payload_key => $column ) {
        $is_business_field = 0 === strpos( $payload_key, 'businessAccount' );
        if ( ! $is_business_field || ! $is_individual ) {
            $data[ $payload_key ] = (string) ( $mandate->$column ?? '' );
        }
    }

    $custom_settings = debisure_get_custom_form_fields();
    foreach ( array( 'custom1', 'custom2', 'custom3', 'custom4', 'custom5' ) as $custom_field ) {
        $custom_value = (string) ( $mandate->$custom_field ?? '' );
        $data[ $custom_field ] = 'checkbox' === $custom_settings[ $custom_field ]['type']
            ? in_array( strtolower( $custom_value ), array( '1', 'true', 'yes', 'on' ), true )
            : $custom_value;
    }

    if (
        ! $is_individual
        && '' === trim( $data['businessAccountRegName'] )
        && '' !== trim( $data['businessAccountName'] )
    ) {
        $data['businessAccountRegName'] = $data['businessAccountName'];
    }

    $data['mandateAmount'] = (float) $mandate->amount;

    return $data;
}

function debisure_handle_resend_mandate() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You are not allowed to resubmit mandates.' );
    }

    $mandate_id = isset( $_POST['mandate_id'] ) && is_scalar( $_POST['mandate_id'] )
        ? absint( $_POST['mandate_id'] )
        : 0;

    $redirect_args = array(
        'page'        => 'debisure-mandates',
        'resend_done' => '1',
    );
    foreach ( array( 'paged', 'per_page', 'debisure_mandate_search' ) as $key ) {
        if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
            $redirect_args[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
        }
    }

    $nonce = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] )
        ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) )
        : '';
    if ( ! wp_verify_nonce( $nonce, 'debisure_resend_mandate_' . $mandate_id ) ) {
        $redirect_args['resend_result'] = 'nonce';
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'debisure';
    $mandate = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $mandate_id
    ) );

    if ( ! $mandate || '' !== $wpdb->last_error ) {
        error_log( 'Debisure mandate resubmission failed: mandate record was not found (ID ' . $mandate_id . ').' );
        $redirect_args['resend_result'] = 'error';
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    if ( ! in_array( strtolower( $mandate->status ), array( 'pending', 'success', 'failed' ), true ) ) {
        error_log( 'Debisure mandate resubmission rejected for unsupported status "' . $mandate->status . '" (ID ' . $mandate_id . ').' );
        $redirect_args['resend_result'] = 'error';
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    $api_token = debisure_get_api_token();
    $service_key = debisure_get_service_key();
    $vendor_key = debisure_get_vendor_key();
    $client_id = get_option( 'debisure_client_id' );
    if ( empty( $api_token ) || empty( $service_key ) || empty( $vendor_key ) || empty( $client_id ) ) {
        error_log( 'Debisure mandate resubmission failed: required credentials or client configuration is missing.' );
        $redirect_args['resend_result'] = 'error';
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    $data = debisure_build_mandate_resubmission_payload(
        $mandate,
        $client_id
    );
    if ( '' !== $data['province'] ) {
        $province_code = debisure_normalize_province_code( $data['province'] );
        if ( null === $province_code ) {
            error_log( 'Debisure mandate resubmission failed: stored province is not recognized (ID ' . $mandate_id . ').' );
            $redirect_args['resend_result'] = 'error';
            wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
            exit;
        }
        $data['province'] = (string) $province_code;
    }

    $headers = array(
        'Content-Type' => 'application/json',
        'accept'       => 'text/plain',
        'X-Api-Key'    => $api_token,
        'X-Service-Key'=> $service_key,
        'X-Vendor-Key' => $vendor_key,
    );
    $response = wp_remote_post(
        'https://api.debisure.com/api/v1/mandates',
        array(
            'headers' => $headers,
            'body'    => wp_json_encode( $data ),
            'timeout' => 45,
        )
    );

    if ( is_wp_error( $response ) ) {
        error_log( 'Debisure mandate resubmission connection failed: ' . $response->get_error_message() );
        $redirect_args['resend_result'] = 'error';
    } else {
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code >= 200 && $response_code < 300 ) {
            $redirect_args['resend_result'] = 'success';
            $redirect_args['mandate_id'] = $mandate_id;
        } else {
            error_log(
                'Debisure mandate resubmission API error (HTTP ' . $response_code . '): '
                . wp_remote_retrieve_body( $response )
            );
            $redirect_args['resend_result'] = 'error';
        }
    }

    wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
    exit;
}

function debisure_handle_export_mandates() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You are not allowed to export mandates.' );
    }
    check_admin_referer( 'debisure_export_mandates' );

    global $wpdb;
    $table_name = $wpdb->prefix . 'debisure';
    $search = isset( $_POST['debisure_mandate_search'] ) && is_scalar( $_POST['debisure_mandate_search'] )
        ? sanitize_text_field( wp_unslash( $_POST['debisure_mandate_search'] ) )
        : '';

    if ( '' !== $search ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $mandates = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name
            WHERE account_reference LIKE %s
                OR first_name LIKE %s
                OR surname LIKE %s
                OR email_address LIKE %s
                OR mandate_name LIKE %s
                OR business_account_name LIKE %s
            ORDER BY created_at DESC",
            $like,
            $like,
            $like,
            $like,
            $like,
            $like
        ), ARRAY_A );
    } else {
        $mandates = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    if ( null === $mandates || '' !== $wpdb->last_error ) {
        error_log( 'Debisure mandate CSV export failed: ' . $wpdb->last_error );
        wp_die( 'Could not export mandates. Check the site error log for details.' );
    }

    $columns = array(
        'id'                       => 'ID',
        'created_at'               => 'Created',
        'account_reference'        => 'Reference',
        'mandate_name'             => 'Mandate Name',
        'is_individual'            => 'Individual',
        'first_name'               => 'First Name',
        'surname'                  => 'Surname',
        'business_account_name'    => 'Business Name',
        'business_account_reg_no'  => 'Business Registration Number',
        'business_account_reg_name'=> 'Business Registered Name',
        'mobile_no'                => 'Mobile Number',
        'email_address'            => 'Email',
        'building'                 => 'Building',
        'street'                   => 'Street',
        'city'                     => 'City',
        'province'                 => 'Province',
        'postal_code'              => 'Postal Code',
        'debit_day'                => 'Debit Day',
        'custom1'                  => 'Custom 1',
        'custom2'                  => 'Custom 2',
        'custom3'                  => 'Custom 3',
        'custom4'                  => 'Custom 4',
        'custom5'                  => 'Custom 5',
        'amount'                   => 'Amount',
        'agreement_date'           => 'Agreement Date',
        'mandate_reference'        => 'Mandate Reference',
        'reason_for_decline'       => 'Reason for Decline',
        'mandate_pdf'              => 'Mandate PDF',
        'status'                   => 'Status',
    );

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="debisure-mandates-' . gmdate( 'Y-m-d' ) . '.csv"' );

    $output = fopen( 'php://output', 'w' );
    if ( false === $output ) {
        error_log( 'Debisure mandate CSV export failed: could not open output stream.' );
        wp_die( 'Could not export mandates.' );
    }

    fputcsv( $output, array_values( $columns ) );
    foreach ( $mandates as $mandate ) {
        $row = array();
        foreach ( $columns as $column => $label ) {
            $value = (string) ( $mandate[ $column ] ?? '' );
            if ( preg_match( '/\A[\s\x00-\x1F]*[=+\-@]/', $value ) ) {
                $value = "'" . $value;
            }
            $row[] = $value;
        }
        fputcsv( $output, $row );
    }

    fclose( $output );
    exit;
}

function debisure_mandates_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! debisure_has_activation_credentials() ) {
        echo '<div class="wrap"><div class="notice notice-warning"><p>Please activate the plugin with your API key to use these features.</p></div></div>';
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'debisure';
    $allowed_page_sizes = array( 20, 50, 100 );
    $requested_page_size = isset( $_GET['per_page'] ) && is_scalar( $_GET['per_page'] )
        ? absint( $_GET['per_page'] )
        : 20;
    $per_page = in_array( $requested_page_size, $allowed_page_sizes, true ) ? $requested_page_size : 20;
    $current_page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] )
        ? max( 1, absint( $_GET['paged'] ) )
        : 1;
    $offset = ( $current_page - 1 ) * $per_page;
    $search = isset( $_GET['debisure_mandate_search'] ) && is_scalar( $_GET['debisure_mandate_search'] )
        ? sanitize_text_field( wp_unslash( $_GET['debisure_mandate_search'] ) )
        : '';
    $form_fields = debisure_get_form_fields();
    $custom_form_fields = debisure_get_custom_form_fields();
    $visible_custom_fields = array();
    foreach ( array( 'custom1', 'custom2', 'custom3', 'custom4', 'custom5' ) as $custom_field ) {
        if ( ! empty( $form_fields[ $custom_field ]['enabled'] ) ) {
            $visible_custom_fields[ $custom_field ] = $custom_form_fields[ $custom_field ];
        }
    }
    $custom_columns_sql = $visible_custom_fields
        ? ', ' . implode( ', ', array_keys( $visible_custom_fields ) )
        : '';

    if ( '' !== $search ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
            FROM $table_name
            WHERE account_reference LIKE %s
                OR first_name LIKE %s
                OR surname LIKE %s
                OR email_address LIKE %s
                OR mandate_name LIKE %s
                OR business_account_name LIKE %s",
            $like,
            $like,
            $like,
            $like,
            $like,
            $like
        ) );
        $mandates = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, account_reference, mandate_name, is_individual, first_name, surname, business_account_name, email_address, amount, debit_day, status, created_at$custom_columns_sql
            FROM $table_name
            WHERE account_reference LIKE %s
                OR first_name LIKE %s
                OR surname LIKE %s
                OR email_address LIKE %s
                OR mandate_name LIKE %s
                OR business_account_name LIKE %s
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $per_page,
            $offset
        ) );
    } else {
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
        $mandates = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, account_reference, mandate_name, is_individual, first_name, surname, business_account_name, email_address, amount, debit_day, status, created_at$custom_columns_sql
            FROM $table_name
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );
    }

    if ( null === $total || null === $mandates || '' !== $wpdb->last_error ) {
        error_log( 'Debisure mandates page query failed: ' . $wpdb->last_error );
        ?>
        <div class="wrap">
            <h1>Mandates</h1>
            <div class="notice notice-error"><p>Could not load mandates from the database. Check the site error log for details.</p></div>
        </div>
        <?php
        return;
    }

    $total = (int) $total;
    $total_pages = (int) ceil( $total / $per_page );
    ?>
    <div class="wrap">
        <h1>Mandates</h1>
        <p>Review submitted debit orders and their latest status.</p>

        <?php if ( isset( $_GET['resend_result'], $_GET['resend_done'] ) && is_scalar( $_GET['resend_result'] ) && is_scalar( $_GET['resend_done'] ) && '1' === $_GET['resend_done'] ) : ?>
            <?php if ( 'success' === sanitize_key( wp_unslash( $_GET['resend_result'] ) ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Mandate request submitted again to Debisure.</p></div>
            <?php elseif ( 'nonce' === sanitize_key( wp_unslash( $_GET['resend_result'] ) ) ) : ?>
                <div class="notice notice-error is-dismissible"><p>The security token expired or was invalid. Refresh the Mandates page and try again.</p></div>
            <?php else : ?>
                <div class="notice notice-error is-dismissible"><p>The mandate request could not be submitted. Check the site error log for details.</p></div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="debisure-mandates" />
            <p class="search-box">
                <label class="screen-reader-text" for="debisure-mandate-search">Search mandates:</label>
                <input type="search" id="debisure-mandate-search" name="debisure_mandate_search" value="<?php echo esc_attr( $search ); ?>" />
                <label for="debisure-mandate-page-size">Show</label>
                <select id="debisure-mandate-page-size" name="per_page">
                    <?php foreach ( $allowed_page_sizes as $page_size ) : ?>
                        <option value="<?php echo esc_attr( (string) $page_size ); ?>" <?php selected( $per_page, $page_size ); ?>><?php echo esc_html( (string) $page_size ); ?></option>
                    <?php endforeach; ?>
                </select>
                <span>per page</span>
                <?php submit_button( 'Search Mandates', '', '', false ); ?>
            </p>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0 0 1em;">
            <input type="hidden" name="action" value="debisure_export_mandates" />
            <input type="hidden" name="debisure_mandate_search" value="<?php echo esc_attr( $search ); ?>" />
            <?php wp_nonce_field( 'debisure_export_mandates' ); ?>
            <?php submit_button( 'Download CSV', 'secondary', 'submit', false ); ?>
        </form>

        <p><?php echo esc_html( number_format_i18n( $total ) ); ?> mandate<?php echo 1 === $total ? '' : 's'; ?></p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col">Created</th>
                    <th scope="col">Reference</th>
                    <th scope="col">Mandate Name</th>
                    <th scope="col">Business</th>
                    <th scope="col">First Name</th>
                    <th scope="col">Surname</th>
                    <th scope="col">Email</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Status</th>
                    <th scope="col">Action</th>
                    <?php foreach ( $visible_custom_fields as $custom_field => $custom_definition ) : ?>
                        <th scope="col" title="<?php echo esc_attr( $custom_definition['label'] ); ?>"><?php echo esc_html( 'Custom ' . substr( $custom_field, 6 ) ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $mandates ) ) : ?>
                    <tr><td colspan="<?php echo esc_attr( (string) ( 10 + count( $visible_custom_fields ) ) ); ?>"><?php echo '' === $search ? 'No mandates have been submitted yet.' : 'No mandates match your search.'; ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $mandates as $mandate ) : ?>
                        <?php
                        $account_holder = ! empty( $mandate->mandate_name )
                            ? $mandate->mandate_name
                            : ( ! empty( $mandate->is_individual )
                                ? trim( $mandate->first_name . ' ' . $mandate->surname )
                                : $mandate->business_account_name );
                        $status_class = sanitize_html_class( strtolower( $mandate->status ) );
                        $action_label = 'pending' === strtolower( $mandate->status )
                            ? 'Resend'
                            : ( in_array( strtolower( $mandate->status ), array( 'success', 'failed' ), true ) ? 'Update' : '' );
                        ?>
                        <tr>
                            <td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mandate->created_at ) ); ?></td>
                            <td><code><?php echo esc_html( $mandate->account_reference ); ?></code></td>
                            <td><?php echo esc_html( '' !== $account_holder ? $account_holder : 'Not provided' ); ?></td>
                            <td><?php echo esc_html( ! empty( $mandate->is_individual ) ? 'No' : 'Yes' ); ?></td>
                            <td><?php echo esc_html( $mandate->first_name ); ?></td>
                            <td><?php echo esc_html( $mandate->surname ); ?></td>
                            <td><?php echo esc_html( $mandate->email_address ); ?></td>
                            <td><?php echo esc_html( 'R ' . number_format_i18n( (float) $mandate->amount, 2 ) ); ?></td>
                            <td><span class="debisure-mandate-status debisure-mandate-status--<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( $mandate->status ) ); ?></span></td>
                            <td>
                                <?php if ( '' !== $action_label ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="debisure_resend_mandate" />
                                        <input type="hidden" name="mandate_id" value="<?php echo esc_attr( (string) $mandate->id ); ?>" />
                                        <input type="hidden" name="paged" value="<?php echo esc_attr( (string) $current_page ); ?>" />
                                        <input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>" />
                                        <input type="hidden" name="debisure_mandate_search" value="<?php echo esc_attr( $search ); ?>" />
                                        <?php wp_nonce_field( 'debisure_resend_mandate_' . $mandate->id ); ?>
                                        <button type="submit" class="button button-secondary debisure-resend-button" data-mandate-id="<?php echo esc_attr( (string) $mandate->id ); ?>"><?php echo esc_html( $action_label ); ?></button>
                                    </form>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <?php foreach ( $visible_custom_fields as $custom_field => $custom_definition ) : ?>
                                <?php
                                $custom_value = (string) ( $mandate->$custom_field ?? '' );
                                if ( 'checkbox' === $custom_definition['type'] && '' !== $custom_value ) {
                                    $custom_value = in_array( strtolower( $custom_value ), array( '1', 'true', 'yes', 'on' ), true ) ? 'Yes' : 'No';
                                }
                                ?>
                                <td><?php echo esc_html( $custom_value ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post( paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $total_pages,
                        'add_args'  => array_filter(
                            array(
                                'debisure_mandate_search' => '' !== $search ? $search : false,
                                'per_page'               => $per_page,
                            )
                        ),
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ) ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script>
        (function () {
            var buttons = document.querySelectorAll('.debisure-resend-button');
            var successParams = new URLSearchParams(window.location.search);
            var successfulMandateId = successParams.get('resend_result') === 'success'
                ? successParams.get('mandate_id')
                : null;
            var storagePrefix = 'debisure-resend-cooldown-';

            function startCooldown(button, expiresAt) {
                var remaining = expiresAt - Date.now();
                if (remaining <= 0) {
                    button.disabled = false;
                    try {
                        sessionStorage.removeItem(storagePrefix + button.dataset.mandateId);
                    } catch (error) {
                    }
                    return;
                }
                button.disabled = true;
                window.setTimeout(function () {
                    startCooldown(button, expiresAt);
                }, remaining);
            }

            buttons.forEach(function (button) {
                var key = storagePrefix + button.dataset.mandateId;
                var expiresAt = 0;

                try {
                    expiresAt = Number(sessionStorage.getItem(key)) || 0;
                    if (successfulMandateId === button.dataset.mandateId && expiresAt <= Date.now()) {
                        expiresAt = Date.now() + 10000;
                        sessionStorage.setItem(key, String(expiresAt));
                    }
                } catch (error) {
                    if (successfulMandateId === button.dataset.mandateId) {
                        expiresAt = Date.now() + 10000;
                    }
                }

                if (expiresAt > Date.now()) {
                    startCooldown(button, expiresAt);
                }

                button.form.addEventListener('submit', function () {
                    button.disabled = true;
                });
            });

            if (successfulMandateId) {
                successParams.delete('resend_result');
                successParams.delete('resend_done');
                successParams.delete('mandate_id');
                var updatedUrl = window.location.pathname + (successParams.toString() ? '?' + successParams.toString() : '') + window.location.hash;
                window.history.replaceState({}, document.title, updatedUrl);
            }
        }());
    </script>
    <?php
}

function debisure_has_activation_credentials() {
    return '' !== trim( (string) get_option( 'debisure_client_id' ) )
        && ! empty( get_option( 'debisure_api_token' ) );
}

function debisure_settings_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $has_activation_credentials = debisure_has_activation_credentials();
    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ( $has_activation_credentials ? 'form-builder' : 'credentials' );
    $available_tabs = $has_activation_credentials
        ? array( 'credentials', 'form-builder', 'status' )
        : array( 'credentials' );
    if ( ! in_array( $active_tab, $available_tabs, true ) ) {
        $active_tab = 'credentials';
    }

    $credentials_url = add_query_arg(
        array( 'page' => 'debisure-settings', 'tab' => 'credentials' ),
        admin_url( 'admin.php' )
    );
    $form_builder_url = add_query_arg(
        array( 'page' => 'debisure-settings', 'tab' => 'form-builder' ),
        admin_url( 'admin.php' )
    );
    $status_url = add_query_arg(
        array( 'page' => 'debisure-settings', 'tab' => 'status' ),
        admin_url( 'admin.php' )
    );
    ?>
    <div class="wrap">
        <h1>Debisure Settings</h1>
        <nav class="nav-tab-wrapper">
            <?php if ( $has_activation_credentials ) : ?>
                <a href="<?php echo esc_url( $form_builder_url ); ?>" class="nav-tab <?php echo 'form-builder' === $active_tab ? 'nav-tab-active' : ''; ?>">Form Builder</a>
            <?php endif; ?>
            <a href="<?php echo esc_url( $credentials_url ); ?>" class="nav-tab <?php echo 'credentials' === $active_tab ? 'nav-tab-active' : ''; ?>">Setup</a>
            <?php if ( $has_activation_credentials ) : ?>
                <a href="<?php echo esc_url( $status_url ); ?>" class="nav-tab <?php echo 'status' === $active_tab ? 'nav-tab-active' : ''; ?>">Status</a>
            <?php endif; ?>
        </nav>
    <?php

    if ( 'form-builder' === $active_tab ) :
        $fields = debisure_get_form_fields();
        $amounts = debisure_get_form_amounts();
        $debit_days = debisure_get_form_debit_days();
        $recaptcha_settings = debisure_get_recaptcha_settings();
        ?>
        <?php settings_errors(); ?>
        <div class="notice notice-info inline" style="margin: 15px 0 20px 0; padding: 12px;">
            <h3>Debisure Form Builder</h3>
        </div>
        <p>Choose which supported mandate fields appear on the form, edit custom field labels and types, and set whether fields are required.</p>
        <form action="options.php" method="post">
            <?php settings_fields( 'debisure_form_builder_group' ); ?>
            <?php $field_definitions = debisure_form_field_definitions(); ?>
            <table class="widefat striped" style="max-width: 900px;">
                <thead>
                    <tr>
                        <th scope="col">Field</th>
                        <th scope="col">Type</th>
                        <th scope="col">Show on form</th>
                        <th scope="col">Required</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( debisure_form_builder_field_order() as $key ) : ?>
                        <?php $definition = $field_definitions[ $key ]; ?>
                        <?php $locked = ! empty( $definition['builder_locked'] ); ?>
                        <?php $custom_field = ! empty( $definition['custom_field'] ); ?>
                        <tr<?php echo $locked ? ' style="background-color: #e5e5e5;"' : ''; ?>>
                            <th scope="row">
                                <?php if ( $custom_field ) : ?>
                                    <label class="screen-reader-text" for="debisure_custom_label_<?php echo esc_attr( $key ); ?>">Label for <?php echo esc_html( $key ); ?></label>
                                    <input type="text" class="debisure-custom-field-label" id="debisure_custom_label_<?php echo esc_attr( $key ); ?>" name="debisure_custom_form_fields[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $definition['label'] ); ?>" />
                                <?php else : ?>
                                    <?php echo esc_html( $definition['builder_label'] ?? $definition['label'] ); ?>
                                <?php endif; ?>
                            </th>
                            <td>
                                <?php if ( $custom_field ) : ?>
                                    <label class="screen-reader-text" for="debisure_custom_type_<?php echo esc_attr( $key ); ?>">Type for <?php echo esc_html( $definition['label'] ); ?></label>
                                    <select class="debisure-custom-field-type" id="debisure_custom_type_<?php echo esc_attr( $key ); ?>" name="debisure_custom_form_fields[<?php echo esc_attr( $key ); ?>][type]">
                                        <option value="text" <?php selected( 'text', $definition['type'] ); ?>>Text</option>
                                        <option value="checkbox" <?php selected( 'checkbox', $definition['type'] ); ?>>Checkbox</option>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td>
                                <label>
                                    <input type="checkbox" name="debisure_form_fields[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $fields[ $key ]['enabled'] ); ?> <?php disabled( $locked ); ?> />
                                    Show field
                                </label>
                            </td>
                            <td>
                                <label>
                                    <input type="checkbox" name="debisure_form_fields[<?php echo esc_attr( $key ); ?>][required]" value="1" <?php checked( $fields[ $key ]['required'] ); ?> <?php disabled( $locked ); ?> />
                                    Required
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <h2>Amount Options</h2>
            <p>Set three distinct preset amounts and the default value shown when a customer chooses Other. All values must be at least R1.00.</p>
            <div class="debisure-amount-presets">
                <?php foreach ( $amounts['options'] as $index => $amount ) : ?>
                    <div class="debisure-amount-preset">
                        <label for="debisure_amount_option_<?php echo esc_attr( (string) $index ); ?>">Preset <?php echo esc_html( (string) ( $index + 1 ) ); ?></label>
                        <input type="number" min="1" step="0.01" id="debisure_amount_option_<?php echo esc_attr( (string) $index ); ?>" name="debisure_form_amounts[options][<?php echo esc_attr( (string) $index ); ?>]" value="<?php echo esc_attr( $amount ); ?>" required />
                    </div>
                <?php endforeach; ?>
                <div class="debisure-amount-preset">
                    <label for="debisure_custom_amount_default">Custom Amount</label>
                    <input type="number" min="1" step="0.01" id="debisure_custom_amount_default" name="debisure_form_amounts[custom_default]" value="<?php echo esc_attr( $amounts['custom_default'] ); ?>" required />
                </div>
            </div>
            <h2>Debit Day Options</h2>
            <p>Select at least one debit day option to make available on the form.</p>
            <div class="debisure-debit-day-options">
                <input type="hidden" name="debisure_form_debit_days[_submitted]" value="1" />
                <?php foreach ( debisure_default_form_debit_days() as $value => $label ) : ?>
                    <label class="debisure-debit-day-option">
                        <input type="checkbox" name="debisure_form_debit_days[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( isset( $debit_days[ $value ] ) ); ?> />
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <h2>reCAPTCHA v3</h2>
            <p>Enter both Google reCAPTCHA v3 keys to enable verification on the public form.</p>
            <?php if ( ( '' !== $recaptcha_settings['site_key'] ) !== ( '' !== $recaptcha_settings['secret_key'] ) ) : ?>
                <div class="notice notice-warning inline"><p>reCAPTCHA is not enabled because both keys are required.</p></div>
            <?php endif; ?>
            <div class="debisure-recaptcha-settings">
                <p>
                    <label for="debisure_recaptcha_site_key">Site key</label><br />
                    <input type="password" class="regular-text" id="debisure_recaptcha_site_key" name="debisure_recaptcha_settings[site_key]" value="" placeholder="<?php echo '' !== $recaptcha_settings['site_key'] ? 'Saved; enter a new key to replace it' : ''; ?>" autocomplete="new-password" />
                    <?php if ( '' !== $recaptcha_settings['site_key'] ) : ?>
                        <br /><label><input type="checkbox" name="debisure_recaptcha_settings[clear_site]" value="1" /> Clear saved site key</label>
                    <?php endif; ?>
                </p>
                <p>
                    <label for="debisure_recaptcha_secret_key">Secret key</label><br />
                    <input type="password" class="regular-text" id="debisure_recaptcha_secret_key" name="debisure_recaptcha_settings[secret_key]" value="" placeholder="<?php echo '' !== $recaptcha_settings['secret_key'] ? 'Saved; enter a new key to replace it' : ''; ?>" autocomplete="new-password" />
                </p>
                <?php if ( '' !== $recaptcha_settings['secret_key'] ) : ?>
                    <p><label><input type="checkbox" name="debisure_recaptcha_settings[clear_secret]" value="1" /> Clear saved secret key</label></p>
                <?php endif; ?>
            </div>
            <?php submit_button( 'Save Form Settings' ); ?>
        </form>
    </div>
    <?php
        return;
    endif;

    if ( 'status' === $active_tab ) :
        $api_online = debisure_health_endpoint_is_online( 'https://api.debisure.com/api/v1/health' );
        $callback_online = $api_online && debisure_health_endpoint_is_online( 'https://debisure.com/v1/health/' );
        $api_status = ! $api_online
            ? 'Offline'
            : ( $callback_online ? 'Online' : 'Degraded - Callback Offline' );
        $cron_type = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'crontab' : 'wpcron';
        ?>
        <div class="notice notice-info inline" style="margin: 15px 0 20px 0; padding: 12px;">
            <h3>Debisure Status</h3>
        </div>
        <table class="form-table">
            <tr>
                <th scope="row">Cron Type</th>
                <td><?php echo esc_html( $cron_type ); ?></td>
            </tr>
            <tr>
                <th scope="row">API Status</th>
                <td><?php echo esc_html( $api_status ); ?></td>
            </tr>
        </table>
    </div>
    <?php
        return;
    endif;
    
    $has_token       = get_option( 'debisure_api_token' ) ? true : false;
    $has_service_key = get_option( 'debisure_service_key' ) ? true : false;
    $has_vendor_key  = get_option( 'debisure_vendor_key' ) ? true : false;
    
    ?>
    <div>
        <?php settings_errors(); ?>

        <div class="notice notice-info inline" style="margin: 15px 0 20px 0; padding: 12px;">
            <h3>Debisure Setup</h3>
            <p>Please enter your Client ID and API Key to activate this plugin.</p>
        </div>
        <?php if ( ! $has_activation_credentials ) : ?>
            <div class="notice notice-warning inline"><p>Please activate the plugin with your API key to use these features.</p></div>
        <?php endif; ?>

        <form action="options.php" method="post">
            <?php settings_fields( 'debisure_settings_group' ); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="debisure_reference_prefix">Reference Prefix</label></th>
                    <td>
                        <input type="text" id="debisure_reference_prefix" name="debisure_reference_prefix" value="<?php echo esc_attr( get_option( 'debisure_reference_prefix', 'WEB' ) ); ?>" class="regular-text" />
                        <p class="description">Added before each generated account reference, for example <code>WEB-...</code>.</p>
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
                                Enter a new key to update, or select the checkbox below to clear it.
                            <?php else : ?>
                                Enter your API key. It will be tested against the validation endpoint before saving.
                            <?php endif; ?>
                        </p>
                        <?php if ( $has_token ) : ?>
                            <label><input type="checkbox" name="debisure_clear_api_token" value="1" /> Clear saved API key</label>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="debisure_service_key">Netcash Service Key</label></th>
                    <td>
                        <input type="text" id="debisure_service_key" name="debisure_service_key" value="" placeholder="<?php echo $has_service_key ? '******** (Saved)' : ''; ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                        <p class="description">
                            <?php if ( $has_service_key ) : ?>
                                Enter a new key to update, or select the checkbox below to clear it.
                            <?php else : ?>
                                Enter your Netcash Service Key.
                            <?php endif; ?>
                        </p>
                        <?php if ( $has_service_key ) : ?>
                            <label><input type="checkbox" name="debisure_clear_service_key" value="1" /> Clear saved Service Key</label>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="debisure_vendor_key">Netcash Vendor Key</label></th>
                    <td>
                        <input type="text" id="debisure_vendor_key" name="debisure_vendor_key" value="" placeholder="<?php echo $has_vendor_key ? '******** (Saved)' : ''; ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                        <p class="description">
                            <?php if ( $has_vendor_key ) : ?>
                                Enter a new key to update, or select the checkbox below to clear it.
                            <?php else : ?>
                                Enter your Netcash Vendor Key.
                            <?php endif; ?>
                        </p>
                        <?php if ( $has_vendor_key ) : ?>
                            <label><input type="checkbox" name="debisure_clear_vendor_key" value="1" /> Clear saved Vendor Key</label>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
    </div>
    </div>
    <?php
}