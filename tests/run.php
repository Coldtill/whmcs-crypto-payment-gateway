<?php

// Tests for the platform-independent part of the module. No WHMCS needed:
//
//   php app/plugins/whmcs/tests/run.php

declare(strict_types=1);

use Coldtill\Whmcs\Client;
use Coldtill\Whmcs\Core;

require __DIR__ . '/../modules/gateways/coldtill/lib/Core.php';

$failed = 0;
$passed = 0;

function check(string $name, bool $ok): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "ok   $name\n";
    } else {
        $failed++;
        echo "FAIL $name\n";
    }
}

function throws(callable $fn, ?int $code = null): bool
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $code === null || $e->getCode() === $code;
    }
    return false;
}

function sign(string $secret, string $ts, string $body): string
{
    return 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
}

/** A fake transport that answers from a queue and records each request. */
final class FakeTransport
{
    public array $calls = [];

    public function __construct(private array $responses)
    {
    }

    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];
        return array_shift($this->responses) ?? [500, ''];
    }
}

// Signatures

$secret = 'whsec_test';
$body   = '{"reference_id":"r1","status":"FILLED","amount":"25"}';
$now    = 1_800_000_000;
$ts     = (string) $now;

check('good signature', Core::verifySignatureV2($body, sign($secret, $ts, $body), $ts, $secret, $now));
check('upper-case hex accepted', Core::verifySignatureV2($body, 'sha256=' . strtoupper(substr(sign($secret, $ts, $body), 7)), $ts, $secret, $now));
check('tampered body rejected', !Core::verifySignatureV2(str_replace('25', '2500', $body), sign($secret, $ts, $body), $ts, $secret, $now));
check('tampered timestamp rejected', !Core::verifySignatureV2($body, sign($secret, $ts, $body), (string) ($now - 1), $secret, $now));
check('wrong secret rejected', !Core::verifySignatureV2($body, sign('other', $ts, $body), $ts, $secret, $now));
check('empty secret rejected', !Core::verifySignatureV2($body, sign('', $ts, $body), $ts, '', $now));
check('missing sha256= prefix rejected', !Core::verifySignatureV2($body, substr(sign($secret, $ts, $body), 7), $ts, $secret, $now));
check('non-numeric timestamp rejected', !Core::verifySignatureV2($body, sign($secret, 'abc', $body), 'abc', $secret, $now));
$old = (string) ($now - 301);
check('stale (301s old) rejected', !Core::verifySignatureV2($body, sign($secret, $old, $body), $old, $secret, $now));
$edge = (string) ($now - 300);
check('300s old still accepted', Core::verifySignatureV2($body, sign($secret, $edge, $body), $edge, $secret, $now));
$future = (string) ($now + 301);
check('301s in the future rejected', !Core::verifySignatureV2($body, sign($secret, $future, $body), $future, $secret, $now));

// Headers

check('X-Coldtill header read', Core::header(['HTTP_X_COLDTILL_SIGNATURE_V2' => 'sha256=a'], 'SIGNATURE_V2') === 'sha256=a');
check('X-Keyhold fallback read', Core::header(['HTTP_X_KEYHOLD_SIGNATURE_V2' => 'sha256=b'], 'SIGNATURE_V2') === 'sha256=b');
check('X-Coldtill preferred over X-Keyhold', Core::header(['HTTP_X_KEYHOLD_TIMESTAMP' => '1', 'HTTP_X_COLDTILL_TIMESTAMP' => '2'], 'TIMESTAMP') === '2');
check('missing header is empty', Core::header([], 'EVENT') === '');
$server = ['HTTP_X_KEYHOLD_SIGNATURE_V2' => sign($secret, $ts, $body), 'HTTP_X_KEYHOLD_TIMESTAMP' => $ts];
check('signature verifies from X-Keyhold headers', Core::verifySignatureV2($body, Core::header($server, 'SIGNATURE_V2'), Core::header($server, 'TIMESTAMP'), $secret, $now));

// Status and amount mapping

check('FILLED pays', Core::action('FILLED', '25', '25.00') === Core::PAY);
check('OVER_FILLED pays', Core::action('OVER_FILLED', '25.00', '25.00') === Core::PAY);
check('FILLED within 0.01 pays', Core::action('FILLED', '25.01', '25.00') === Core::PAY);
check('FILLED off by 0.02 is a mismatch', Core::action('FILLED', '25.02', '25.00') === Core::MISMATCH);
check('FILLED for a different amount is a mismatch', Core::action('FILLED', '10', '25.00') === Core::MISMATCH);
check('FILLED without an amount is a mismatch', Core::action('FILLED', '', '25.00') === Core::MISMATCH);
check('PARTIALLY_FILLED only logs', Core::action('PARTIALLY_FILLED', '25', '25.00') === Core::PARTIAL);
check('CANCELLED closes', Core::action('CANCELLED', '25', '25.00') === Core::CLOSED);
check('REPLACED closes', Core::action('REPLACED', '25', '25.00') === Core::CLOSED);
check('OPEN does nothing', Core::action('OPEN', '25', '25.00') === Core::NONE);
check('unknown state does nothing', Core::action('', '25', '25.00') === Core::NONE);

// Invoice ids, payment request, base URL

check('WHMCS-42 maps to 42', Core::invoiceIdFrom('WHMCS-42') === 42);
check('WC-42 is not ours', Core::invoiceIdFrom('WC-42') === 0);
check('WHMCS-42x is not ours', Core::invoiceIdFrom('WHMCS-42x') === 0);

