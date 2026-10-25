# WHMCS crypto payment gateway: Coldtill for WHMCS

**Accept USDT, USDC, Bitcoin, Ethereum, Solana, Tron and 200+ coins on WHMCS invoices, straight to your own wallets.**
No sign-up, no KYB, no custody and no processor that can freeze your money.

This gateway module connects your WHMCS install to [Coldtill](https://coldtill.com), a
[self-hosted crypto payment gateway](https://github.com/Coldtill/self-hosted-crypto-payment-gateway) that runs on
your own server.

[Download the module](https://github.com/Coldtill/whmcs-crypto-payment-gateway/releases/latest/download/coldtill-whmcs.zip) ·
[Coldtill](https://coldtill.com) · [Docs](https://coldtill.com/docs/) · [Live demo dashboard](https://demo.coldtill.com/)

![Coldtill checkout: choose a network and coin, then scan the QR code](https://coldtill.com/img/checkout-pay.webp)

## Features

- **200+ coins on 35+ chains.** BTC, ETH, SOL, TRX, BNB, POL, USDT and USDC directly, and XRP, TON, Sui, Cardano,
  Dogecoin and more through auto-convert, settled to you as USDC or USDT.
- **Self-custody.** On Ethereum, Base, Polygon, BNB Chain, Tron and Solana each customer pays into a deposit contract
  that can only send to your cold wallet. Bitcoin goes to your own wallet's addresses.
- **No account to freeze.** No sign-up, no KYC or KYB, no reserves and no payout schedule.
- **Pay Now on the invoice.** The Coldtill payment is created only when the customer clicks, for the invoice's
  balance, and clicking again reuses the open payment.
- **Signed webhooks** (HMAC-SHA256 with a timestamp and replay window). Invoices are marked paid automatically and
  re-checked when the customer returns to the invoice if a webhook is missed.
- **Safe by default.** A payment is only applied when its reference and amount match the invoice; partial payments,
  overpayments and mismatches are written to the gateway log.

## Requirements

- WHMCS 8.x, PHP 8.1+ with curl, MySQL or MariaDB
- Client currency: USD (invoices in other currencies show a message instead of the pay button)
- A running Coldtill server. The Free edition installs with one command:

```bash
curl -fsSL https://coldtill.com/install.sh | sudo bash -s -- --edition free
```

## Install

1. Download [`coldtill-whmcs.zip`](https://github.com/Coldtill/whmcs-crypto-payment-gateway/releases/latest/download/coldtill-whmcs.zip).
2. Unzip it and upload the `modules` folder into your WHMCS root, so the files land in `modules/gateways/`:

```
modules/gateways/coldtill.php
modules/gateways/callback/coldtill.php
modules/gateways/coldtill/
```

## Setup

1. In Coldtill, create a project API key (**API keys**) and copy it.
2. In WHMCS, go to **System Settings → Payment Gateways**, find **Coldtill (crypto)** under All Payment Gateways
   and activate it.
3. Enter your Coldtill URL (`https://`; plain `http` only for `localhost`) and the API key.
4. In Coldtill → **Webhooks**, set the URL shown under "Webhook secret"
   (`https://your-whmcs/modules/gateways/callback/coldtill.php`) and paste the signing secret into the module
   settings. Save.

## What happens to an invoice

| Coldtill payment | WHMCS invoice |
|---|---|
| Filled or overpaid | Payment added for the balance; the invoice is marked paid. The transaction ID is the deposit's transaction hash |
| Partially paid | Unpaid; logged in the gateway log while the customer pays the rest |
| Cancelled or replaced | Unpaid; logged. If part was paid, settle the invoice by hand from what Coldtill shows |
| Amount differs from the balance | Unpaid; logged as a mismatch |

Every Coldtill payment is in USD for the invoice's balance when the customer clicked. The module keeps each invoice's
current Coldtill reference in a `mod_coldtill` table, which it creates on first use; events for older references
are logged and ignored. Overpayments are not credited: refund the difference from your wallet if needed.

The gateway log is under **Billing → Gateway Log**.

## Security

Report vulnerabilities to [security@coldtill.com](mailto:security@coldtill.com). See
[coldtill.com/security](https://coldtill.com/security).

## Licence

MIT. See [LICENSE](LICENSE).
