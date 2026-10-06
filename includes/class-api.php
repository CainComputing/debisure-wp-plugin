<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'debisure/v1', '/webhook', array(
        'methods'             => 'POST',
        'callback'            => 'debisure_handle_rest_webhook',
        'permission_callback' => 'debisure_verify_webhook_signature',
    ) );
} );

function debisure_verify_webhook_signature( WP_REST_Request $request ) {
    $api_key = debisure_get_api_token();
    if ( empty( $api_key ) ) {
        return new WP_Error( 'webhook_not_configured', 'Webhook authentication is not configured.', array( 'status' => 503 ) );
    }

    $timestamp = $request->get_header( 'x-debisure-timestamp' );
    $signature = $request->get_header( 'x-debisure-signature' );
    if ( ! is_string( $timestamp ) || ! ctype_digit( $timestamp ) || ! is_string( $signature ) || ! preg_match( '/\A[a-f0-9]{64}\z/i', $signature ) ) {
        return new WP_Error( 'invalid_webhook_authentication', 'Webhook signature headers are missing or invalid.', array( 'status' => 401 ) );
    }

    if ( abs( time() - (int) $timestamp ) > 300 ) {
        return new WP_Error( 'expired_webhook_timestamp', 'Webhook timestamp is outside the allowed time window.', array( 'status' => 401 ) );
    }

    $body = $request->get_body();
    $expected_signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $api_key );
    if ( ! hash_equals( $expected_signature, strtolower( $signature ) ) ) {
        return new WP_Error( 'invalid_webhook_signature', 'Webhook signature verification failed.', array( 'status' => 401 ) );
    }

    return true;
}

function debisure_handle_rest_webhook( WP_REST_Request $request ) {
    $body = $request->get_body();
    $params = json_decode( $body, true );
    if ( ! is_array( $params ) ) {
        parse_str( $body, $params );
    }
    if ( ! is_array( $params ) ) {
        return new WP_Error( 'invalid_webhook_body', 'Webhook body must be valid JSON or form-encoded data.', array( 'status' => 400 ) );
    }

    $result = debisure_update_mandate_status_from_callback( $params );
    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return new WP_REST_Response( array( 'success' => true, 'status' => $result ), 200 );
}

function debisure_normalize_province_code( $value ) {
    if ( ! is_scalar( $value ) || is_bool( $value ) ) {
        return null;
    }

    $value = trim( (string) $value );
    if ( preg_match( '/\A[1-9]\z/', $value ) ) {
        return (int) $value;
    }

    $province_codes = array(
        'western cape'  => 1,
        'gauteng'       => 2,
        'eastern cape'  => 3,
        'free state'    => 4,
        'kwazulu natal' => 5,
        'kwazulu-natal' => 5,
        'limpopo'       => 6,
        'mpumalanga'    => 7,
        'northern cape' => 8,
        'north west'    => 9,
    );

    $province_name = strtolower( preg_replace( '/\s+/', ' ', $value ) );
    return $province_codes[ $province_name ] ?? null;
}

