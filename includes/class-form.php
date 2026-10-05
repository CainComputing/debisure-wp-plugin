<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'debisure_form', 'debisure_render_form_shortcode' );

function debisure_default_custom_form_fields() {
    $fields = array();
    for ( $index = 1; $index <= 5; $index++ ) {
        $key = 'custom' . $index;
        $fields[ $key ] = array(
            'label' => 'Custom Field ' . $index,
            'type'  => 'text',
        );
    }
    return $fields;
}

function debisure_sanitize_custom_form_fields( $input ) {
    $defaults = debisure_default_custom_form_fields();
    $input = is_array( $input ) ? $input : array();
    $fields = array();

    foreach ( $defaults as $key => $default ) {
        $submitted = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
        $label = isset( $submitted['label'] ) && is_scalar( $submitted['label'] )
            ? sanitize_text_field( (string) $submitted['label'] )
            : $default['label'];
        $type = isset( $submitted['type'] ) && is_string( $submitted['type'] ) && in_array( $submitted['type'], array( 'text', 'checkbox' ), true )
            ? $submitted['type']
            : $default['type'];

        $fields[ $key ] = array(
            'label' => '' !== $label ? $label : $default['label'],
            'type'  => $type,
        );
    }

    return $fields;
}

function debisure_get_custom_form_fields() {
    return debisure_sanitize_custom_form_fields(
        get_option( 'debisure_custom_form_fields', debisure_default_custom_form_fields() )
    );
}

function debisure_form_field_definitions() {
    $definitions = array(
        'firstName' => array(
            'label'          => 'Name',
            'type'           => 'text',
            'value'          => '',
            'builder_locked' => true,
        ),
        'surname' => array(
            'label'          => 'Surname',
            'type'           => 'text',
            'value'          => '',
            'builder_locked' => true,
        ),
        'mobileNo' => array(
            'label'          => 'Mobile Number',
            'type'           => 'text',
            'value'          => '',
            'builder_locked' => true,
        ),
        'emailAddress' => array(
            'label'          => 'Email',
            'type'           => 'email',
            'value'          => '',
            'builder_locked' => true,
        ),
        'clientId' => array(
            'label'          => 'ClientId',
            'type'           => 'text',
            'system_managed' => true,
            'builder_locked' => true,
            'builder_hidden' => true,
        ),
        'callBackUrl' => array(
            'label'          => 'CallBackUrl',
            'type'           => 'text',
            'system_managed' => true,
            'builder_locked' => true,
            'builder_hidden' => true,
        ),
        'mandateName' => array(
            'label'          => 'MandateName',
            'type'           => 'text',
            'system_managed' => true,
            'builder_locked' => true,
            'builder_hidden' => true,
        ),
        'accountReference' => array(
            'label'          => 'AccountReference',
            'type'           => 'text',
            'system_managed' => true,
            'builder_locked' => true,
            'builder_hidden' => true,
        ),
        'isBusinessAccount' => array(
            'label'   => 'I am signing on behalf of an organisation',
            'type'    => 'checkbox',
            'business_toggle' => true,
        ),
        'businessAccountName' => array(
            'label' => 'Business Name',
            'type'  => 'text',
            'value' => '',
            'business_only' => true,
        ),
        'businessAccountRegNo' => array(
            'label' => 'Business Registration Number',
            'type'  => 'text',
            'value' => '',
            'business_only' => true,
        ),
        'businessAccountRegName' => array(
            'label' => 'Business Registered Name',
            'type'  => 'text',
            'value' => '',
            'description' => 'If left blank, Business Name will be used.',
            'business_only' => true,
        ),
        'building' => array(
            'label' => 'Building',
            'type'  => 'text',
            'value' => '',
        ),
        'street' => array(
            'label' => 'Street',
            'type'  => 'text',
            'value' => '',
        ),
        'city' => array(
            'label' => 'City',
            'type'  => 'text',
            'value' => '',
        ),
        'province' => array(
            'label' => 'Province',
            'type'  => 'select',
            'placeholder' => 'Select a province',
            'options' => array(
                1 => 'Western Cape',
                2 => 'Gauteng',
                3 => 'Eastern Cape',
                4 => 'Free State',
                5 => 'KwaZulu Natal',
                6 => 'Limpopo',
                7 => 'Mpumalanga',
                8 => 'Northern Cape',
                9 => 'North West',
            ),
        ),
        'postalCode' => array(
            'label' => 'Postal Code',
            'type'  => 'text',
            'value' => '',
        ),
        'mandateAmount' => array(
            'label' => 'Amount',
            'type'  => 'amount_radio',
        ),
        'debitDay' => array(
            'label'   => 'Debit Day',
            'type'    => 'select',
            'options' => array(
                'FirstDayOfMonth' => 'First Day Of Month',
                'LastDayOfMonth'  => 'Last Day Of Month',
            ),
        ),
    );

    foreach ( debisure_get_custom_form_fields() as $key => $custom_field ) {
        $definitions[ $key ] = array(
            'label'        => $custom_field['label'],
            'type'         => $custom_field['type'],
            'value'        => '',
            'custom_field' => true,
        );
    }

    return $definitions;
}

