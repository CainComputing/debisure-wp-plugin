# Debisure

Use the Debisure plugin to accept and manage mandates from your WordPress site.

## Install

1. In WordPress, go to **Plugins > Add New > Upload Plugin**.
2. Select the Debisure plugin ZIP file and choose **Install Now**.
3. Choose **Activate Plugin**.

## Set up the plugin

Open **Debisure > Settings**:

- In **Setup**, enter and save the credentials supplied for your Debisure account.
- In **Form Builder**, choose which customer and mandate fields to display and require, configure the preset and custom amount options, and select the available debit days. At least one debit day must remain selected.
- In **Status**, check the plugin's reported service and scheduled-task status.

## Add the mandate form

Add `[debisure_form]` to the WordPress page where customers should complete a mandate.

## Configure the return page

Create or choose the page customers should see when they return from Debisure, and add `[debisure_return]` to that page. Set the return page in your Debisure client settings.

For testing, a logged-in site administrator can open the return page with `?testid=test`, `?testid=testfail`, or `?testid=testpending` to preview sample successful, failed, or pending mandate details. Use `?testid=MANDATE_REFERENCE` to display a saved mandate. Samples are shown only for preview and are not stored in the database. Customers and other visitors cannot use these test views.

## Manage mandates

Open **Debisure > Mandates** to search and review submitted mandates. Use **Resend** for a pending mandate or **Update** for a completed mandate when you need to submit it again.
