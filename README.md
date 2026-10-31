# 💳 SynraPay for Magento 2 / Adobe Commerce

Accept **Visa, Mastercard and American Express** on your Magento 2 store with
SynraPay. The card fields are SynraPay's own, shown **inside your checkout page**
(no redirect), with 3-D Secure in an overlay. Card data never touches your
server.

| | |
|---|---|
| 📦 Module | `Synra_Pay` v1.0.0 — folder [`synra-pay/`](./synra-pay) (= `app/code/Synra/Pay`) |
| 🛒 Magento | 2.4.0 or greater (Luma and Hyvä-compatible Knockout checkout) |
| 🐘 PHP | 8.2 or greater |
| 🔐 Integration | SynraPay Direct API — hosted session, signature v2 |

---

## ✨ What the shopper sees

1. Picks **Credit / Debit card** at the payment step.
2. SynraPay's card fields load inside the payment method box.
3. Clicks **Place Order** → the card is sent from SynraPay's frame to SynraPay, 3-D Secure opens in an overlay.
4. Lands on the normal Magento **success page**. The order is in *Processing* with an invoice.

---

## ✅ Before you start

You need these from the **SynraPay Merchant Portal** (sign in → pick the store → **Developers**):

| Item | Where in the portal |
|---|---|
| **Merchant ID** (`x-merchant-id`) | Developers → **API Keys** → Request Signing |
| **API Signature Key** | Developers → **API Keys** → Request Signing (Direct API grant required) |
| **Webhook signing secret** | Developers → **Webhooks** → Configuration → *Generate new secret* (shown once) |
| **Allowed origin** | Developers → **Integrations** → add your store's origin, e.g. `https://shop.example.com` |

Sandbox and Live each have their **own portal and own keys**. Start with Sandbox.

---

## 📦 Installation

### Option A — upload the ready zip

1. Download [`dist/synra-pay-magento2.zip`](./dist/synra-pay-magento2.zip).
2. Unzip at your Magento root. It creates `app/code/Synra/Pay/`:
   ```sh
   unzip synra-pay-magento2.zip -d /var/www/magento
   ```

### Option B — copy the folder

1. Clone this repository.
2. Copy `synra-pay/` to `<magento root>/app/code/Synra/Pay/` (rename the folder to `Pay`).

### Then enable the module

```sh
bin/magento module:enable Synra_Pay
bin/magento setup:upgrade
bin/magento setup:di:compile                 # production mode
bin/magento setup:static-content:deploy -f   # production mode
bin/magento cache:flush
```

---

## ⚙️ Configuration

**Stores → Configuration → Sales → Payment Methods → SynraPay — Credit / Debit card**

| Setting | What to enter |
|---|---|
| Enabled | Yes |
| Title / Description | What shoppers see at checkout |
| 🌍 Environment | **Sandbox** while testing, **Live** when you go live (*Develop* is for SynraPay internal testing) |
| 🆔 Merchant ID | From API Keys (for the chosen environment) |
| 🔑 API Key (signature key) | From API Keys. Stored encrypted, never sent to the browser |
| 📞 Fallback customer phone | SynraPay requires a phone on every order; this is used when the shopper's phone is missing or invalid |
| 🔔 Webhook signing secret | From Webhooks → Configuration |
| Webhook API-Key header name / value | Optional extra check on webhooks (API Keys → Webhook Verification) |
| Payment from Applicable Countries | Optional country restriction |
| 🐞 Debug log | Writes each step to `var/log/synra-pay.log` (never card data or keys) |

Save, then **copy two values printed on the same page**:

- 🔗 **Your Webhook URL** → Merchant Portal → Developers → Webhooks → *Update Webhook* → Endpoint URL, with **All events** subscribed.
- 🌐 **Origin to register** → Merchant Portal → Developers → Integrations → allowed origins.
  Without it the card form answers `ORIGIN_NOT_REGISTERED`. Live requires `https://`; Sandbox also accepts `http://localhost`.

🕒 **Cron**: make sure Magento's cron is running. The module adds `synra_pay_recheck` (every 5 minutes) which settles orders left waiting for SynraPay's final confirmation. The webhook remains the primary path.

---

## 🧪 Testing