function debisure_form_builder_field_order() {
    $definitions = debisure_form_field_definitions();
    $required_order = array();
    foreach ( $definitions as $key => $definition ) {
        if ( ! empty( $definition['builder_locked'] ) && empty( $definition['builder_hidden'] ) ) {
            $required_order[] = $key;
        }
    }
    $selectable_order = array();
    foreach ( $definitions as $key => $definition ) {
        if ( empty( $definition['builder_locked'] ) && empty( $definition['builder_hidden'] ) ) {
            $selectable_order[] = $key;
        }
    }
    return array_merge( $required_order, $selectable_order );
}

function debisure_default_form_debit_days() {
    return array(
        'FirstDayOfMonth' => 'First Day Of Month',
        'LastDayOfMonth'  => 'Last Day Of Month',
    );
}

function debisure_get_form_debit_days() {
    $available = debisure_default_form_debit_days();
    $saved = get_option( 'debisure_form_debit_days', array_keys( $available ) );
    if ( ! is_array( $saved ) ) {
        return $available;
    }

    $selected = array();
    foreach ( $available as $value => $label ) {
        if ( in_array( $value, $saved, true ) ) {
            $selected[ $value ] = $label;
        }
    }

    return $selected ? $selected : $available;
}

function debisure_default_form_amounts() {
    return array(
        'options'       => array( '100', '500', '1000' ),
        'custom_default' => '1000',
    );
}

function debisure_get_form_amounts() {
    $defaults = debisure_default_form_amounts();
    $saved = get_option( 'debisure_form_amounts', $defaults );
    if ( ! is_array( $saved ) || ! isset( $saved['options'], $saved['custom_default'] ) || ! is_array( $saved['options'] ) || 3 !== count( $saved['options'] ) ) {
        return $defaults;
    }

    $amounts = array();
    foreach ( array_values( $saved['options'] ) as $amount ) {
        if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount < 1 ) {
            return $defaults;
        }
        $amounts[] = number_format( (float) $amount, 2, '.', '' );
    }

    if ( count( array_unique( $amounts ) ) !== 3 ) {
        return $defaults;
    }
    if ( ! is_numeric( $saved['custom_default'] ) || ! is_finite( (float) $saved['custom_default'] ) || (float) $saved['custom_default'] < 1 ) {
        return $defaults;
    }

    return array(
        'options'        => $amounts,
        'custom_default' => number_format( (float) $saved['custom_default'], 2, '.', '' ),
    );
}

function debisure_default_form_fields() {
    $defaults = array();
    foreach ( debisure_form_field_definitions() as $key => $definition ) {
        $defaults[ $key ] = array(
            'enabled'  => empty( $definition['custom_field'] ),
            'required' => ! empty( $definition['builder_locked'] )
                || in_array( $key, array( 'mandateAmount' ), true ),
        );
    }
    return $defaults;
}

function debisure_sanitize_form_fields( $input ) {
    $input = is_array( $input ) ? $input : array();
    $fields = array();
    foreach ( debisure_form_field_definitions() as $key => $definition ) {
        $submitted = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
        if ( ! empty( $definition['builder_locked'] ) ) {
            $fields[ $key ] = array(
                'enabled'  => true,
                'required' => true,
            );
            continue;
        }
        if ( ! empty( $definition['custom_field'] ) ) {
            $fields[ $key ] = array(
                'enabled'  => isset( $submitted['enabled'] ) && '1' === (string) $submitted['enabled'],
                'required' => isset( $submitted['required'] ) && '1' === (string) $submitted['required'],
            );
            continue;
        }
        $fields[ $key ] = array(
            'enabled'  => isset( $submitted['enabled'] ) && '1' === (string) $submitted['enabled'],
            'required' => isset( $submitted['required'] ) && '1' === (string) $submitted['required'],
        );
    }
    return $fields;
}

