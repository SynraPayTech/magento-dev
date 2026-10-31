<?php
declare(strict_types=1);

namespace Synra\Pay\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;
use Synra\Pay\Model\Config;
use Synra\Pay\Model\Log;

/**
 * Signed server-to-server client for the SynraPay Direct API (signature v2):
 *
 *   x-merchant-id:       <Merchant ID>
 *   x-timestamp:         <unix ms>
 *   x-signature-version: 2
 *   x-signature:         HMAC_SHA512( "{ts}.{METHOD}.{path}.{rawBody}", api_signature_key )   // hex
 *
 * where path is the URL's own path (e.g. /v1/direct/create-order) and the
 * body is '' on a GET. Every signature is single-use, so the timestamp is
 * strictly increasing across the calls made in one request.
 *
 *   POST /direct/create-order              the order
 *   POST /direct/hosted-session            a session on it → client_token
 *   GET  /direct/payment/{id}/status       confirm before fulfilling
 *   POST /direct/refund                    PLACEHOLDER until the payment service publishes the route
 */
class Client
{
    const PATH_CREATE_ORDER   = '/direct/create-order';
    const PATH_HOSTED_SESSION = '/direct/hosted-session';
    const PATH_STATUS         = '/direct/payment/%s/status';
    const PATH_REFUND         = '/direct/refund';

    const VERSION = '1.0.0';

    /** @var CurlFactory */
    private $curlFactory;
    /** @var Config */
    private $config;
    /** @var Log */
    private $log;
    /** @var int last x-timestamp used by this process (ms) */
    private static $lastTs = 0;
    /** @var int HTTP status of the last call (0 = transport failure) */
    private $lastHttpCode = 0;

    public function __construct(CurlFactory $curlFactory, Config $config, Log $log)
    {
        $this->curlFactory = $curlFactory;
        $this->config      = $config;
        $this->log         = $log;
    }

    public function lastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    /**
     * Settings-page connection check: one signed GET on a payment id that does
     * not exist. The gateway must first accept the Merchant ID and signature;
     * only then does it answer "not found". So anything but an authentication
     * error means the credentials work.
     *
     * @return array { ok: bool, message: string }
     */
    public function probe(): array
    {
        if (!$this->config->isConfigured()) {
            return ['ok' => false, 'message' => (string) __('Merchant ID or API key is empty.')];
        }
        list($ok, $data, $err) = $this->status('connection-check', 8);
        $code = is_array($data) && isset($data['error']['code']) ? (string) $data['error']['code'] : '';
        if ($ok) {
            return ['ok' => true, 'message' => (string) __('Signed call accepted by the gateway.')];
        }
        if ($this->lastHttpCode === 0) {
            return ['ok' => false, 'message' => (string) __('Could not reach %1: %2', $this->config->baseUrl(), $err)];
        }
        if (in_array($code, ['INVALID_SIGNATURE', 'MERCHANT_NOT_IDENTIFIED', 'INVALID_TIMESTAMP', 'TIMESTAMP_EXPIRED', 'UNAUTHORIZED'], true) || $this->lastHttpCode === 401) {
            return ['ok' => false, 'message' => $err];
        }
        // 404 / 400 on a made-up id: authentication passed.
        return ['ok' => true, 'message' => (string) __('Merchant ID and API key accepted (gateway answered HTTP %1 for a non-existent payment, as expected).', $this->lastHttpCode)];
    }

    /** What the store signs with, without revealing it: length and character class. */
    public function keyFingerprint(): string
    {
        $k = $this->config->signatureKey();
        if ($k === '') {
            return 'empty';
        }
        $class = ctype_xdigit($k) ? 'hex' : (ctype_alnum($k) ? 'alphanumeric' : (preg_match('/^[\x20-\x7E]+$/', $k) ? 'printable' : 'NON-PRINTABLE (decryption problem?)'));
        return strlen($k) . ' chars, ' . $class;
    }

    /**
     * Step 1 — POST /v1/direct/create-order.
     *
     * @param array $payload { merchant_order_id, amount, currency, description,
     *                         customer{ email, firstName?, lastName?, phoneCountryCode, phoneNumber } }
     * @return array [ok, data, err]  data = { order_id, merchant_order_id, amount, currency, status, … }
     */
    public function createOrder(array $payload): array
    {
        return $this->call('POST', self::PATH_CREATE_ORDER, $payload);
    }

    /**
     * Step 2 — POST /v1/direct/hosted-session on that order.
     *
     * @return array [ok, data, err]  data = { session_id, client_token, expires_at, sdk_url, order{…} }
     */
    public function hostedSession(string $orderId, string $origin, array $options = []): array
    {
        $body = array_merge([
            'order_id'  => $orderId,
            'origin'    => $origin,
            'capture'   => true,
            'three_ds'  => true,
            'save_card' => 'never',
        ], $options);
        return $this->call('POST', self::PATH_HOSTED_SESSION, $body);
    }

