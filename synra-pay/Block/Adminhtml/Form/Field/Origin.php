<?php
declare(strict_types=1);

namespace Synra\Pay\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Prints the origin(s) to register on the SynraPay merchant for hosted sessions. */
class Origin extends Field
{
    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(Context $context, StoreManagerInterface $storeManager, array $data = [])
    {
        parent::__construct($context, $data);
        $this->storeManager = $storeManager;
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $origins = [];
        foreach ($this->storeManager->getStores() as $store) {
            foreach ([true, false] as $secure) {
                $p = parse_url((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $secure));
                if (empty($p['host'])) {
                    continue;
                }
                $o = (isset($p['scheme']) ? $p['scheme'] : 'https') . '://' . $p['host'] . (!empty($p['port']) ? ':' . (int) $p['port'] : '');
                $origins[$o] = true;
            }
        }
        $html = '';
        foreach (array_keys($origins) as $o) {
            $html .= '<code style="user-select:all;display:inline-block;margin:0 8px 4px 0">' . $this->escapeHtml($o) . '</code>';
        }
        return $html !== '' ? $html : '<em>' . $this->escapeHtml((string) __('No store base URL configured.')) . '</em>';
    }
}