function debisure_get_form_fields() {
    $defaults = debisure_default_form_fields();
    $definitions = debisure_form_field_definitions();
    $saved    = get_option( 'debisure_form_fields', $defaults );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    foreach ( $defaults as $key => $default ) {
        if ( ! empty( $definitions[ $key ]['builder_locked'] ) ) {
            $saved[ $key ] = array(
                'enabled'  => true,
                'required' => true,
            );
            continue;
        }
        if ( ! isset( $saved[ $key ] ) || ! is_array( $saved[ $key ] ) ) {
            $saved[ $key ] = $default;
            continue;
        }
        $saved[ $key ] = array(
            'enabled'  => isset( $saved[ $key ]['enabled'] ) ? (bool) $saved[ $key ]['enabled'] : $default['enabled'],
            'required' => isset( $saved[ $key ]['required'] ) ? (bool) $saved[ $key ]['required'] : $default['required'],
        );
    }

    return $saved;
}

function debisure_generate_account_reference() {
    $prefix = debisure_sanitize_reference_prefix( get_option( 'debisure_reference_prefix', 'WEB' ) );
    return $prefix . '-' . strtoupper( dechex( time() ) ) . '-' . strtoupper( wp_generate_password( 16, false, false ) );
}

function debisure_render_form_shortcode() {
    add_action( 'wp_footer', 'debisure_form_scripts' );
    $stylesheet = DEBISURE_PLUGIN_DIR . 'assets/css/form.css';
    wp_enqueue_style(
        'debisure-form',
        DEBISURE_PLUGIN_URL . 'assets/css/form.css',
        array(),
        file_exists( $stylesheet ) ? (string) filemtime( $stylesheet ) : '1.0'
    );

    $unique_reference = debisure_generate_account_reference();
    $fields = debisure_get_form_fields();
    $definitions = debisure_form_field_definitions();
    $definitions['debitDay']['options'] = debisure_get_form_debit_days();
    $amounts = debisure_get_form_amounts();
    $recaptcha_settings = debisure_get_recaptcha_settings();
    $recaptcha_enabled = '' !== $recaptcha_settings['site_key'] && '' !== $recaptcha_settings['secret_key'];
    if ( $recaptcha_enabled ) {
        wp_enqueue_script(
            'debisure-recaptcha-v3',
            add_query_arg( 'render', $recaptcha_settings['site_key'], 'https://www.google.com/recaptcha/api.js' ),
            array(),
            null,
            true
        );
    }

    ob_start();
    ?>
    <div class="debisure-form-wrapper">
        <form id="debisure-dynamic-form" class="debisure-form">
            <div class="debisure-required-fields-note">
                <span class="debisure-required-fields-asterisk" aria-hidden="true">*</span>
                <span class="debisure-required-fields-text">Required Fields</span>
            </div>
            <div id="debisure-form-messages" style="margin-bottom: 15px;"></div>

            <input type="hidden" id="deb_account_reference" name="accountReference" value="<?php echo esc_attr( $unique_reference ); ?>" />

            <?php foreach ( $definitions as $key => $definition ) : ?>
                <?php if ( ! empty( $definition['system_managed'] ) ) { continue; } ?>
                <?php if ( empty( $fields[ $key ]['enabled'] ) ) { continue; } ?>
                <?php $field_id = 'deb_' . $key; ?>
                <?php if ( ! empty( $definition['business_only'] ) ) : ?>
                    <?php if ( ! isset( $business_fields_open ) ) : ?>
                        <div class="debisure-business-fields" hidden>
                        <?php $business_fields_open = true; ?>
                    <?php endif; ?>
                <?php elseif ( isset( $business_fields_open ) && empty( $definition['business_only'] ) ) : ?>
                    </div>
                    <?php unset( $business_fields_open ); ?>
                <?php endif; ?>
                <?php if ( 'checkbox' === $definition['type'] ) : ?>
                    <div class="debisure-form-row debisure-checkbox-row">
                        <label>
                            <input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" data-debisure-field="<?php echo esc_attr( $key ); ?>" value="true" <?php if ( ! empty( $definition['business_toggle'] ) ) : ?>data-business-account-toggle="1"<?php endif; ?> <?php if ( ! empty( $definition['custom_field'] ) && ! empty( $fields[ $key ]['required'] ) ) : ?>required="required"<?php endif; ?> />
                            <?php echo esc_html( $definition['label'] ); ?>
                            <?php if ( ! empty( $fields[ $key ]['required'] ) ) : ?>
                                <span class="debisure-required-asterisk" aria-hidden="true">*</span>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php else : ?>
                    <div class="debisure-form-row">
                        <label for="<?php echo esc_attr( $field_id ); ?>">
                            <?php echo esc_html( $definition['label'] ); ?>
                            <?php if ( ! empty( $fields[ $key ]['required'] ) ) : ?>
                                <span class="debisure-required-asterisk" aria-hidden="true">*</span>
                            <?php endif; ?>
                        </label>
                        <?php if ( 'amount_radio' === $definition['type'] ) : ?>
                            <div class="debisure-amount-options" data-required="<?php echo ! empty( $fields[ $key ]['required'] ) ? '1' : '0'; ?>">
                                <?php foreach ( $amounts['options'] as $index => $amount ) : ?>
                                    <label>
                                        <input
                                            type="radio"
                                            name="debisure_amount_choice"
                                            value="<?php echo esc_attr( $amount ); ?>"
                                            data-debisure-amount-choice
                                            <?php checked( ! empty( $fields[ $key ]['required'] ) && 0 === $index ); ?>
                                            <?php echo ! empty( $fields[ $key ]['required'] ) ? 'required="required"' : ''; ?>
                                        />
                                        R<?php echo esc_html( rtrim( rtrim( number_format( (float) $amount, 2, '.', '' ), '0' ), '.' ) ); ?>
                                    </label><br />
                                <?php endforeach; ?>
                                <label>
                                    <input
                                        type="radio"
                                        name="debisure_amount_choice"
                                        value="other"
                                        data-debisure-amount-choice
                                        <?php echo ! empty( $fields[ $key ]['required'] ) ? 'required="required"' : ''; ?>
                                    />
                                    Other
                                </label>
                                <div class="debisure-custom-amount" hidden>
                                    <label for="deb_custom_mandate_amount">
                                        Custom Amount (R)
                                        <?php if ( ! empty( $fields[ $key ]['required'] ) ) : ?>
                                            <span class="debisure-required-asterisk" aria-hidden="true">*</span>
                                        <?php endif; ?>
                                    </label>
                                    <input
                                        type="number"
                                        id="deb_custom_mandate_amount"
                                        value="<?php echo esc_attr( $amounts['custom_default'] ); ?>"
                                        min="1"
                                        step="0.01"
                                        data-debisure-custom-amount
                                    />
                                </div>
                            </div>
                        <?php elseif ( 'select' === $definition['type'] ) : ?>
                            <select id="<?php echo esc_attr( $field_id ); ?>" data-debisure-field="<?php echo esc_attr( $key ); ?>" <?php if ( ! empty( $fields[ $key ]['required'] ) ) : ?>data-builder-required="1" required="required"<?php endif; ?>>
                                <?php if ( isset( $definition['placeholder'] ) ) : ?>
                                    <option value="" selected><?php echo esc_html( $definition['placeholder'] ); ?></option>
                                <?php endif; ?>
                                <?php foreach ( $definition['options'] as $option_value => $option_label ) : ?>
                                    <option value="<?php echo esc_attr( $option_value ); ?>"><?php echo esc_html( $option_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else : ?>
                            <input
                                type="<?php echo esc_attr( $definition['type'] ); ?>"
                                id="<?php echo esc_attr( $field_id ); ?>"
                                data-debisure-field="<?php echo esc_attr( $key ); ?>"
                                <?php if ( ! empty( $fields[ $key ]['required'] ) ) : ?>data-builder-required="1"<?php endif; ?>
                                value="<?php echo esc_attr( $definition['value'] ?? '' ); ?>"
                                <?php if ( isset( $definition['step'] ) ) : ?>step="<?php echo esc_attr( $definition['step'] ); ?>"<?php endif; ?>
                                <?php echo $fields[ $key ]['required'] ? 'required="required"' : ''; ?>
                            />
                        <?php endif; ?>
                        <?php if ( isset( $definition['description'] ) ) : ?>
                            <p class="description"><?php echo esc_html( $definition['description'] ); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ( isset( $business_fields_open ) ) : ?>
                </div>
            <?php endif; ?>

            <div class="debisure-form-submit-row">
                <button type="submit" id="debisure-submit-btn" class="debisure-submit-button">Submit Mandate</button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function debisure_form_scripts() {
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('debisure-dynamic-form');
        if (!form) return;

        const businessToggle = form.querySelector('[data-business-account-toggle]');
        const businessFields = form.querySelector('.debisure-business-fields');
        if (businessToggle && businessFields) {
            const updateBusinessFields = function () {
                businessFields.hidden = !businessToggle.checked;
                businessFields.querySelectorAll('[data-builder-required]').forEach(function (field) {
                    field.required = businessToggle.checked;
                });
            };
            businessToggle.addEventListener('change', updateBusinessFields);
            updateBusinessFields();
        }

        const amountOptions = form.querySelector('.debisure-amount-options');
        const customAmount = form.querySelector('.debisure-custom-amount');
        const customAmountInput = form.querySelector('[data-debisure-custom-amount]');
        if (amountOptions && customAmount && customAmountInput) {
            const updateCustomAmount = function () {
                const selected = amountOptions.querySelector('[data-debisure-amount-choice]:checked');
                const isCustom = selected && selected.value === 'other';
                customAmount.hidden = !isCustom;
                customAmountInput.required = Boolean(isCustom && amountOptions.dataset.required === '1');
            };
            amountOptions.addEventListener('change', updateCustomAmount);
            updateCustomAmount();
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = document.getElementById('debisure-submit-btn');
            const msgBox = document.getElementById('debisure-form-messages');
            
            submitBtn.disabled = true;
            submitBtn.innerText = 'Submitting & Redirecting...';
            msgBox.innerHTML = '';

            const formData = {
                accountReference: document.getElementById('deb_account_reference').value
            };
            const recaptchaSiteKey = <?php echo wp_json_encode( $recaptcha_enabled ? $recaptcha_settings['site_key'] : '' ); ?>;
            const selectedAmount = form.querySelector('[data-debisure-amount-choice]:checked');
            if (selectedAmount) {
                if (selectedAmount.value === 'other') {
                    formData.mandateAmount = customAmountInput.value === '' ? null : parseFloat(customAmountInput.value);
                } else {
                    formData.mandateAmount = parseFloat(selectedAmount.value);
                }
            }
            form.querySelectorAll('[data-debisure-field]').forEach(function (field) {
                if (field.type === 'checkbox') {
                    formData[field.dataset.debisureField] = field.checked;
                } else if (field.type === 'number') {
                    formData[field.dataset.debisureField] = field.value === '' ? null : parseFloat(field.value);
                } else {
                    formData[field.dataset.debisureField] = field.value;
                }
            });

            const sendForm = function () {
                fetch('<?php echo esc_url( admin_url('admin-ajax.php?action=debisure_submit_form') ); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const responseData = data.data;
                    
                    const redirectUrl = (responseData && responseData.data && responseData.data.mandateUrl) 
                                        || responseData.mandateUrl 
                                        || responseData.redirectUrl 
                                        || responseData.url;

                    if (redirectUrl) {
                        msgBox.innerHTML = '<div style="padding: 10px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb;">Mandate created! Redirecting to payment gateway...</div>';
                        setTimeout(function() {
                            window.location.href = redirectUrl;
                        }, 1000);
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.innerText = 'Submit Mandate';
                        msgBox.innerHTML = '<div style="padding: 10px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;">Mandate submitted, but no mandate URL was found in the response payload.</div>';
                        console.log('API Success Response Data:', responseData);
                    }
                } else {
                    submitBtn.disabled = false;
                    submitBtn.innerText = 'Submit Mandate';
                    msgBox.innerHTML = '<div style="padding: 10px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;">Error: ' + (data.data || 'Unknown error occurred.') + '</div>';
                }
            })
            .catch(error => {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Submit Mandate';
                msgBox.innerHTML = '<div style="padding: 10px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;">Network error occurred. Please try again.</div>';
                console.error('Error:', error);
            });
            };

            if (recaptchaSiteKey) {
                if (!window.grecaptcha) {
                    submitBtn.disabled = false;
                    submitBtn.innerText = 'Submit Mandate';
                    msgBox.textContent = 'reCAPTCHA could not be loaded. Please try again.';
                    return;
                }
                window.grecaptcha.ready(function () {
                    window.grecaptcha.execute(recaptchaSiteKey, { action: 'mandate_submit' })
                        .then(function (token) {
                            formData.recaptchaToken = token;
                            sendForm();
                        })
                        .catch(function (error) {
                            submitBtn.disabled = false;
                            submitBtn.innerText = 'Submit Mandate';
                            msgBox.textContent = 'reCAPTCHA verification could not be completed. Please try again.';
                            console.error('reCAPTCHA error:', error);
                        });
                });
            } else {
                sendForm();
            }
        });
    });
    </script>
    <?php
}