    /**
     * Step 3 — GET /v1/direct/payment/{order_id}/status. The ORDER decides;
     * nothing the browser says about the outcome is trusted.
     *
     * @return array [ok, data, err]  data = { order_id, merchant_order_id, status, amount, currency, confirmation?, gateway_transaction_id, … }
     */
    public function status(string $orderId, int $timeout = 45): array
    {
        return $this->call('GET', sprintf(self::PATH_STATUS, rawurlencode($orderId)), null, $timeout);
    }

    /** PLACEHOLDER refund route — same contract as the WooCommerce plugin. */
    public function refund(array $payload): array
    {
        return $this->call('POST', self::PATH_REFUND, $payload);
    }

    /**
     * One signed Direct API call. Returns [ok(bool), data(array|null), error(string)].
     */
    public function call(string $method, string $path, ?array $payload = null, int $timeout = 45): array
    {
        if (!$this->config->isConfigured()) {
            return [false, null, (string) __('SynraPay is not configured (missing Merchant ID or API key).')];
        }

        $method = strtoupper($method);
        $url    = $this->config->baseUrl() . $path;
        $parts  = parse_url($url);
        $signedPath = isset($parts['path']) ? $parts['path'] : $path;
        $body   = $method === 'GET' ? '' : json_encode(is_array($payload) ? $payload : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Strictly increasing: two calls in the same millisecond would share a
        // signature, and a signature is good for one request only.
        $ts = (int) round(microtime(true) * 1000);
        if ($ts <= self::$lastTs) {
            $ts = self::$lastTs + 1;
        }
        self::$lastTs = $ts;
        $ts  = (string) $ts;
        $sig = hash_hmac('sha512', $ts . '.' . $method . '.' . $signedPath . '.' . $body, $this->config->signatureKey());

        $curl = $this->curlFactory->create();
        $curl->setTimeout(max(3, $timeout));
        $curl->setHeaders([
            'content-type'        => 'application/json',
            'accept'              => 'application/json',
            'x-merchant-id'       => $this->config->merchantId(),
            'x-timestamp'         => $ts,
            'x-signature-version' => '2',
            'x-signature'         => $sig,
            'user-agent'          => 'SynraPay-Magento2/' . self::VERSION,
        ]);

        // Debug trace of the signing inputs. The key itself is never written.
        $this->log->info(sprintf(
            'sign %s %s ts=%s body=%d bytes sha256=%s key=%s',
            $method, $signedPath, $ts, strlen($body), substr(hash('sha256', $body), 0, 12), $this->keyFingerprint()
        ));

        $this->lastHttpCode = 0;
        try {
            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->post($url, $body);
            }
        } catch (\Throwable $e) {
            $this->log->error('transport ' . $method . ' ' . $signedPath . ': ' . $e->getMessage());
            return [false, null, $e->getMessage()];
        }

        $code = (int) $curl->getStatus();
        $this->lastHttpCode = $code;
        $raw  = (string) $curl->getBody();
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $this->log->info(sprintf('%s %s → HTTP %d %s', $method, $signedPath, $code, mb_substr(preg_replace('/\s+/', ' ', $raw), 0, 300)));
            return [false, is_array($data) ? $data : null, self::errorText($data, $code)];
        }
        $this->log->info(sprintf('%s %s → HTTP %d', $method, $signedPath, $code));
        return [true, $data, ''];
    }

    /** A readable sentence out of a Direct API error answer. */
    public static function errorText($data, int $code): string
    {
        $msg = sprintf('Gateway error (HTTP %d).', $code);
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            if (!empty($data['error']['message'])) {
                $msg = (string) $data['error']['message'];
            }
            if (!empty($data['error']['code'])) {
                $msg = $data['error']['code'] . ': ' . $msg;
            }
            if (!empty($data['error']['details']) && is_array($data['error']['details'])) {
                $bits = [];
                foreach ($data['error']['details'] as $d) {
                    if (is_array($d) && !empty($d['message'])) {
                        $bits[] = $d['message'];
                    } elseif (is_array($d) && !empty($d['field'])) {
                        $bits[] = $d['field'];
                    }
                }
                if ($bits) {
                    $msg .= ' ' . implode(' ', $bits);
                }
            }
        } elseif (is_array($data) && !empty($data['error_message'])) {
            $msg = (string) $data['error_message'];
        } elseif (is_array($data) && !empty($data['message'])) {
            $msg = (string) $data['message'];
        }
        return $msg;
    }
}
