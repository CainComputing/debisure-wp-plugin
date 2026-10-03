<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register WordPress REST API endpoint for background Webhooks / Callbacks
 * Endpoint: YourSite.com/wp-json/debisure/v1/webhook
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'debisure/v1', '/webhook', array(
        'methods'             => 'POST',
        'callback'            => 'debisure_handle_rest_webhook',
        'permission_callback' => 'debisure_verify_webhook_origin',
    ) );
} );

/**
 * Verify that the incoming REST webhook request originates securely from debisure.com
 */
function debisure_verify_webhook_origin( WP_REST_Request $request ) {
    $referer    = $request->get_header( 'referer' );
    $origin     = $request->get_header( 'origin' );
    $client_ip  = $_SERVER['REMOTE_ADDR'] ?? '';

    $is_valid = false;

    // 1. Strict hostname check for debisure.com
    if ( $referer ) {
        $host = parse_url( $referer, PHP_URL_HOST );
        if ( $host && strtolower( $host ) === 'debisure.com' ) {
            $is_valid = true;
        }
    }
    if ( ! $is_valid && $origin ) {
        $host = parse_url( $origin, PHP_URL_HOST );
        if ( $host && strtolower( $host ) === 'debisure.com' ) {
            $is_valid = true;
        }
    }

    // 2. Allow local server testing IP if needed
    $allowed_ips = array( '127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? '' );
    if ( in_array( $client_ip, $allowed_ips, true ) ) {
        $is_valid = true;
    }

    // 3. Optional matching via API Key header
    $incoming_api_key = $request->get_header( 'x-api-key' );
    $saved_api_token  = debisure_get_api_token();
    if ( ! empty( $incoming_api_key ) && ! empty( $saved_api_token ) && hash_equals( $saved_api_token, $incoming_api_key ) ) {
        $is_valid = true;
    }

    if ( ! $is_valid ) {
        return new WP_Error( 'rest_forbidden', 'Unauthorized webhook source.', array( 'status' => 403 ) );
    }

    return true;
}

function debisure_handle_rest_webhook( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'debisure_mandates';

    $params = $request->get_json_params();
    if ( empty( $params ) ) {
        $params = $request->get_body_params();
    }

    $account_reference = isset( $params['accountReference'] ) ? sanitize_text_field( $params['accountReference'] ) : ( isset( $params['MandateReferenceNumber'] ) ? sanitize_text_field( $params['MandateReferenceNumber'] ) : '' );
    
    $mandate_successful = $params['MandateSuccessful'] ?? ( $params['success'] ?? false );
    $new_status = ( $mandate_successful == '1' || strtolower( (string)$mandate_successful ) === 'true' ) ? 'success' : 'failed';

    if ( ! empty( $account_reference ) ) {
        $wpdb->update(
            $table_name,
            array( 'status' => $new_status ),
            array( 'account_reference' => $account_reference ),
            array( '%s' ),
            array( '%s' )
        );
    }

    return new WP_REST_Response( array( 'success' => true, 'message' => 'Webhook received and status updated' ), 200 );
}

/**
 * Test credentials against the Debisure Auth Validate endpoint
 */
function debisure_validate_api_credentials( $client_id, $service_key, $vendor_key, $api_token ) {
    $auth_endpoint = 'https://api.debisure.com/api/v1/auth/validate'; 

    $headers = array(
        'Content-Type' => 'application/json',
        'accept'       => 'text/plain',
    );

    if ( ! empty( $service_key ) ) {
        $headers['X-Service-Key'] = $service_key;
    }
    if ( ! empty( $vendor_key ) ) {
        $headers['X-Vendor-Key'] = $vendor_key;
    }

    $payload = array(
        'clientId' => $client_id,
        'apiKey'   => $api_token,
    );

    $response = wp_remote_post( $auth_endpoint, array(
        'headers' => $headers,
        'body'    => json_encode( $payload ),
        'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'connection_failed', 'Could not connect to Debisure API: ' . $response->get_error_message() );
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );

    if ( $response_code >= 200 && $response_code < 300 && isset( $data['success'] ) && $data['success'] === true ) {
        return true;
    }

    $error_message = isset( $data['message'] ) ? $data['message'] : 'Invalid API Key or Client ID.';
    return new WP_Error( 'invalid_credentials', $error_message );
}

add_action( 'wp_ajax_debisure_submit_form', 'debisure_handle_form_submission' );
add_action( 'wp_ajax_nopriv_debisure_submit_form', 'debisure_handle_form_submission' );

function debisure_handle_form_submission() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'debisure_mandates';

    $input_JSON = file_get_contents( 'php://input' );
    $data = json_decode( $input_JSON, true );

    if ( empty( $data ) ) {
        wp_send_json_error( 'Invalid form data received.' );
    }

    $account_reference = sanitize_text_field( $data['accountReference'] );

    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE account_reference = %s",
        $account_reference
    ) );

    if ( $exists > 0 ) {
        wp_send_json_error( 'Duplicate submission error: This account reference has already been used.' );
    }

    $client_id        = get_option( 'debisure_client_id' );
    $service_key      = get_option( 'debisure_service_key' );
    $vendor_key       = get_option( 'debisure_vendor_key' );
    $api_token        = debisure_get_api_token();
    $callback_page_id = get_option( 'debisure_callback_page_id' );
    $callback_url     = $callback_page_id ? get_permalink( $callback_page_id ) : '';

    if ( empty( $api_token ) ) {
        wp_send_json_error( 'API Key is not configured in plugin settings.' );
    }

    if ( empty( $client_id ) ) {
        wp_send_json_error( 'Client ID is not configured in plugin settings.' );
    }

    if ( empty( $callback_url ) ) {
        wp_send_json_error( 'Callback Page is not configured in Debisure settings.' );
    }

    $data['clientId']    = $client_id;
    $data['callBackUrl'] = $callback_url;

    $api_endpoint = 'https://api.debisure.com/api/v1/mandates';

    $headers = array(
        'Content-Type' => 'application/json',
        'accept'       => 'text/plain',
        'X-Api-Key'    => $api_token,
    );

    if ( ! empty( $service_key ) ) {
        $headers['X-Service-Key'] = $service_key;
    }
    if ( ! empty( $vendor_key ) ) {
        $headers['X-Vendor-Key'] = $vendor_key;
    }

    $response = wp_remote_post( $api_endpoint, array(
        'headers' => $headers,
        'body'    => json_encode( $data ),
        'timeout' => 45,
    ) );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'API Connection Error: ' . $response->get_error_message() );
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    if ( $response_code >= 200 && $response_code < 300 ) {
        // Insert all submitted payload fields into the database with initial 'pending' status
        $wpdb->insert(
            $table_name,
            array(
                'account_reference'  => $account_reference,
                'mandate_name'       => sanitize_text_field( $data['mandateName'] ?? '' ),
                'mandate_amount'     => floatval( $data['mandateAmount'] ?? 0 ),
                'is_individual'      => ! empty( $data['isIndividual'] ) ? 1 : 0,
                'first_name'         => sanitize_text_field( $data['firstName'] ?? '' ),
                'surname'            => sanitize_text_field( $data['surname'] ?? '' ),
                'mobile_no'          => sanitize_text_field( $data['mobileNo'] ?? '' ),
                'email_address'      => sanitize_email( $data['emailAddress'] ?? '' ),
                'debit_day'          => sanitize_text_field( $data['debitDay'] ?? '' ),
                'december_debit_day' => sanitize_text_field( $data['decemberDebitDay'] ?? '' ),
                'client_id'          => sanitize_text_field( $client_id ),
                'callback_url'       => esc_url_raw( $callback_url ),
                'status'             => 'pending',
            ),
            array( '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        wp_send_json_success( json_decode( $response_body, true ) );
    } else {
        wp_send_json_error( 'API error (Code ' . $response_code . '): ' . $response_body );
    }
}