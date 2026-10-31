<?php
declare(strict_types=1);

namespace Synra\Pay\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Synra\Pay\Model\Api\Client;
use Synra\Pay\Model\Config;

/**
 * Settings page: one signed call to the gateway with the SAVED credentials, so a
 * wrong Merchant ID, a wrong or badly decrypted API key, or the wrong
 * Environment shows up here instead of at the shopper's checkout.
 */
class ConnectionCheck extends Field
{
    /** @var Client */
    private $client;
    /** @var Config */
    private $config;

    public function __construct(Context $context, Client $client, Config $config, array $data = [])
    {
        parent::__construct($context, $data);
        $this->client = $client;
        $this->config = $config;
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        if (!$this->config->isConfigured()) {
            return '<em>' . $this->escapeHtml((string) __('Save a Merchant ID and API key first.')) . '</em>';
        }
        $r = $this->client->probe();
        $icon = $r['ok'] ? '✅' : '❌';
        $html = '<div style="max-width:640px">'
            . '<strong>' . $icon . ' ' . $this->escapeHtml($r['message']) . '</strong>'
            . '<p class="note" style="margin-top:6px">'
            . $this->escapeHtml((string) __('Environment: %1 → %2', $this->config->mode(), $this->config->baseUrl())) . '<br>'
            . $this->escapeHtml((string) __('Merchant ID: %1', $this->config->merchantId())) . '<br>'
            . $this->escapeHtml((string) __('API key as decrypted on this server: %1', $this->client->keyFingerprint()))
            . '</p></div>';
        return $html;
    }
}
