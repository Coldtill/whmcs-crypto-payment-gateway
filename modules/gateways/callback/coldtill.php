<?php

// Coldtill webhook receiver. Set this file's URL as the project's webhook in Coldtill → Webhooks.

use Coldtill\Whmcs\Core;
use Coldtill\Whmcs\Gateway;

require __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../coldtill/lib/Gateway.php';

$gw = getGatewayVariables(Gateway::MODULE);
if (empty($gw['type'])) {
    die('Module Not Activated');
}

$raw = file_get_contents('php://input') ?: '';
$sig = Core::header($_SERVER, 'SIGNATURE_V2');
$ts  = Core::header($_SERVER, 'TIMESTAMP');
if (!Core::verifySignatureV2($raw, $sig, $ts, (string) $gw['webhook_secret'])) {
    http_response_code(401);
    exit;
}

$event = Core::header($_SERVER, 'EVENT');
$data  = json_decode($raw, true);
if (str_starts_with($event, 'payment.') && is_array($data) && !empty($data['reference_id'])) {
    $ref       = (string) $data['reference_id'];
    $invoiceId = Gateway::invoiceForReference($ref);
    if ($invoiceId > 0) {
        Gateway::apply(
            $gw,
            $invoiceId,
            $ref,
            (string) ($data['status'] ?? ''),
            (string) ($data['amount'] ?? ''),
            (string) ($data['payment_info'][0]['transaction_hash'] ?? ''),
            (string) ($data['invoice_id'] ?? ''),
            ['event' => $event] + $data,
            true
        );
    } elseif (Core::invoiceIdFrom((string) ($data['invoice_id'] ?? '')) > 0 && ($data['status'] ?? '') !== 'OPEN') {
        // An older payment for one of our invoices; logged so a late deposit on it can be found and settled by hand.
        Gateway::log($gw, ['event' => $event] + $data, 'Ignored: not the invoice\'s current Coldtill payment');
    }
}

http_response_code(200);
echo 'OK';
