<?php
declare(strict_types=1);

namespace Synra\Pay\Block;

use Magento\Framework\DataObject;
use Magento\Payment\Block\Info as PaymentInfo;
use Synra\Pay\Model\OrderSettler;

/** The payment block on the admin order page and emails: SynraPay references. */
class Info extends PaymentInfo
{
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info = $this->getInfo();
        $rows = [];
        $map  = [
            OrderSettler::META_MERCHANT_ORDER => 'Merchant order ID',
            OrderSettler::META_TRANSACTION    => 'Transaction ID',
            OrderSettler::META_AUTH_CODE      => 'Authorization code',
            OrderSettler::META_ENV            => 'Environment',
            OrderSettler::META_AWAITING       => 'Awaiting final confirmation since',
        ];
        foreach ($map as $key => $label) {
            $v = $info->getAdditionalInformation($key);
            if ($v !== null && $v !== '') {
                $rows[(string) __($label)] = (string) $v;
            }
        }
        return $transport->setData(array_merge($transport->getData(), $rows));
    }
}
