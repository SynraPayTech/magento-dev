<?php
declare(strict_types=1);

namespace Synra\Pay\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Mode implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'develop', 'label' => __('Develop (dev-api.synrapay.com)')],
            ['value' => 'sandbox', 'label' => __('Sandbox (testing)')],
            ['value' => 'live',    'label' => __('Live (production)')],
        ];
    }
}