function debisure_update_mandate_status_from_callback( $params ) {
    global $wpdb;

    if ( ! is_array( $params ) ) {
        return new WP_Error( 'invalid_callback', 'Callback data must be an object.', array( 'status' => 400 ) );
    }

    $account_reference_value = $params['account_reference'] ?? $params['accountReference'] ?? '';
    if ( ! is_string( $account_reference_value ) || '' === trim( $account_reference_value ) ) {
        return new WP_Error( 'missing_callback_reference', 'Callback is missing the mandate account reference.', array( 'status' => 400 ) );
    }
    $account_reference = sanitize_text_field( $account_reference_value );

    if ( ! isset( $params['status'] ) || ! is_string( $params['status'] ) ) {
        return new WP_Error( 'missing_callback_status', 'Callback is missing the mandate result.', array( 'status' => 400 ) );
    }
    $callback_status = strtolower( trim( $params['status'] ) );

    if ( 'success' === $callback_status ) {
        $new_status = 'success';
    } elseif ( 'failed' === $callback_status ) {
        $new_status = 'failed';
    } else {
        return new WP_Error( 'invalid_callback_status', 'Callback status must be success or failed.', array( 'status' => 400 ) );
    }

    $update_data = array( 'status' => $new_status );
    $update_formats = array( '%s' );
    $callback_columns = array(
        'mandate_name'              => array( 'aliases' => array( 'mandate_name', 'mandateName' ), 'column' => 'mandate_name', 'type' => 'text' ),
        'is_individual'             => array( 'aliases' => array( 'is_individual', 'isIndividual' ), 'column' => 'is_individual', 'type' => 'boolean' ),
        'first_name'                => array( 'aliases' => array( 'first_name', 'firstName' ), 'column' => 'first_name', 'type' => 'text' ),
        'surname'                   => array( 'aliases' => array( 'surname' ), 'column' => 'surname', 'type' => 'text' ),
        'business_account_name'     => array( 'aliases' => array( 'business_account_name', 'businessAccountName' ), 'column' => 'business_account_name', 'type' => 'text' ),
        'business_account_reg_no'   => array( 'aliases' => array( 'business_account_reg_no', 'businessAccountRegNo' ), 'column' => 'business_account_reg_no', 'type' => 'text' ),
        'business_account_reg_name' => array( 'aliases' => array( 'business_account_reg_name', 'businessAccountRegName' ), 'column' => 'business_account_reg_name', 'type' => 'text' ),
        'mobile_no'                 => array( 'aliases' => array( 'mobile_no', 'mobileNo' ), 'column' => 'mobile_no', 'type' => 'text' ),
        'email_address'             => array( 'aliases' => array( 'email_address', 'emailAddress' ), 'column' => 'email_address', 'type' => 'email' ),
        'building'                  => array( 'aliases' => array( 'building' ), 'column' => 'building', 'type' => 'text' ),
        'street'                    => array( 'aliases' => array( 'street' ), 'column' => 'street', 'type' => 'text' ),
        'city'                      => array( 'aliases' => array( 'city' ), 'column' => 'city', 'type' => 'text' ),
        'province'                  => array( 'aliases' => array( 'province' ), 'column' => 'province', 'type' => 'text' ),
        'postal_code'               => array( 'aliases' => array( 'postal_code', 'postalCode' ), 'column' => 'postal_code', 'type' => 'text' ),
        'debit_day'                 => array( 'aliases' => array( 'debit_day', 'debitDay' ), 'column' => 'debit_day', 'type' => 'text' ),
        'amount'                    => array( 'aliases' => array( 'amount', 'mandateAmount' ), 'column' => 'amount', 'type' => 'amount' ),
        'agreement_date'            => array( 'aliases' => array( 'agreement_date', 'agreementDate' ), 'column' => 'agreement_date', 'type' => 'text' ),
        'mandate_reference'         => array( 'aliases' => array( 'mandate_reference', 'mandateReference' ), 'column' => 'mandate_reference', 'type' => 'text' ),
        'reason_for_decline'        => array( 'aliases' => array( 'reason_for_decline', 'reasonForDecline' ), 'column' => 'reason_for_decline', 'type' => 'textarea' ),
        'mandate_pdf'               => array( 'aliases' => array( 'mandate_pdf', 'mandatePdf' ), 'column' => 'mandate_pdf', 'type' => 'url' ),
    );

    foreach ( $callback_columns as $column_details ) {
        $provided_key = null;
        foreach ( $column_details['aliases'] as $alias ) {
            if ( array_key_exists( $alias, $params ) ) {
                $provided_key = $alias;
                break;
            }
        }
        if ( null === $provided_key ) {
            continue;
        }
        $value = $params[ $provided_key ];

        if ( 'province' === $column_details['column'] ) {
            if ( is_string( $value ) && '' === trim( $value ) ) {
                continue;
            }
            $province_code = debisure_normalize_province_code( $value );
            if ( null === $province_code ) {
                return new WP_Error( 'invalid_callback_field', 'Callback field ' . $provided_key . ' must be a valid province code or name.', array( 'status' => 400 ) );
            }
            $update_data[ $column_details['column'] ] = $province_code;
            $update_formats[] = '%d';
            continue;
        }

        if ( 'boolean' === $column_details['type'] ) {
            if ( ! is_bool( $value ) && ! in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
                return new WP_Error( 'invalid_callback_field', 'Callback field ' . $provided_key . ' must be a boolean.', array( 'status' => 400 ) );
            }
            $update_data[ $column_details['column'] ] = in_array( $value, array( true, 1, '1', 'true' ), true ) ? 1 : 0;
            $update_formats[] = '%d';
            continue;
        }

        if ( 'amount' === $column_details['type'] ) {
            if ( is_string( $value ) && '' === trim( $value ) ) {
                continue;
            }
            if ( is_string( $value ) ) {
                $value = trim( $value );
                if ( preg_match( '/\A\d+,\d+\z/', $value ) ) {
                    $value = str_replace( ',', '.', $value );
                }
            }
            if ( ! is_scalar( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 1 || (float) $value > 99999999.99 ) {
                return new WP_Error( 'invalid_callback_field', 'Callback field ' . $provided_key . ' must be a valid amount of at least R1.00.', array( 'status' => 400 ) );
            }
            $update_data[ $column_details['column'] ] = (float) $value;
            $update_formats[] = '%f';
            continue;
        }

        if ( ! is_string( $value ) ) {
            return new WP_Error( 'invalid_callback_field', 'Callback field ' . $provided_key . ' must be a string.', array( 'status' => 400 ) );
        }
        if ( '' === trim( $value ) ) {
            continue;
        }

        switch ( $column_details['type'] ) {
            case 'email':
                $value = sanitize_email( $value );
                if ( '' === $value || ! is_email( $value ) ) {
                    return new WP_Error( 'invalid_callback_field', 'Callback field ' . $provided_key . ' must be a valid email address.', array( 'status' => 400 ) );
                }
                break;
            case 'textarea':
                $value = sanitize_textarea_field( $value );
                break;
            case 'url':
                $sanitized_url = esc_url_raw( $value );
                $url_parts = '' !== $sanitized_url ? wp_parse_url( $sanitized_url ) : false;
                if (
                    '' === $sanitized_url
                    || ! is_array( $url_parts )
                    || empty( $url_parts['host'] )
                    || ! isset( $url_parts['scheme'] )
                    || ! in_array( strtolower( $url_parts['scheme'] ), array( 'http', 'https' ), true )
                ) {
                    return new WP_Error( 'invalid_callback_field', 'Callback field ' . $provided_key . ' must be a valid URL.', array( 'status' => 400 ) );
                }
                $value = $sanitized_url;
                break;
            default:
                $value = sanitize_text_field( $value );
                break;
        }

        $update_data[ $column_details['column'] ] = $value;
        $update_formats[] = '%s';
    }

    $table_name = $wpdb->prefix . 'debisure';
    $updated = $wpdb->update(
        $table_name,
        $update_data,
        array( 'account_reference' => $account_reference ),
        $update_formats,
        array( '%s' )
    );

    if ( false === $updated ) {
        return new WP_Error( 'callback_database_error', 'Could not update mandate status: ' . $wpdb->last_error, array( 'status' => 500 ) );
    }

    if ( 0 === $updated ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE account_reference = %s",
            $account_reference
        ) );
        if ( ! $exists ) {
            return new WP_Error( 'callback_mandate_not_found', 'No mandate matches the callback account reference.', array( 'status' => 404 ) );
        }
    }

    return $new_status;
}

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

