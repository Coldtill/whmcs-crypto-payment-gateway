<?php

// The pay step: the invoice's pay button posts here, and this creates the Coldtill payment and sends the customer
// to its checkout. The signed token from the button limits it to invoices whose page the visitor was shown.

use Coldtill\Whmcs\Core;
use Coldtill\Whmcs\Gateway;

require __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/Gateway.php';

function coldtill_pay_page(int $code, string $message, string $invoiceUrl = ''): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $link = $invoiceUrl !== '' ? '<p><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES) . '">Back to the invoice</a></p>' : '';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Crypto payment</title>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>' . $link;
    exit;
}

function coldtill_redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

$gw = getGatewayVariables(Gateway::MODULE);
if (empty($gw['type'])) {
    coldtill_pay_page(404, 'Crypto payment is not available.');
}

$invoiceId  = (int) ($_POST['invoice_id'] ?? 0);
$invoiceUrl = Core::invoiceUrl((string) \WHMCS\Config\Setting::getValue('SystemURL'), $invoiceId);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !Core::verifyPayToken((string) $gw['api_key'], $invoiceId, (string) ($_POST['expires'] ?? ''), (string) ($_POST['coldtill_token'] ?? ''))) {
    coldtill_pay_page(403, 'This payment link has expired. Open the invoice again and choose Pay now.', $invoiceId > 0 ? $invoiceUrl : '');
}

$inv = Gateway::invoice($invoiceId);
if (!$inv || $inv['status'] !== 'Unpaid' || (float) $inv['balance'] <= 0) {
    coldtill_redirect($invoiceUrl);
}
if ($inv['currency'] !== 'USD') {
    coldtill_pay_page(400, 'Crypto payment is only available for invoices in USD.', $invoiceUrl);
}
$balance = Core::money((string) $inv['balance']);

try {
    $client = Gateway::client($gw);

    // Clicking Pay again (or coming back after a partial payment) keeps the open payment instead of replacing it.
    $row = Gateway::referenceFor($invoiceId);
    if ($row && $row['amount'] === $balance) {
        try {
            $p     = $client->getPayment($row['reference_id']);
            $state = (string) ($p['paymentState'] ?? '');
            if ($state === 'OPEN' || $state === 'PARTIALLY_FILLED') {
                coldtill_redirect((string) ($p['url'] ?? $row['url']));
            }
            if ($state === 'FILLED' || $state === 'OVER_FILLED') {
                Gateway::sync($gw, $invoiceId);
                coldtill_redirect($invoiceUrl);
            }
        } catch (RuntimeException $e) {
            // Fall through and create a new payment.
        }
    }

    $req = Core::paymentRequest((int) $inv['userid'], $inv['email'], $balance, $invoiceId, (string) \WHMCS\Config\Setting::getValue('SystemURL'));
    $res = $client->createPayment($req);
} catch (RuntimeException $e) {
    Gateway::log($gw, ['invoice' => $invoiceId, 'error' => $e->getMessage()], 'Payment not created');
    if ($e->getCode() === 409) {
        coldtill_pay_page(409, 'Your previous crypto payment is still confirming. Please try again in a few minutes.', $invoiceUrl);
    }
    coldtill_pay_page(502, 'Crypto payment is unavailable right now. Please try again or choose another method.', $invoiceUrl);
}

Gateway::saveReference($invoiceId, (string) $res['reference_id'], (string) $res['url'], $balance);
Gateway::log($gw, ['invoice' => $invoiceId, 'reference_id' => $res['reference_id'], 'amountInUSD' => $balance], 'Awaiting crypto payment');
coldtill_redirect((string) $res['url']);
