<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'debisure_thankyou', 'debisure_render_thankyou_shortcode' );

function debisure_render_thankyou_shortcode() {
    // Capture POST data from the redirect, with fallback to GET if refreshed
    $data = ! empty( $_POST ) ? $_POST : $_GET;

    $status             = sanitize_text_field( $data['status'] ?? 'unknown' );
    $ref                = sanitize_text_field( $data['ref'] ?? ( $data['MandateReferenceNumber'] ?? 'N/A' ) );
    $first_name         = sanitize_text_field( $data['FirstName'] ?? '' );
    $last_name          = sanitize_text_field( $data['LastName'] ?? '' );
    $amount             = sanitize_text_field( $data['Amount'] ?? ( $data['DefaultAmount'] ?? '' ) );
    $mandate_successful = sanitize_text_field( $data['MandateSuccessful'] ?? '' );
    $reason_for_decline = sanitize_text_field( $data['ReasonForDecline'] ?? '' );
    $mandate_pdf_link   = esc_url_raw( $data['MandatePDFLink'] ?? '' );

    $is_success = ( $status === 'success' || $mandate_successful == '1' || strtolower( $mandate_successful ) === 'true' );

    ob_start();
    ?>
    <div class="debisure-thankyou-wrapper" style="font-family: 'Inter', sans-serif; background-color: #051320; color: #cbd5e1; padding: 40px 20px; border-radius: 12px; max-width: 600px; margin: 40px auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);">
        <div style="text-align: center; margin-bottom: 30px;">
            <?php if ( $is_success ) : ?>
                <div style="width: 70px; height: 70px; background: rgba(0, 208, 132, 0.1); border: 2px solid #00D084; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto;">
                    <span style="color: #00D084; font-size: 32px; font-weight: bold;">&#10003;</span>
                </div>
                <h2 style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 10px;">Mandate Successful!</h2>
                <p style="color: #8892B0; font-size: 14px;">Your DebiCheck mandate has been successfully processed and verified.</p>
            <?php else : ?>
                <div style="width: 70px; height: 70px; background: rgba(239, 68, 68, 0.1); border: 2px solid #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto;">
                    <span style="color: #ef4444; font-size: 32px; font-weight: bold;">&#10005;</span>
                </div>
                <h2 style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 10px;">Mandate Processing Issue</h2>
                <p style="color: #8892B0; font-size: 14px;"><?php echo ! empty( $reason_for_decline ) ? esc_html( $reason_for_decline ) : 'The mandate was either declined or could not be completed.'; ?></p>
            <?php endif; ?>
        </div>

        <div style="background: #030b13; border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 20px; margin-bottom: 25px;">
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
                <span style="color: #8892B0; font-size: 13px;">Reference Number</span>
                <span style="color: #ffffff; font-family: monospace; font-weight: 600;"><?php echo esc_html( $ref ); ?></span>
            </div>
            <?php if ( ! empty( $first_name ) || ! empty( $last_name ) ) : ?>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
                <span style="color: #8892B0; font-size: 13px;">Account Holder</span>
                <span style="color: #ffffff; font-weight: 500;"><?php echo esc_html( $first_name . ' ' . $last_name ); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( ! empty( $amount ) ) : ?>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
                <span style="color: #8892B0; font-size: 13px;">Mandate Amount</span>
                <span style="color: #00D4FF; font-weight: 600;">R <?php echo number_format( (float)$amount, 2 ); ?></span>
            </div>
            <?php endif; ?>
            <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                <span style="color: #8892B0; font-size: 13px;">Status</span>
                <span style="text-transform: uppercase; font-size: 12px; font-weight: 700; color: <?php echo $is_success ? '#00D084' : '#ef4444'; ?>;"><?php echo esc_html( $status ); ?></span>
            </div>
        </div>

        <?php if ( ! empty( $mandate_pdf_link ) ) : ?>
            <div style="text-align: center;">
                <a href="<?php echo esc_url( $mandate_pdf_link ); ?>" target="_blank" style="display: inline-block; background: #00D4FF; color: #0A2540; font-weight: 600; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-size: 14px;">Download Mandate PDF</a>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}