<?php

// Coldtill for WHMCS: self-custody crypto payments through your own Coldtill install.

use Coldtill\Whmcs\Core;
use Coldtill\Whmcs\Gateway;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('COLDTILL_WHMCS_VERSION')) {
    define('COLDTILL_WHMCS_VERSION', '1.0.0');
}

require_once __DIR__ . '/coldtill/lib/Gateway.php';

function coldtill_MetaData()
{
    return [
        'DisplayName' => 'Coldtill (crypto)',
        'APIVersion'  => '1.1',
    ];
}

function coldtill_config()
{
    $systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');

    return [
        'FriendlyName'   => ['Type' => 'System', 'Value' => 'Coldtill (crypto)'],
        'base_url'       => [
            'FriendlyName' => 'Coldtill URL',
            'Type'         => 'text',
            'Size'         => '50',
            'Description'  => 'For example https://pay.example.com. Must be https (http is allowed for localhost only).',
        ],
        'api_key'        => [
            'FriendlyName' => 'API key',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'Project API key from Coldtill → API keys.',
        ],
        'webhook_secret' => [
            'FriendlyName' => 'Webhook secret',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'In Coldtill → Webhooks, set the URL to <code>'
                . htmlspecialchars($systemUrl . '/modules/gateways/callback/coldtill.php', ENT_QUOTES)
                . '</code> and paste the signing secret here.',
        ],
    ];
}

/** Refuses a non-https Coldtill URL on save, where WHMCS calls this; the client refuses it at payment time anyway. */
function coldtill_config_validate(array $params)
{
    $url = trim((string) ($params['base_url'] ?? ''));
    if ($url !== '' && !Core::isAllowedBaseUrl($url)) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration('Coldtill URL must start with https:// so the API key is not sent in the clear.');
    }
}

/**
 * Shows the pay button. Nothing is created here: the button posts to coldtill/pay.php, which creates the Coldtill
 * payment only when the customer clicks. When the invoice already has a Coldtill payment (the customer is coming
 * back from the checkout), it is re-checked in case its webhook was missed.
 */
function coldtill_link($params)
{
    $invoiceId = (int) $params['invoiceid'];
    if (($params['currency'] ?? '') !== 'USD') {
        return '<p>Crypto payment is only available for invoices in USD.</p>';
    }
    if (empty($params['api_key']) || !Core::isAllowedBaseUrl((string) ($params['base_url'] ?? ''))) {
        return '<p>Crypto payment is not available right now.</p>';
    }

    if (Gateway::sync($params, $invoiceId)) {
        // The page was rendered before the payment was added; reload it so it shows the invoice as paid.
        $url = htmlspecialchars(Core::invoiceUrl((string) $params['systemurl'], $invoiceId), ENT_QUOTES);
        return '<p>Crypto payment received. <a href="' . $url . '">Refresh the invoice</a>.</p>'
            . '<script>window.location.reload();</script>';
    }

    // WHMCS adds its own CSRF "token" field to client area forms, so ours has a different name.
    $expires = time() + 86400;
    $action  = rtrim((string) $params['systemurl'], '/') . '/modules/gateways/coldtill/pay.php';
    $h       = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

    return '<form method="post" action="' . $h($action) . '">'
        . '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">'
        . '<input type="hidden" name="expires" value="' . $expires . '">'
        . '<input type="hidden" name="coldtill_token" value="' . $h(Core::payToken((string) $params['api_key'], $invoiceId, $expires)) . '">'
        . '<input type="submit" class="btn btn-success" value="' . $h($params['langpaynow'] ?? 'Pay now') . '">'
        . '</form>';
}
