<?php

// The WHMCS side of the gateway: invoices, the reference table and payments. Shared by the gateway module, the
// pay step and the callback.

namespace Coldtill\Whmcs;

use RuntimeException;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/Core.php';

final class Gateway
{
    public const MODULE = 'coldtill';
    private const TABLE = 'mod_coldtill';

    public static function client(array $gw): Client
    {
        return new Client((string) ($gw['base_url'] ?? ''), (string) ($gw['api_key'] ?? ''));
    }

    /**
     * One row per invoice holding its current Coldtill reference. A new payment for the invoice overwrites the
     * row, so events for an older reference no longer match and are ignored. Invoice notes would also work, but
     * customers can see them and admins can edit them.
     */
    private static function ensureTable(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, function ($t) {
            $t->unsignedInteger('invoice_id')->primary();
            $t->string('reference_id', 64)->unique();
            $t->text('url');
            $t->string('amount', 32);
            $t->unsignedInteger('updated_at');
        });
    }

    public static function saveReference(int $invoiceId, string $ref, string $url, string $amount): void
    {
        self::ensureTable();
        Capsule::table(self::TABLE)->updateOrInsert(
            ['invoice_id' => $invoiceId],
            ['reference_id' => $ref, 'url' => $url, 'amount' => $amount, 'updated_at' => time()]
        );
    }

    /** @return array{invoice_id:int,reference_id:string,url:string,amount:string}|null */
    public static function referenceFor(int $invoiceId): ?array
    {
        self::ensureTable();
        $row = Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->first();
        return $row ? (array) $row : null;
    }

    public static function invoiceForReference(string $ref): int
    {
        self::ensureTable();
        return (int) Capsule::table(self::TABLE)->where('reference_id', $ref)->value('invoice_id');
    }

    /** GetInvoice plus the client's currency code and email, or null when there is no such invoice. */
    public static function invoice(int $invoiceId): ?array
    {
        $inv = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($inv['result'] ?? '') !== 'success') {
            return null;
        }
        $client = Capsule::table('tblclients')
            ->leftJoin('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblclients.id', (int) $inv['userid'])
            ->first(['tblclients.email', 'tblcurrencies.code']);
        $inv['email']    = (string) ($client->email ?? '');
        $inv['currency'] = (string) ($client->code ?? '');
        return $inv;
    }

    public static function log(array $gw, array $data, string $status): void
    {
        logTransaction($gw['name'] ?? self::MODULE, $data, $status);
    }

    /** Fallback for missed webhooks: re-check the invoice's current Coldtill payment. True when it was paid now. */
    public static function sync(array $gw, int $invoiceId): bool
    {
        $row = self::referenceFor($invoiceId);
        if (!$row) {
            return false;
        }
        try {
            $p = self::client($gw)->getPayment($row['reference_id']);
        } catch (RuntimeException $e) {
            self::log($gw, ['invoice' => $invoiceId, 'reference_id' => $row['reference_id'], 'error' => $e->getMessage()], 'Status check failed');
            return false;
        }
        return self::apply(
            $gw,
            $invoiceId,
            $row['reference_id'],
            (string) ($p['paymentState'] ?? ''),
            (string) ($p['amountInUSD'] ?? ''),
            (string) ($p['deposits'][0]['transactionHash'] ?? ''),
            (string) ($p['invoiceID'] ?? ''),
            ['source' => 'status check'] + $p,
            false
        );
    }

    /**
     * Applies a Coldtill payment state to the invoice. $fromCallback lets WHMCS's own callback checks run; they
     * exit the script, which is right for the callback but not on an invoice page. True when a payment was added.
     */
    public static function apply(array $gw, int $invoiceId, string $ref, string $status, string $amount, string $tx, string $coldtillInvoiceId, array $logData, bool $fromCallback): bool
    {
        // A retried payment gets a new reference; events for the old one must not touch the invoice.
        $current = self::referenceFor($invoiceId);
        if (!$current || $current['reference_id'] !== $ref) {
            return false;
        }
        if ($coldtillInvoiceId !== '' && Core::invoiceIdFrom($coldtillInvoiceId) !== $invoiceId) {
            self::log($gw, $logData, 'Ignored: invoice id does not match');
            return false;
        }

        // The webhook and the customer's return can arrive together; one of them waits so a payment is added once.
        $lock = 'coldtill_invoice_' . $invoiceId;
        Capsule::select('SELECT GET_LOCK(?, 15)', [$lock]);
        try {
            $inv = self::invoice($invoiceId);
            if (!$inv || $inv['status'] !== 'Unpaid') {
                return false;
            }
            if ($inv['currency'] !== 'USD') {
                self::log($gw, $logData, 'Ignored: invoice currency is not USD');
                return false;
            }
            switch (Core::action($status, $amount, (string) $inv['balance'])) {
                case Core::PAY:
                    $transId = $tx !== '' ? $tx : $ref;
                    if ($fromCallback) {
                        checkCbInvoiceID($invoiceId, $gw['name']);
                        checkCbTransID($transId);
                    } elseif (Capsule::table('tblaccounts')->where('transid', $transId)->exists()) {
                        return false;
                    }
                    addInvoicePayment($invoiceId, $transId, Core::money($amount), 0, self::MODULE);
                    self::log($gw, $logData, $status === 'OVER_FILLED' ? 'Success (overpaid; refund the difference from your wallet if needed)' : 'Success');
                    return true;
                case Core::MISMATCH:
                    self::log($gw, $logData, sprintf('Amount mismatch: Coldtill %s USD, invoice balance %s USD; not applied', $amount, $inv['balance']));
                    break;
                case Core::PARTIAL:
                    self::log($gw, $logData, 'Partially paid; waiting for the rest');
                    break;
                case Core::CLOSED:
                    // Part of the money may have arrived before the payment closed; WHMCS gets nothing applied, so
                    // the log says to check.
                    self::log($gw, $logData, ($status === 'CANCELLED' ? 'Cancelled' : 'Replaced') . '; if anything was paid, check Coldtill and settle the invoice by hand');
                    break;
            }
            return false;
        } finally {
            Capsule::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
