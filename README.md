# Debisure Integration

Integrates Debisure services with WordPress.

## Description

This plugin provides a seamless integration between Debisure services and your WordPress website.

* Connects your WordPress site to the Debisure API securely.
* Automates background synchronization of services.
* Provides full management directly from your WordPress dashboard.

## Installation

1. Download the latest release `.zip` file from the GitHub repository.
2. Log in to your WordPress admin panel and navigate to **Plugins > Add New > Upload Plugin**.
3. Choose the downloaded zip file and click **Install Now**.
4. Click **Activate Plugin**.

## Form configuration

Open **Debisure > Settings**. The **Form Builder** tab lets you show or hide the supported mandate fields, choose which fields are required, configure three distinct preset amounts and the default custom amount, and choose which debit day options are available (at least one must be selected). Custom customer-entered amounts are accepted independently of the presets. Use **Setup** to manage credentials and the account-reference prefix. The callback and redirect URLs are managed in the Debisure client record and are not included in mandate API requests. Configure the client return page to contain the `[debisure_thankyou]` shortcode. On return, Debisure should POST only the `ref` field; the shortcode looks up that reference in the local database and displays the saved record. The return page may initially show `pending` if the asynchronous webhook has not yet updated the status. The **Status** tab reports cron type and Debisure service health. The form is displayed with the `[debicheck_form]` shortcode.

The **Debisure > Mandates** page lists submitted debit orders with search and pagination. Administrators can resubmit a pending mandate with **Resend**, or a completed mandate with **Update**; both buttons rebuild the API request from the saved database fields, reuse the existing account reference, and do not create a duplicate local row or redirect to the payment portal.

The thank-you shortcode outputs unstyled semantic markup with `debisure-thankyou-*` CSS classes so the layout can be customized with a theme, CSS, or page builder. When the webhook has supplied a mandate PDF URL, the return page displays a **View eMandate** link.

## Webhook contract

Debisure should POST a JSON object to the configured `webhookUrl` with this shape:

```json
{
  "eventId": "unique-event-id",
  "accountReference": "WEB-...",
  "status": "success",
  "isIndividual": true,
  "firstName": "Jane",
  "surname": "Example",
  "businessAccountName": "",
  "businessAccountRegNo": "",
  "businessAccountRegName": "",
  "mobileNo": "0821234567",
  "emailAddress": "jane@example.com",
  "building": "Unit 1",
  "street": "Main Road",
  "city": "Cape Town",
  "province": "Western Cape",
  "postalCode": "8001",
  "debitDay": "FirstDayOfMonth",
  "mandateAmount": 100.00,
  "agreementDate": "2026-10-03",
  "mandateReference": "DEBI-...",
  "reasonForDecline": "",
  "mandatePdf": "https://example.com/mandate.pdf"
}
```

`status` must be `success` or `failed`, and `accountReference` identifies the existing row without changing it. The callback also accepts the bridge's snake_case names, including `account_reference`, `mandate_name`, `amount`, `first_name`, `business_account_name`, `agreement_date`, and `mandate_pdf`. Amounts may use a decimal point or a comma decimal separator (for example, `100,0000` is interpreted as `100.0000`). Optional fields are updated only when their values are nonblank; blank values and omitted fields leave saved data unchanged. `mandate_name` updates the existing mandate-name field and is used for the Mandates list and future resubmissions. Normal form submissions set it to the person's first and last name or the business name. URL/configuration fields such as `clientId`, `CallBackUrl`, `redirectUrl`, `returnUrl`, `webhookUrl`, and `redirect_url` are not mandate database fields and are ignored by the webhook.

The endpoint accepts JSON or form-encoded bodies, but both formats must still include the authentication headers below. The signature is calculated from the exact raw request body. `eventId` is optional.

Authenticate webhook requests with these headers:

- `X-Debisure-Timestamp`: Unix timestamp in seconds.
- `X-Debisure-Signature`: lowercase hexadecimal HMAC-SHA256 of `<timestamp>.<exact raw JSON request body>`, using the client's Debisure API key as the HMAC key.

The plugin rejects timestamps more than five minutes from its local clock and verifies the signature with a constant-time comparison. Keep the API key private and send the webhook over HTTPS. A server-side relay must add these headers; an unsigned request will be rejected.

Webhook debugging is enabled by default. Each request, authentication result, and processing result is appended as a JSON line to `webhook-debug.log` in the plugin directory. The complete request body, query parameters, and all request headers are logged, including authorization and cookie headers. This may include credentials and customer data; restrict filesystem access and remove the log when troubleshooting is complete. To choose a different writable path, define `DEBISURE_WEBHOOK_DEBUG_LOG` in `wp-config.php`; define it as `false` to disable this logging.

For a non-mutating delivery/authentication check, run `php tools/test-webhook.php` from the plugin directory with `DEBISURE_WEBHOOK_URL` set to the HTTPS webhook endpoint and `DEBISURE_WEBHOOK_API_KEY` set to the same API token configured in WordPress. The test intentionally sends an invalid status and expects `invalid_callback_status`, which confirms authentication passed without changing a mandate. It uses PHP cURL when available and otherwise falls back to HTTPS streams, which require `allow_url_fopen`.

The four columns are included when the table is created or updated by the plugin's activation routine. Existing installations that do not reactivate/run the schema routine must add them manually. For this installation's `wpxy_` table prefix, run:

```sql
ALTER TABLE wpxy_debisure
    ADD COLUMN agreement_date varchar(50) NOT NULL DEFAULT '',
    ADD COLUMN mandate_reference varchar(100) NOT NULL DEFAULT '',
    ADD COLUMN reason_for_decline text NOT NULL,
    ADD COLUMN mandate_pdf text NOT NULL;
```

Pending mandates that remain unresolved for more than 48 hours are automatically marked `incomplete` by an hourly WordPress Cron task.

The **Status** tab reports the detected cron type and checks Debisure API health at `https://api.debisure.com/api/v1/health` and callback health at `https://debisure.com/v1/health/`.