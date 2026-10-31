<?php
declare(strict_types=1);

namespace Synra\Pay\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;
use Synra\Pay\Model\Config;

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
    /** @var int last x-timestamp used by this process (ms) */
    private static $lastTs = 0;

    public function __construct(CurlFactory $curlFactory, Config $config)
    {
        $this->curlFactory = $curlFactory;
        $this->config      = $config;
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

        try {
            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->post($url, $body);
            }
        } catch (\Throwable $e) {
            return [false, null, $e->getMessage()];
        }

        $code = (int) $curl->getStatus();
        $data = json_decode((string) $curl->getBody(), true);

        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return [false, is_array($data) ? $data : null, self::errorText($data, $code)];
        }
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