1. Set Environment = **Sandbox** with Sandbox keys, register the Sandbox webhook URL and origin.
2. Place an order with a SynraPay sandbox test card.
3. Check: order in **Processing** with an invoice, status history shows *SynraPay: payment captured*, and the webhook arrived (`var/log/synra-pay.log` with Debug log on).
4. Switch Environment to **Live**, paste the Live keys, register the Live webhook URL and origin.

---

## 🔄 How a payment flows

```
checkout page   POST synra/hs/session                       (form_key)
   store        POST /v1/direct/create-order                merchant_order_id MAG-<site>-<time>-<rand>, amount = quote grand total
   store        POST /v1/direct/hosted-session              origin = your store → client_token
   page         SynraPayFields.create({ clientToken })      card fields mount in the payment box

Place Order  →  Magento places the order as usual (pending_payment, no email yet)
                 the module links it to that SynraPay order (same quote, amount and currency, or refuses)
   page         fields.submit()                             3-D Secure in an overlay
   page         POST synra/hs/confirm (polls)
   store        GET /v1/direct/payment/{order_id}/status
                   CAPTURED / AUTHORIZED  → invoice (captured at SynraPay), Processing, order email → success page
                   FAILED / DECLINED …    → order canceled, cart restored, new card session for a retry
                   approved, not final    → order stays pending_payment, shopper sees success; cron / webhook finish it
   webhook      POST synra/webhook/index/token/<per-store token>   signed (v2), source of truth
```

---

## 🔒 Security

- The browser only ever holds a short-lived **client token**. Merchant ID and API key stay on your server and sign every call (`HMAC-SHA512`, signature v2).
- The store **never trusts the browser** about the outcome: only SynraPay's order status settles the Magento order.
- The **amount paid must equal the order total** (same currency) or the order goes **on hold** for review.
- Webhooks are accepted only with a valid **signature + timestamp (5 min window)**, a per-store secret token in the URL and, optionally, your API-key header.
- Per-session and per-IP **rate limits** on the checkout endpoints; Magento `form_key` CSRF on every AJAX call.
- Nothing logs card data or keys.

---

## 🛠️ Troubleshooting

| Message | Meaning / fix |
|---|---|
| `ORIGIN_NOT_REGISTERED` | Register the exact origin printed on the settings page (scheme + host + port) under Developers → Integrations. |
| `MERCHANT_NOT_IDENTIFIED` | Merchant ID is wrong or belongs to another environment. |
| `ENVIRONMENT_MISMATCH` | The keys pasted are for a different environment than the one selected. |
| Card form keeps loading | Open `var/log/synra-pay.log`; usually a missing phone (set a fallback phone) or an unregistered origin. |
| Webhook answers 401 | Signing secret differs from the portal, or the API-key header name/value does not match. |
| Order stays *Pending Payment* | Cron not running, or the webhook URL is not registered. Run `bin/magento cron:run` and check the webhook in the portal. |

---

## 📁 Repository layout

```
synra-pay/                               the module (= app/code/Synra/Pay)
├── etc/                                 module, config, di (gateway Adapter), events, crontab, admin form, routes
├── Model/                               Config, Api/Client (signed Direct API), HostedSession, OrderSettler, OrderFinder, Ui/ConfigProvider
├── Gateway/Command/                     Initialize (links the Magento order), Refund (placeholder route)
├── Controller/Hs/                       session + confirm (checkout AJAX)
├── Controller/Webhook/                  signed webhook
├── Cron/Recheck.php                     re-check pending orders
├── Block/                               admin order info, settings-page helpers
└── view/frontend/                       Knockout renderer, synra-hs-core.js, template, css
build-zip.sh                             builds dist/synra-pay-magento2.zip
dist/                                    ready-to-install zip
```

### 🧑‍💻 Notes for developers

- Rebuild the zip with `sh build-zip.sh` (uses `zip` or Windows `tar`; forward-slash entries).
- Order data: `sales_order.ext_order_id` = SynraPay order id; payment `additional_information` carries `synra_merchant_order_id`, `synra_direct_order_id`, `synra_env`, `synra_gateway_reference`.
- Refund from a credit memo calls `POST /v1/direct/refund`, a **placeholder** route until the payment service publishes the final one.
- Not included on purpose: hosted-checkout redirect mode, admin-placed orders (`can_use_internal` = 0), saved cards.

---

## 📝 Changelog

- **1.0.0** — First release: hosted-session card fields on the checkout page, webhook, cron re-check, admin settings.

## 🙋 Support

Open an issue in this repository or contact your SynraPay account manager through the Merchant Portal.
