<?php
declare(strict_types=1);

namespace Synra\Pay\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Math\Random;
use Magento\Quote\Model\Quote;
use Synra\Pay\Model\Api\Client;

/**
 * The hosted session on the checkout page:
 *
 *   page → open()  : create the Direct API order for the quote (or reuse the one
 *                    already open for the same amount, currency and customer) and
 *                    open a hosted session on it. Only the client_token goes back.
 *   Place Order    : the initialize command checks posted() — the Direct API order
 *                    the card fields are on must be the one THIS store opened, for
 *                    THIS quote, at the order's amount and currency.
 *
 * The open order lives in the checkout session (server side); the page only
 * ever sees its id.
 */
class HostedSession
{
    const SESSION_KEY = 'synra_hs';
    const HS_FIELD    = 'synra_hs_order_id';

    /** @var CheckoutSession */
    private $checkoutSession;
    /** @var CustomerSession */
    private $customerSession;
    /** @var Config */
    private $config;
    /** @var Client */
    private $client;
    /** @var Log */
    private $log;
    /** @var HttpRequest */
    private $request;
    /** @var RemoteAddress */
    private $remoteAddress;
    /** @var CacheInterface */
    private $cache;
    /** @var Random */
    private $random;

    public function __construct(
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        Config $config,
        Client $client,
        Log $log,
        HttpRequest $request,
        RemoteAddress $remoteAddress,
        CacheInterface $cache,
        Random $random
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->config          = $config;
        $this->client          = $client;
        $this->log             = $log;
        $this->request         = $request;
        $this->remoteAddress   = $remoteAddress;
        $this->cache           = $cache;
        $this->random          = $random;
    }

    /* ── open ──────────────────────────────────────────────────────────────── */

    /**
     * @param array $input { email, phone, phone_country_code, country, first_name, last_name }
     * @return array { clientToken, sdkUrl, expiresAt, sessionId, orderId, merchantOrderId, amount, currency }
     * @throws LocalizedException
     */
    public function open(array $input, bool $fresh): array
    {
        if (!$this->config->isConfigured()) {
            throw new LocalizedException(__('SynraPay is not configured (missing Merchant ID or API key).'));
        }
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            throw new LocalizedException(__('There is nothing to pay for yet.'));
        }

        $customer = $this->customerFromInput($input, $quote);
        list($amount, $currency) = $this->amount($quote);
        if ((float) $amount <= 0) {
            throw new LocalizedException(__('There is nothing to pay for yet.'));
        }

        $existing = $this->stored();
        $phoneFp  = isset($customer['phone']) ? $customer['phone'] : $customer['phoneCountryCode'] . $customer['phoneNumber'];
        $fp       = md5(implode('|', [$quote->getId(), $amount, $currency, $customer['email'], $phoneFp, $this->config->mode()]));

        $directOrderId   = '';
        $merchantOrderId = '';
        $created         = time();
        if (!$fresh && $existing && $existing['fp'] === $fp && (time() - (int) $existing['created']) < 1500) {
            // Same quote, same shopper: a new session on the order we already have.
            $directOrderId   = $existing['order_id'];
            $merchantOrderId = $existing['merchant_order_id'];
            $created         = (int) $existing['created'];
        } else {
            $merchantOrderId = $this->merchantOrderId($quote);
            list($ok, $data, $err) = $this->client->createOrder([
                'merchant_order_id' => $merchantOrderId,
                'amount'            => $amount,
                'currency'          => $currency,
                'description'       => $this->description($quote),
                'customer'          => $customer,
            ]);
            if (!$ok || empty($data['order_id'])) {
                $this->log->error('create-order failed: ' . ($ok ? 'no order_id' : $err));
                throw new LocalizedException(__($ok ? 'No order id returned.' : $err));
            }
            $directOrderId = (string) $data['order_id'];
            if (!empty($data['merchant_order_id'])) {
                $merchantOrderId = (string) $data['merchant_order_id'];
            }
        }

        list($ok, $data, $err) = $this->client->hostedSession($directOrderId, $this->requestOrigin());
        if (!$ok || empty($data['client_token'])) {
            // The order may be closed (paid, failed, expired): forget it so the
            // next call starts a new one.
            $this->clear();
            $this->log->error('hosted-session failed on ' . $directOrderId . ': ' . ($ok ? 'no client_token' : $err));
            throw new LocalizedException(__($ok ? 'No client token returned.' : $err));
        }

