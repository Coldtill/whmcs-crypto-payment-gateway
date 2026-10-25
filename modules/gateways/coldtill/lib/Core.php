<?php

// Platform-independent parts of the Coldtill gateway. Nothing in this file may depend on WHMCS, so tests/run.php
// can load it on its own.

namespace Coldtill\Whmcs;

use RuntimeException;

final class Core
{
    public const PAY      = 'pay';
    public const PARTIAL  = 'partial';
    public const CLOSED   = 'closed';
    public const MISMATCH = 'mismatch';
    public const NONE     = 'none';

    public const INVOICE_PREFIX  = 'WHMCS-';
    public const CUSTOMER_PREFIX = 'whmcs-client-';

    /** Only https keeps the API key private on the way to Coldtill; plain http is accepted for localhost testing. */
    public static function isAllowedBaseUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        return $scheme === 'https' || ($scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true));
    }

    /** Verifies the timestamped X-Coldtill-Signature-V2 header and rejects deliveries older than five minutes. */
    public static function verifySignatureV2(string $rawBody, string $header, string $timestamp, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || !str_starts_with($header, 'sha256=') || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > 300) {
            return false;
        }
        $want = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($want, strtolower(trim($header)));
    }

    /** Servers send each header as X-Coldtill-* and as the pre-rename X-Keyhold-*. $name is e.g. SIGNATURE_V2. */
    public static function header(array $server, string $name): string
    {
        foreach (['HTTP_X_COLDTILL_', 'HTTP_X_KEYHOLD_'] as $prefix) {
            if (isset($server[$prefix . $name]) && is_string($server[$prefix . $name])) {
                return trim($server[$prefix . $name]);
            }
        }
        return '';
    }

    /** What to do with a Coldtill payment state, given the amount it was created for and the invoice balance now. */
    public static function action(string $status, string $amount, string $balance): string
    {
        switch ($status) {
            case 'FILLED':
            case 'OVER_FILLED':
                // A payment is only applied when it was created for what the invoice still owes.
                return self::sameAmount($amount, $balance) ? self::PAY : self::MISMATCH;
            case 'PARTIALLY_FILLED':
                return self::PARTIAL;
            case 'CANCELLED':
            case 'REPLACED':
                return self::CLOSED;
        }
        return self::NONE;
    }

    /** Equal within one cent, compared in whole cents so 0.01 itself is not lost to float rounding. */
    public static function sameAmount(string $a, string $b): bool
    {
        if (!is_numeric($a) || !is_numeric($b)) {
            return false;
        }
        return abs((int) round((float) $a * 100) - (int) round((float) $b * 100)) <= 1;
    }

    public static function money(string|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** Coldtill allows one open payment per customer id; WHMCS invoices always belong to a client. */
    public static function paymentRequest(int $clientId, string $email, string $balance, int $invoiceId, string $systemUrl): array
    {
        $req = [
            'customerID'    => self::CUSTOMER_PREFIX . $clientId,
            'customerEmail' => $email,
            'amountInUSD'   => self::money($balance),
            'invoiceID'     => self::INVOICE_PREFIX . $invoiceId,
        ];
        // The checkout's "Return to store" button brings the customer back to the invoice, which also re-checks it.
        $return = self::invoiceUrl($systemUrl, $invoiceId);
        if (str_starts_with($return, 'https://')) {
            $req['returnURL'] = $return;
        }
        return $req;
    }

    public static function invoiceUrl(string $systemUrl, int $invoiceId): string
    {
        return rtrim($systemUrl, '/') . '/viewinvoice.php?id=' . $invoiceId;
    }

    /** The invoice id in "WHMCS-123", or 0 when the value is not one of ours. */
    public static function invoiceIdFrom(string $coldtillInvoiceId): int
    {
        if (!preg_match('/^' . preg_quote(self::INVOICE_PREFIX, '/') . '([1-9][0-9]*)$/', $coldtillInvoiceId, $m)) {
            return 0;
        }
        return (int) $m[1];
    }

    /**
     * The pay button posts this token so the pay step only creates payments for invoices whose page the visitor
     * was shown. It is keyed with the API key, which never leaves the server.
     */
    public static function payToken(string $key, int $invoiceId, int $expires): string
    {
        return hash_hmac('sha256', 'coldtill-pay|' . $invoiceId . '|' . $expires, 'coldtill-pay-token|' . $key);
    }

    public static function verifyPayToken(string $key, int $invoiceId, string $expires, string $token, ?int $now = null): bool
    {
        if ($key === '' || $invoiceId <= 0 || !ctype_digit($expires) || (int) $expires < ($now ?? time())) {
            return false;
        }
        return hash_equals(self::payToken($key, $invoiceId, (int) $expires), strtolower($token));
    }
}

final class Client
{
    private string $baseUrl;
    /** @var callable(string, string, array<string,string>, ?string): array{0:int,1:string} */
    private $transport;

    /**
     * $transport takes (method, url, headers, body) and returns [status code, body]; it throws RuntimeException
     * when the server is unreachable. Tests pass a fake; the default is curl.
     */
    public function __construct(string $baseUrl, private string $apiKey, ?callable $transport = null)
    {
        if (!Core::isAllowedBaseUrl($baseUrl)) {
            throw new RuntimeException('Coldtill URL must start with https:// so the API key is not sent in the clear.');
        }
        $this->baseUrl   = rtrim(trim($baseUrl), '/');
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /**
     * @return array{host:string,reference_id:string,url:string}
     *
     * A project that limits return hosts rejects other sites' return URLs, so that case is retried once without
     * returnURL and the payment uses the project's own Return URL.
     */
    public function createPayment(array $req): array
    {
        try {
            $res = $this->request('POST', '/payment', $req);
        } catch (RuntimeException $e) {
            if (!isset($req['returnURL']) || $e->getCode() !== 400 || !str_contains($e->getMessage(), 'returnURL')) {
                throw $e;
            }
            unset($req['returnURL']);
            $res = $this->request('POST', '/payment', $req);
        }
        if (empty($res['reference_id']) || empty($res['url'])) {
            throw new RuntimeException('Coldtill: the payment response has no reference_id or url');
        }
        return $res;
    }

    public function getPayment(string $referenceId): array
    {
        return $this->request('GET', '/payment/reference/' . rawurlencode($referenceId));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = ['API-Key' => $this->apiKey, 'Accept' => 'application/json'];
        $json    = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        [$code, $raw] = ($this->transport)($method, $this->baseUrl . '/api/v1' . $path, $headers, $json);
        $data = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) && isset($data['error']) && is_string($data['error']) ? $data['error'] : 'HTTP ' . $code;
            throw new RuntimeException('Coldtill: ' . $msg, (int) $code);
        }
        return is_array($data) ? $data : [];
    }

    /** @return array{0:int,1:string} */
    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Coldtill is unreachable: ' . curl_error($ch));
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $raw];
    }
}
