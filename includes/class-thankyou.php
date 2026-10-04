<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'debisure_return', 'debisure_render_thankyou_shortcode' );

add_action( 'wp_enqueue_scripts', function () {
    $stylesheet = DEBISURE_PLUGIN_DIR . 'assets/css/thankyou.css';
    wp_enqueue_style(
        'debisure-return',
        DEBISURE_PLUGIN_URL . 'assets/css/thankyou.css',
        array(),
        file_exists( $stylesheet ) ? (string) filemtime( $stylesheet ) : '1.0'
    );
} );

function debisure_render_thankyou_shortcode() {
    global $wpdb;

    $is_test_request = isset( $_GET['testid'] );
    $test_access_denied = $is_test_request && ! current_user_can( 'manage_options' );
    if ( $is_test_request ) {
        $reference = is_scalar( $_GET['testid'] )
            ? sanitize_text_field( (string) wp_unslash( $_GET['testid'] ) )
            : '';
    } else {
        $reference = isset( $_POST['ref'] ) && is_scalar( $_POST['ref'] )
            ? sanitize_text_field( (string) wp_unslash( $_POST['ref'] ) )
            : '';
    }
    if ( strlen( $reference ) > 100 ) {
        $reference = '';
    }
    $mandate = null;
    $test_case = strtolower( $reference );
    $is_admin_preview = ! $is_test_request
        && '' === $reference
        && current_user_can( 'manage_options' );
    if ( $is_admin_preview ) {
        $test_case = 'test';
    }
    $mock_statuses = array(
        'test'        => 'success',
        'testfail'    => 'failed',
        'testpending' => 'pending',
    );
    $is_mock_preview = false;
    $can_view_mock = $is_admin_preview || ( $is_test_request && ! $test_access_denied );
    if ( isset( $mock_statuses[ $test_case ] ) && $can_view_mock ) {
        $mandate = (object) array(
            'account_reference'     => 'TEST-DEBISURE-' . strtoupper( $test_case ),
            'is_individual'         => 1,
            'first_name'            => 'Jane',
            'surname'               => 'Example',
            'business_account_name' => '',
            'amount'                => '1250.00',
            'status'                => $mock_statuses[ $test_case ],
            'mandate_pdf'           => 'https://debisure.com/',
        );
        $is_mock_preview = true;
    } elseif ( '' !== $reference && ! $test_access_denied ) {
        $table_name = $wpdb->prefix . 'debisure';
        $mandate = $wpdb->get_row( $wpdb->prepare(
            "SELECT account_reference, is_individual, first_name, surname, business_account_name, amount, status, mandate_pdf
            FROM $table_name
            WHERE account_reference = %s",
            $reference
        ) );

        if ( null === $mandate && ! empty( $wpdb->last_error ) ) {
            error_log( 'Debisure thank-you lookup failed: ' . $wpdb->last_error );
        } elseif ( null === $mandate ) {
            error_log( 'Debisure thank-you lookup found no mandate row for the submitted reference.' );
        }
    }

    $status = $mandate ? strtolower( sanitize_text_field( (string) $mandate->status ) ) : '';
    $is_success = 'success' === $status;
    $is_failed = in_array( $status, array( 'failed', 'incomplete' ), true );
    $status_class = $is_success ? 'success' : ( $is_failed ? 'issue' : 'pending' );
    $account_holder = '';
    if ( $mandate ) {
        $account_holder = ! empty( $mandate->is_individual )
            ? trim( $mandate->first_name . ' ' . $mandate->surname )
            : (string) $mandate->business_account_name;
    }

    ob_start();
    ?>
    <section class="debisure-thankyou debisure-thankyou--<?php echo esc_attr( $status_class ); ?>">
        <header class="debisure-thankyou-header">
            <?php if ( $test_access_denied ) : ?>
                <h2 class="debisure-thankyou-title">Test View Unavailable</h2>
                <p class="debisure-thankyou-message">Test access is restricted to site administrators.</p>
            <?php else : ?>
                <?php if ( $is_mock_preview ) : ?>
                    <p class="debisure-thankyou-message">Test preview using sample mandate details.</p>
                <?php endif; ?>
                <?php if ( ! $mandate ) : ?>
                    <h2 class="debisure-thankyou-title">Mandate Details Unavailable</h2>
                    <p class="debisure-thankyou-message">
                        <?php echo '' === $reference
                            ? 'The mandate reference was not provided.'
                            : 'No mandate record could be found for the provided reference.'; ?>
                    </p>
                <?php elseif ( $is_success ) : ?>
                    <span class="debisure-thankyou-icon debisure-thankyou-icon--success" aria-hidden="true">&#10003;</span>
                    <h2 class="debisure-thankyou-title">Mandate Successful!</h2>
                    <p class="debisure-thankyou-message">Your mandate has been successfully processed and verified.</p>
                <?php elseif ( $is_failed ) : ?>
                    <span class="debisure-thankyou-icon debisure-thankyou-icon--issue" aria-hidden="true">&#10005;</span>
                    <h2 class="debisure-thankyou-title">Mandate Processing Issue</h2>
                    <p class="debisure-thankyou-message"><?php echo 'incomplete' === $status ? 'The mandate could not be completed.' : 'The mandate was declined or could not be completed.'; ?></p>
                <?php else : ?>
                    <span class="debisure-thankyou-icon debisure-thankyou-icon--pending" aria-hidden="true">&#8230;</span>
                    <h2 class="debisure-thankyou-title">Mandate Processing</h2>
                    <p class="debisure-thankyou-message">Your mandate has been received. Its final status is still being confirmed.</p>
                <?php endif; ?>
            <?php endif; ?>
        </header>

        <?php if ( $mandate ) : ?>
            <dl class="debisure-thankyou-details">
                <div class="debisure-thankyou-detail debisure-thankyou-detail--reference">
                    <dt class="debisure-thankyou-label">Reference Number</dt>
                    <dd class="debisure-thankyou-value"><?php echo esc_html( $mandate->account_reference ); ?></dd>
                </div>
                <?php if ( '' !== $account_holder ) : ?>
                    <div class="debisure-thankyou-detail debisure-thankyou-detail--account-holder">
                        <dt class="debisure-thankyou-label">Account Holder</dt>
                        <dd class="debisure-thankyou-value"><?php echo esc_html( $account_holder ); ?></dd>
                    </div>
                <?php endif; ?>
                <div class="debisure-thankyou-detail debisure-thankyou-detail--amount">
                    <dt class="debisure-thankyou-label">Mandate Amount</dt>
                    <dd class="debisure-thankyou-value">R <?php echo esc_html( number_format( (float) $mandate->amount, 2 ) ); ?></dd>
                </div>
                <div class="debisure-thankyou-detail debisure-thankyou-detail--status">
                    <dt class="debisure-thankyou-label">Status</dt>
                    <dd class="debisure-thankyou-value"><?php echo esc_html( $status ); ?></dd>
                </div>
                <?php if ( ! empty( $mandate->mandate_pdf ) ) : ?>
                    <div class="debisure-thankyou-detail debisure-thankyou-detail--emandate">
                        <dt class="debisure-thankyou-label">eMandate</dt>
                        <dd class="debisure-thankyou-value">
                            <a href="<?php echo esc_url( $mandate->mandate_pdf ); ?>" target="_blank" rel="noopener noreferrer">View eMandate</a>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}