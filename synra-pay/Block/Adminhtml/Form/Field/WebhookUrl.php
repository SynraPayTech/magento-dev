<?php
declare(strict_types=1);

namespace Synra\Pay\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Synra\Pay\Model\Config;

/** Prints the store's webhook URL (with its per-store token) on the settings page. */
class WebhookUrl extends Field
{
    /** @var Config */
    private $config;
    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(Context $context, Config $config, StoreManagerInterface $storeManager, array $data = [])
    {
        parent::__construct($context, $data);
        $this->config       = $config;
        $this->storeManager = $storeManager;
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $store = $this->storeManager->getDefaultStoreView();
        $base  = $store ? rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true), '/') : '';
        $url   = $base . '/synra/webhook/index/token/' . $this->config->webhookToken() . '/';
        return '<code style="user-select:all;word-break:break-all">' . $this->escapeHtml($url) . '</code>';
    }
}
