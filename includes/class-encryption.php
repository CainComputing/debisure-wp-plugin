<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function debisure_encrypt_data( $data ) {
    if ( empty( $data ) ) {
        return $data;
    }
    $method = 'AES-256-CBC';
    $key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
    $iv_length = openssl_cipher_iv_length( $method );
    $iv = openssl_random_pseudo_bytes( $iv_length );
    $encrypted = openssl_encrypt( $data, $method, $key, OPENSSL_RAW_DATA, $iv );
    return base64_encode( $iv . $encrypted );
}

function debisure_decrypt_data( $encrypted_data ) {
    if ( empty( $encrypted_data ) ) {
        return $encrypted_data;
    }
    $method = 'AES-256-CBC';
    $key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
    $decoded = base64_decode( $encrypted_data );
    $iv_length = openssl_cipher_iv_length( $method );
    
    if ( strlen( $decoded ) <= $iv_length ) {
        return $encrypted_data;
    }
    
    $iv = substr( $decoded, 0, $iv_length );
    $encrypted_text = substr( $decoded, $iv_length );
    $decrypted = openssl_decrypt( $encrypted_text, $method, $key, OPENSSL_RAW_DATA, $iv );
    
    return $decrypted !== false ? $decrypted : $encrypted_data;
}

function debisure_get_api_token() {
    $encrypted_token = get_option( 'debisure_api_token' );
    return debisure_decrypt_data( $encrypted_token );
}

function debisure_get_service_key() {
    $encrypted = get_option( 'debisure_service_key' );
    return debisure_decrypt_data( $encrypted );
}

function debisure_get_vendor_key() {
    $encrypted = get_option( 'debisure_vendor_key' );
    return debisure_decrypt_data( $encrypted );
}

function debisure_get_recaptcha_settings() {
    $saved = get_option( 'debisure_recaptcha_settings', array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    return array(
        'site_key'   => is_string( $saved['site_key'] ?? null ) ? $saved['site_key'] : '',
        'secret_key' => debisure_decrypt_data( is_string( $saved['secret_key'] ?? null ) ? $saved['secret_key'] : '' ),
    );
}