function debisure_verify_recaptcha_v3( $token ) {
    $settings = debisure_get_recaptcha_settings();
    $has_site_key = '' !== $settings['site_key'];
    $has_secret_key = '' !== $settings['secret_key'];

    if ( ! $has_site_key && ! $has_secret_key ) {
        return true;
    }
    if ( ! $has_site_key || ! $has_secret_key ) {
        return new WP_Error( 'recaptcha_incomplete_config', 'Both reCAPTCHA keys must be configured.' );
    }
    if ( ! is_string( $token ) || '' === trim( $token ) ) {
        return new WP_Error( 'recaptcha_missing_token', 'reCAPTCHA verification token is missing.' );
    }

    $response = wp_remote_post(
        'https://www.google.com/recaptcha/api/siteverify',
        array(
            'timeout' => 10,
            'body'    => array(
                'secret'   => $settings['secret_key'],
                'response' => $token,
            ),
        )
    );
    if ( is_wp_error( $response ) ) {
        error_log( 'Debisure reCAPTCHA verification request failed: ' . $response->get_error_message() );
        return new WP_Error( 'recaptcha_service_unavailable', 'reCAPTCHA verification is temporarily unavailable.' );
    }

    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
        error_log( 'Debisure reCAPTCHA verification returned HTTP ' . wp_remote_retrieve_response_code( $response ) . '.' );
        return new WP_Error( 'recaptcha_service_unavailable', 'reCAPTCHA verification is temporarily unavailable.' );
    }

    $result = json_decode( wp_remote_retrieve_body( $response ), true );
    $site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    if (
        ! is_array( $result )
        || true !== ( $result['success'] ?? false )
        || 'mandate_submit' !== ( $result['action'] ?? '' )
        || ! is_numeric( $result['score'] ?? null )
        || (float) $result['score'] < 0.5
        || '' === $site_host
        || strtolower( (string) ( $result['hostname'] ?? '' ) ) !== $site_host
    ) {
        return new WP_Error( 'recaptcha_rejected', 'reCAPTCHA verification failed. Please try again.' );
    }

    return true;
}