$req = Core::paymentRequest(7, 'jane@example.com', '10.5', 42, 'https://billing.example.com/');
check('payment request fields', $req === [
    'customerID'    => 'whmcs-client-7',
    'customerEmail' => 'jane@example.com',
    'amountInUSD'   => '10.50',
    'invoiceID'     => 'WHMCS-42',
    'returnURL'     => 'https://billing.example.com/viewinvoice.php?id=42',
]);
check('no returnURL on an http system URL', !isset(Core::paymentRequest(7, '', '1', 42, 'http://billing.example.com')['returnURL']));

check('https base URL allowed', Core::isAllowedBaseUrl('https://pay.example.com'));
check('http localhost allowed', Core::isAllowedBaseUrl('http://localhost:8080'));
check('http 127.0.0.1 allowed', Core::isAllowedBaseUrl('http://127.0.0.1:8080'));
check('http remote host refused', !Core::isAllowedBaseUrl('http://pay.example.com'));
check('URL with credentials refused', !Core::isAllowedBaseUrl('https://u:p@pay.example.com'));
check('empty URL refused', !Core::isAllowedBaseUrl(''));
check('client refuses http remote host', throws(fn () => new Client('http://pay.example.com', 'k', new FakeTransport([]))));

// Pay token

$exp = (string) ($now + 3600);
$tok = Core::payToken('key', 42, (int) $exp);
check('pay token valid', Core::verifyPayToken('key', 42, $exp, $tok, $now));
check('pay token for another invoice refused', !Core::verifyPayToken('key', 43, $exp, $tok, $now));
check('pay token with changed expiry refused', !Core::verifyPayToken('key', 42, (string) ($now + 7200), $tok, $now));
check('expired pay token refused', !Core::verifyPayToken('key', 42, $exp, $tok, $now + 3601));
check('pay token with another key refused', !Core::verifyPayToken('other', 42, $exp, $tok, $now));
check('pay token with empty key refused', !Core::verifyPayToken('', 42, $exp, Core::payToken('', 42, (int) $exp), $now));

// API client

$ok = [200, '{"host":"https://pay.example.com","reference_id":"ref-1","url":"https://pay.example.com/pay/ref-1"}'];

$t   = new FakeTransport([$ok]);
$res = (new Client('https://pay.example.com/', 'key-1', $t))->createPayment($req);
check('create returns the reference', $res['reference_id'] === 'ref-1');
check('create posts to /api/v1/payment', $t->calls[0]['method'] === 'POST' && $t->calls[0]['url'] === 'https://pay.example.com/api/v1/payment');
check('create sends API-Key and JSON', $t->calls[0]['headers']['API-Key'] === 'key-1' && $t->calls[0]['headers']['Content-Type'] === 'application/json');
check('create sends the request body', $t->calls[0]['body'] === $req);

$t   = new FakeTransport([[400, '{"error":"returnURL host billing.example.com is not in this project\'s allowed return hosts"}'], $ok]);
$res = (new Client('https://pay.example.com', 'k', $t))->createPayment($req);
check('returnURL 400 retried once', count($t->calls) === 2 && $res['reference_id'] === 'ref-1');
check('retry drops only returnURL', !isset($t->calls[1]['body']['returnURL']) && $t->calls[1]['body']['invoiceID'] === 'WHMCS-42');

$t = new FakeTransport([[400, '{"error":"returnURL must be an https:// URL"}'], [400, '{"error":"returnURL must be an https:// URL"}']]);
check('second returnURL 400 is not retried again', throws(fn () => (new Client('https://pay.example.com', 'k', $t))->createPayment($req), 400) && count($t->calls) === 2);

$t = new FakeTransport([[400, '{"error":"amountInUSD must be between 0 and 10,000,000"}']]);
check('other 400 not retried', throws(fn () => (new Client('https://pay.example.com', 'k', $t))->createPayment($req), 400) && count($t->calls) === 1);

$noReturn = $req;
unset($noReturn['returnURL']);
$t = new FakeTransport([[400, '{"error":"returnURL must be an https:// URL"}']]);
check('no retry when no returnURL was sent', throws(fn () => (new Client('https://pay.example.com', 'k', $t))->createPayment($noReturn), 400) && count($t->calls) === 1);

$t = new FakeTransport([[409, '{"error":"a deposit is still confirming"}']]);
check('409 surfaces with its code', throws(fn () => (new Client('https://pay.example.com', 'k', $t))->createPayment($req), 409));

$t = new FakeTransport([[502, '<html>Bad gateway</html>']]);
check('non-JSON error is reported by status', throws(fn () => (new Client('https://pay.example.com', 'k', $t))->createPayment($req), 502));

$t = new FakeTransport([[200, '{}']]);
check('response without reference_id refused', throws(fn () => (new Client('https://pay.example.com', 'k', $t))->createPayment($req)));

$t = new FakeTransport([[200, '{"paymentState":"FILLED","amountInUSD":"25","deposits":[{"transactionHash":"0xabc"}]}']]);
$p = (new Client('https://pay.example.com/coldtill', 'k', $t))->getPayment('ref/1');
check('get payment path keeps the base path and encodes the reference', $t->calls[0]['method'] === 'GET' && $t->calls[0]['url'] === 'https://pay.example.com/coldtill/api/v1/payment/reference/ref%2F1');
check('get payment sends no body', $t->calls[0]['body'] === null && !isset($t->calls[0]['headers']['Content-Type']));
check('get payment fields', $p['paymentState'] === 'FILLED' && $p['deposits'][0]['transactionHash'] === '0xabc');

$down = function () {
    throw new RuntimeException('Coldtill is unreachable: timeout');
};
check('unreachable server throws', throws(fn () => (new Client('https://pay.example.com', 'k', $down))->getPayment('r')));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