        $this->checkoutSession->setData(self::SESSION_KEY, [
            'quote_id'          => (int) $quote->getId(),
            'order_id'          => $directOrderId,
            'merchant_order_id' => $merchantOrderId,
            'fp'                => $fp,
            'amount'            => $amount,
            'currency'          => $currency,
            'created'           => $created,
        ]);
        $this->log->info('session on ' . $merchantOrderId . ' (' . $directOrderId . ') ' . $amount . ' ' . $currency . ' quote #' . $quote->getId());

        return [
            'clientToken'     => $data['client_token'],
            'sdkUrl'          => !empty($data['sdk_url']) ? (string) $data['sdk_url'] : $this->config->sdkUrl(),
            'expiresAt'       => isset($data['expires_at']) ? $data['expires_at'] : null,
            'sessionId'       => isset($data['session_id']) ? $data['session_id'] : null,
            'orderId'         => $directOrderId,
            'merchantOrderId' => $merchantOrderId,
            'amount'          => $amount,
            'currency'        => $currency,
        ];
    }

    /** The open Direct API order, if any. @return array|null */
    public function stored(): ?array
    {
        $hs = $this->checkoutSession->getData(self::SESSION_KEY);
        return is_array($hs) && !empty($hs['order_id']) && isset($hs['fp'], $hs['amount'], $hs['currency']) ? $hs : null;
    }

    /** The Direct API order the checkout form says the card fields are on, if it is the one this store opened. */
    public function posted(string $postedOrderId): ?array
    {
        $hs = $this->stored();
        if ($postedOrderId === '' || !$hs || !hash_equals((string) $hs['order_id'], $postedOrderId)) {
            return null;
        }
        return $hs;
    }

    public function clear(): void
    {
        $this->checkoutSession->unsetData(self::SESSION_KEY);
    }

    /* ── pieces ────────────────────────────────────────────────────────────── */

    private function merchantOrderId(Quote $quote): string
    {
        $site = strtoupper(substr(md5($this->config->siteOrigin()), 0, 6));
        $time = strtoupper(base_convert((string) (int) floor(microtime(true) * 1000), 10, 36));
        $rand = strtoupper($this->random->getRandomString(4, Random::CHARS_DIGITS . Random::CHARS_UPPERS));
        return 'MAG-' . $site . '-' . $time . '-' . $rand;
    }

    private function description(Quote $quote): string
    {
        $store = $quote->getStore();
        $name  = $store ? trim(strip_tags(html_entity_decode((string) $store->getFrontendName(), ENT_QUOTES, 'UTF-8'))) : '';
        $d     = $name !== '' ? sprintf('%s order', $name) : 'Magento order';
        $d     = preg_replace('/[\x00-\x1F\x7F<>]/u', '', $d);
        if (mb_strlen($d) < 3) {
            $d = 'Magento order';
        }
        return mb_substr($d, 0, 255);
    }

    /** @return array [amount(string), currency] — the quote's grand total in the quote's currency. */
    private function amount(Quote $quote): array
    {
        $quote->collectTotals();
        $currency = (string) ($quote->getQuoteCurrencyCode() ?: $quote->getStore()->getCurrentCurrencyCode());
        return [number_format(round((float) $quote->getGrandTotal(), 2), 2, '.', ''), strtoupper($currency)];
    }

    /**
     * What the checkout page said about the shopper, in the Direct API's shape,
     * completed from the quote. Email and phone are required by create-order;
     * the name is optional.
     *
     * @throws LocalizedException
     */
    private function customerFromInput(array $in, Quote $quote): array
    {
        $email   = isset($in['email']) ? trim((string) $in['email']) : '';
        $phone   = isset($in['phone']) ? preg_replace('/[^\d+]/', '', (string) $in['phone']) : '';
        $dial    = isset($in['phone_country_code']) ? preg_replace('/[^\d]/', '', (string) $in['phone_country_code']) : '';
        $country = isset($in['country']) ? strtoupper(trim((string) $in['country'])) : '';
        $first   = isset($in['first_name']) ? trim(strip_tags((string) $in['first_name'])) : '';
        $last    = isset($in['last_name']) ? trim(strip_tags((string) $in['last_name'])) : '';

        $billing = $quote->getBillingAddress();
        $ship    = $quote->getShippingAddress();

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && $this->customerSession->isLoggedIn()) {
            $email = (string) $this->customerSession->getCustomer()->getEmail();
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = (string) ($quote->getCustomerEmail() ?: ($billing ? $billing->getEmail() : ''));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Enter a valid email address to load the card form.'));
        }
        foreach ([$billing, $ship] as $addr) {
            if (!$addr) {
                continue;
            }
            if ($country === '') {
                $country = strtoupper((string) $addr->getCountryId());
            }
            if ($first === '') {
                $first = (string) $addr->getFirstname();
            }
            if ($last === '') {
                $last = (string) $addr->getLastname();
            }
        }

        // The phone: what was typed, else the quote's billing/shipping phone,
        // else the store's fallback number (SynraPay refuses an order without one).
        $split = $this->splitPhone($phone, $dial, $country);
        foreach ([$billing, $ship] as $addr) {
            if (!$split && $addr) {
                $split = $this->splitPhone((string) $addr->getTelephone(), '', $country ?: (string) $addr->getCountryId());
            }
        }
        $customer = ['email' => strtolower($email)];
        if ($split) {
            $customer['phoneCountryCode'] = $split['phoneCountryCode'];
            $customer['phoneNumber']      = $split['phoneNumber'];
        } else {
            $fallback = $this->config->fallbackPhone();
            if (strlen($fallback) < 8 || strlen($fallback) > 15) {
                throw new LocalizedException(__('Enter a valid phone number to load the card form.'));
            }
            $customer['phone'] = '+' . $fallback;
        }
        foreach (['firstName' => $first, 'lastName' => $last] as $k => $v) {
            if (mb_strlen($v) >= 2 && mb_strlen($v) <= 120 && preg_match('/\p{L}/u', $v)) {
                $customer[$k] = $v;
            }
        }
        return $customer;
    }

    /**
     * A phone as the Direct API wants it: dial code + national number. The dial
     * code comes from the billing country when it was not given; a number that
     * does not add up to 8-15 digits is not a phone.
     */
    private function splitPhone(string $phone, string $dial, string $country): ?array
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);
        $dial  = preg_replace('/[^\d]/', '', $dial);
        if ($phone === '') {
            return null;
        }
        if ($dial === '' && $country !== '') {
            $dial = DialCodes::forCountry($country);
        }
        $digits = ltrim($phone, '+');
        if ($dial !== '' && strpos($digits, $dial) === 0 && strlen($digits) > strlen($dial) + 4) {
            $digits = substr($digits, strlen($dial));
        }
        $digits = ltrim($digits, '0');
        if ($dial === '' || $digits === '' || strlen($dial . $digits) < 8 || strlen($dial . $digits) > 15) {
            return null;
        }
        return ['phoneCountryCode' => '+' . $dial, 'phoneNumber' => $digits];
    }

    /**
     * The origin a session is opened for: the checkout page's own Origin header
     * when it is this site (so scheme and port are exactly what the browser will
     * send to SynraPay's frame), otherwise the site origin.
     */
    private function requestOrigin(): string
    {
        $site = $this->config->siteOrigin();
        $hdr  = rtrim((string) $this->request->getHeader('Origin'), '/');
        if ($hdr !== '' && preg_match('#^https?://[^/\s]+$#i', $hdr) && parse_url($hdr, PHP_URL_HOST) === parse_url($site, PHP_URL_HOST)) {
            return $hdr;
        }
        return $site;
    }

    /* ── abuse guard ───────────────────────────────────────────────────────── */

    /**
     * At most $max calls per $window seconds per session and per IP (twice
     * that). A checkout needs a handful; a script does not get thousands.
     *
     * @throws LocalizedException
     */
    public function guard(string $bucket, int $max, int $window): void
    {
        $ids = [
            'sid' => [$this->checkoutSession->getSessionId() ?: '', $max],
            'ip'  => [$this->remoteAddress->getRemoteAddress() ?: '', $max * 2],
        ];
        foreach ($ids as $kind => $pair) {
            list($id, $limit) = $pair;
            if ($id === '') {
                continue;
            }
            $key = 'synra_hs_rl_' . $bucket . '_' . $kind . '_' . md5((string) $id);
            $n   = (int) $this->cache->load($key) + 1;
            $this->cache->save((string) $n, $key, ['SYNRA_PAY'], $window);
            if ($n > $limit) {
                $this->log->warning('rate limit hit: ' . $bucket . ' by ' . $kind);
                throw new LocalizedException(__('Too many attempts. Please wait a few minutes and try again.'));
            }
        }
    }
}