function debisure_handle_form_submission() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'debisure';

    $input_JSON = file_get_contents( 'php://input' );
    $data = json_decode( $input_JSON, true );

    if ( ! is_array( $data ) || empty( $data ) ) {
        wp_send_json_error( 'Invalid form data received.' );
    }

    $recaptcha_token = $data['recaptchaToken'] ?? '';
    unset( $data['recaptchaToken'] );
    $recaptcha_result = debisure_verify_recaptcha_v3( $recaptcha_token );
    if ( is_wp_error( $recaptcha_result ) ) {
        if ( 'recaptcha_incomplete_config' === $recaptcha_result->get_error_code() ) {
            error_log( 'Debisure form submission rejected: reCAPTCHA keys are only partially configured.' );
            wp_send_json_error( 'The form security check is not configured correctly. Please contact the site administrator.' );
        }
        wp_send_json_error( $recaptcha_result->get_error_message() );
    }

    $field_settings = debisure_get_form_fields();
    $field_definitions = debisure_form_field_definitions();
    $field_definitions['debitDay']['options'] = debisure_get_form_debit_days();
    $amount_settings = debisure_get_form_amounts();
    $custom_amount_value = $data['isCustomAmount'] ?? false;
    if ( ! is_bool( $custom_amount_value ) && ! in_array( $custom_amount_value, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
        wp_send_json_error( 'Invalid custom amount selection.' );
    }
    $is_custom_amount = in_array( $custom_amount_value, array( true, 1, '1', 'true' ), true );
    unset( $data['isCustomAmount'] );
    $is_business_account = false;
    if ( ! empty( $field_settings['isBusinessAccount']['enabled'] ) ) {
        $business_account_value = $data['isBusinessAccount'] ?? false;
        if ( ! is_bool( $business_account_value ) && ! in_array( $business_account_value, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
            wp_send_json_error( 'Invalid value for ' . $field_definitions['isBusinessAccount']['label'] . '.' );
        }
        $is_business_account = in_array( $business_account_value, array( true, 1, '1', 'true' ), true );
        $data['isBusinessAccount'] = $is_business_account;
    }
    foreach ( $field_definitions as $field_name => $definition ) {
        if ( ! empty( $definition['system_managed'] ) ) {
            continue;
        }
        if ( empty( $field_settings[ $field_name ]['enabled'] ) ) {
            unset( $data[ $field_name ] );
            continue;
        }
        if ( ! empty( $definition['business_only'] ) && ! $is_business_account ) {
            unset( $data[ $field_name ] );
            continue;
        }

        if ( 'checkbox' === $definition['type'] ) {
            if ( ! array_key_exists( $field_name, $data ) ) {
                if ( ! empty( $field_settings[ $field_name ]['required'] ) ) {
                    wp_send_json_error( $definition['label'] . ' is required.' );
                }
                $data[ $field_name ] = false;
            } elseif ( ! is_bool( $data[ $field_name ] ) && ! in_array( $data[ $field_name ], array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
                wp_send_json_error( 'Invalid value for ' . $definition['label'] . '.' );
            } else {
                $checkbox_value = in_array( $data[ $field_name ], array( true, 1, '1', 'true' ), true );
                if ( ! empty( $definition['custom_field'] ) && ! empty( $field_settings[ $field_name ]['required'] ) && ! $checkbox_value ) {
                    wp_send_json_error( $definition['label'] . ' is required.' );
                }
                $data[ $field_name ] = $checkbox_value;
            }
            continue;
        }

        if (
            'businessAccountRegName' === $field_name
            && $is_business_account
            && empty( $data[ $field_name ] )
            && ! empty( $data['businessAccountName'] )
        ) {
            $data[ $field_name ] = $data['businessAccountName'];
        }

        $value = $data[ $field_name ] ?? null;
        if ( ! is_scalar( $value ) || ( is_string( $value ) && '' === trim( $value ) ) ) {
            if ( ! empty( $field_settings[ $field_name ]['required'] ) ) {
                wp_send_json_error( $definition['label'] . ' is required.' );
            }
            unset( $data[ $field_name ] );
            continue;
        }

        if ( in_array( $definition['type'], array( 'number', 'amount_radio' ), true ) ) {
            if ( ! is_numeric( $value ) ) {
                wp_send_json_error( $definition['label'] . ' must be a number.' );
            }
            if ( 'amount_radio' === $definition['type'] ) {
                $amount = (float) $value;
                if ( $is_custom_amount ) {
                    if ( empty( $amount_settings['allow_custom'] ) ) {
                        wp_send_json_error( 'Custom amounts are not allowed.' );
                    }
                    if (
                        $amount < (float) $amount_settings['custom_minimum']
                        || ( '' !== $amount_settings['custom_maximum'] && $amount > (float) $amount_settings['custom_maximum'] )
                    ) {
                        wp_send_json_error( 'Custom amount must be within the configured minimum and maximum.' );
                    }
                } else {
                    $preset_amounts = array_map( 'floatval', $amount_settings['options'] );
                    if ( ! in_array( $amount, $preset_amounts, true ) ) {
                        wp_send_json_error( 'Invalid preset amount selected.' );
                    }
                }
            }
            $data[ $field_name ] = (float) $value;
        } elseif ( 'select' === $definition['type'] ) {
            $value = (string) $value;
            if ( ! array_key_exists( $value, $definition['options'] ) ) {
                wp_send_json_error( 'Invalid selection for ' . $definition['label'] . '.' );
            }
            $data[ $field_name ] = $value;
        } elseif ( 'email' === $definition['type'] ) {
            $data[ $field_name ] = sanitize_email( $value );
        } else {
            $data[ $field_name ] = sanitize_text_field( $value );
        }
    }

    if (
        $is_business_account
        && ! empty( $field_settings['businessAccountName']['enabled'] )
        && ! empty( $field_settings['businessAccountRegName']['enabled'] )
        && ! empty( $data['businessAccountName'] )
        && empty( $data['businessAccountRegName'] )
    ) {
        $data['businessAccountRegName'] = $data['businessAccountName'];
    }
    $data['mandateName'] = $is_business_account
        ? sanitize_text_field( $data['businessAccountName'] ?? '' )
        : trim( sanitize_text_field( $data['firstName'] ?? '' ) . ' ' . sanitize_text_field( $data['surname'] ?? '' ) );
    if ( '' === trim( $data['mandateName'] ) ) {
        wp_send_json_error( 'MandateName is required.' );
    }

    $custom_field_values = array();
    foreach ( $field_definitions as $field_name => $definition ) {
        if ( ! empty( $definition['custom_field'] ) && array_key_exists( $field_name, $data ) ) {
            $custom_field_values[ $field_name ] = $data[ $field_name ];
        }
    }

    unset( $data['isBusinessAccount'], $data['decemberDebitDay'] );
    $data['isIndividual'] = ! $is_business_account;

    $allowed_fields = array( 'accountReference', 'isIndividual', 'mandateName' );
    foreach ( $field_definitions as $field_name => $definition ) {
        if ( ! empty( $definition['system_managed'] ) || ! empty( $definition['custom_field'] ) ) {
            continue;
        }
        if ( ! empty( $field_settings[ $field_name ]['enabled'] ) ) {
            $allowed_fields[] = 'isBusinessAccount' === $field_name ? 'isIndividual' : $field_name;
        }
    }
    $data = array_intersect_key( $data, array_flip( $allowed_fields ) );

    if ( isset( $data['province'] ) && '' !== (string) $data['province'] ) {
        $province_code = debisure_normalize_province_code( $data['province'] );
        if ( null === $province_code ) {
            wp_send_json_error( 'Please select a valid province.' );
        }
        $data['province'] = (string) $province_code;
    }

    if ( empty( $data['accountReference'] ) || ! is_scalar( $data['accountReference'] ) ) {
        wp_send_json_error( 'Account reference is required.' );
    }
    $account_reference = sanitize_text_field( $data['accountReference'] );

    $service_key = debisure_get_service_key();
    $vendor_key  = debisure_get_vendor_key();
    $api_token   = debisure_get_api_token();
    if ( empty( $api_token ) || empty( $service_key ) || empty( $vendor_key ) ) {
        wp_send_json_error( 'Configuration error. Please contact the site admin.' );
    }

    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE account_reference = %s",
        $account_reference
    ) );

    if ( $exists > 0 ) {
        wp_send_json_error( 'Duplicate submission error: This account reference has already been used.' );
    }

    $client_id        = get_option( 'debisure_client_id' );
    if ( empty( $client_id ) ) {
        wp_send_json_error( 'Client ID is not configured in plugin settings.' );
    }

    $data['clientId'] = $client_id;

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
        $mandate_record = array(
            'account_reference'       => $account_reference,
            'mandate_name'            => sanitize_text_field( $data['mandateName'] ?? '' ),
            'is_individual'           => ! empty( $data['isIndividual'] ) ? 1 : 0,
            'first_name'              => sanitize_text_field( $data['firstName'] ?? '' ),
            'surname'                 => sanitize_text_field( $data['surname'] ?? '' ),
            'business_account_name'   => sanitize_text_field( $data['businessAccountName'] ?? '' ),
            'business_account_reg_no' => sanitize_text_field( $data['businessAccountRegNo'] ?? '' ),
            'business_account_reg_name' => sanitize_text_field( $data['businessAccountRegName'] ?? '' ),
            'mobile_no'                 => sanitize_text_field( $data['mobileNo'] ?? '' ),
            'email_address'             => sanitize_email( $data['emailAddress'] ?? '' ),
            'building'                  => sanitize_text_field( $data['building'] ?? '' ),
            'street'                    => sanitize_text_field( $data['street'] ?? '' ),
            'city'                      => sanitize_text_field( $data['city'] ?? '' ),
            'province'                  => sanitize_text_field( $data['province'] ?? '' ),
            'postal_code'               => sanitize_text_field( $data['postalCode'] ?? '' ),
            'debit_day'                 => sanitize_text_field( $data['debitDay'] ?? '' ),
            'amount'                    => (float) ( $data['mandateAmount'] ?? 0 ),
            'agreement_date'            => '',
            'mandate_reference'         => '',
            'reason_for_decline'        => '',
            'mandate_pdf'               => '',
            'status'                    => 'pending',
        );
        $mandate_formats = array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' );
        foreach ( array( 'custom1', 'custom2', 'custom3', 'custom4', 'custom5' ) as $custom_field ) {
            $custom_value = $custom_field_values[ $custom_field ] ?? '';
            $mandate_record[ $custom_field ] = is_bool( $custom_value )
                ? ( $custom_value ? 'true' : 'false' )
                : sanitize_text_field( (string) $custom_value );
            $mandate_formats[] = '%s';
        }

        $inserted = $wpdb->insert( $table_name, $mandate_record, $mandate_formats );

        if ( false === $inserted ) {
            error_log(
                'Debisure mandate was accepted by the API but could not be saved locally for account reference '
                . $account_reference . ': ' . $wpdb->last_error
            );
            wp_send_json_error( 'Debisure accepted the mandate, but this site could not save its local record. Please contact the site administrator before retrying.' );
        }

        wp_send_json_success( json_decode( $response_body, true ) );
    } else {
        wp_send_json_error( 'API error (Code ' . $response_code . '): ' . $response_body );
    }
}