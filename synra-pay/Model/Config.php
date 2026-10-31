<?php
declare(strict_types=1);

namespace Synra\Pay\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The module's settings (Stores → Configuration → Sales → Payment Methods → SynraPay).
 */
class Config
{
    const CODE = 'synra_pay';
    const PATH = 'payment/synra_pay/';

    /** @var ScopeConfigInterface */
    private $scopeConfig;
    /** @var EncryptorInterface */
    private $encryptor;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var UrlInterface */
    private $urlBuilder;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder
    ) {
        $this->scopeConfig  = $scopeConfig;
        $this->encryptor    = $encryptor;
        $this->storeManager = $storeManager;
        $this->urlBuilder   = $urlBuilder;
    }

    /** @param int|string|null $storeId */
    public function get(string $key, $storeId = null): string
    {
        $v = $this->scopeConfig->getValue(self::PATH . $key, ScopeInterface::SCOPE_STORE, $storeId);
        return trim((string) $v);
    }

    /** An encrypted (obscure) setting, decrypted. */
    public function secret(string $key, $storeId = null): string
    {
        $raw = $this->get($key, $storeId);
        if ($raw === '') {
            return '';
        }
        $dec = $this->encryptor->decrypt($raw);
        return trim((string) $dec);
    }

    public function isActive($storeId = null): bool
    {
        return $this->get('active', $storeId) === '1';
    }

    public function debug($storeId = null): bool
    {
        return $this->get('debug', $storeId) === '1';
    }

    public function title($storeId = null): string
    {
        $t = $this->get('title', $storeId);
        return $t !== '' ? $t : 'Credit / Debit card';
    }

    public function description($storeId = null): string
    {
        return $this->get('description', $storeId);
    }

    /** develop | sandbox | live */
    public function mode($storeId = null): string
    {
        $m = $this->get('mode', $storeId);
        return in_array($m, ['develop', 'sandbox', 'live'], true) ? $m : 'develop';
    }

    public function baseUrl($storeId = null): string
    {
        switch ($this->mode($storeId)) {
            case 'live':
                $u = $this->get('live_base', $storeId) ?: 'https://api.synrapay.com/v1';
                break;
            case 'sandbox':
                $u = $this->get('sandbox_base', $storeId) ?: 'https://sandbox-api.synrapay.com/v1';
                break;
            default:
                $u = $this->get('dev_base', $storeId) ?: 'https://dev-api.synrapay.com/v1';
        }
        return rtrim($u, '/');
    }

    /** The scripts host for the environment: where sdk.js (and the card frame) live. */
    public function sdkUrl($storeId = null): string
    {
        switch ($this->mode($storeId)) {
            case 'live':
                return 'https://js.synrapay.com/direct/v1/sdk.js';
            case 'sandbox':
                return 'https://sandbox.js.synrapay.com/direct/v1/sdk.js';
            default:
                return 'https://dev.js.synrapay.com/direct/v1/sdk.js';
        }
    }

    public function merchantId($storeId = null): string
    {
        return $this->get('merchant_id', $storeId);
    }

    public function signatureKey($storeId = null): string
    {
        return $this->secret('api_signature_key', $storeId);
    }

    public function isConfigured($storeId = null): bool
    {
        return $this->merchantId($storeId) !== '' && $this->signatureKey($storeId) !== '';
    }

    /** Digits only, or '' when no fallback is wanted. */
    public function fallbackPhone($storeId = null): string
    {
        return preg_replace('/[^\d]/', '', $this->get('fallback_phone', $storeId));
    }

    public function webhookSecret($storeId = null): string
    {
        return $this->secret('key_sha512', $storeId);
    }

    public function webhookKeyName($storeId = null): string
    {
        return $this->get('x_api_key_name', $storeId);
    }

    public function webhookKeyValue($storeId = null): string
    {
        return $this->secret('x_api_key', $storeId);
    }

    /**
     * The per-store secret in the webhook URL, so the endpoint cannot be
     * guessed: the random token the install patch stored, or (until the config
     * cache has seen it) one derived from this installation's encryption key —
     * stable without a write, never different from one request to the next.
     */
    public function webhookToken(): string
    {
        $t = $this->get('webhook_token');
        if ($t === '') {
            $t = substr(hash('sha256', $this->encryptor->hash('synra_pay_webhook_token')), 0, 32);
        }
        return $t;
    }

    public function webhookUrl(): string
    {
        return $this->urlBuilder->getUrl('synra/webhook/index', ['token' => $this->webhookToken(), '_secure' => true]);
    }

    /** scheme://host[:port] of this store, as a browser writes it in the Origin header. */
    public function siteOrigin(): string
    {
        $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
        $p    = parse_url($base);
        $origin = (isset($p['scheme']) ? $p['scheme'] : 'https') . '://' . (isset($p['host']) ? $p['host'] : '');
        if (!empty($p['port'])) {
            $origin .= ':' . (int) $p['port'];
        }
        return $origin;
    }
}
