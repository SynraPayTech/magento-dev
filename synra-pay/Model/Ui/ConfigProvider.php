<?php
declare(strict_types=1);

namespace Synra\Pay\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Synra\Pay\Model\Config;
use Synra\Pay\Model\HostedSession;

/**
 * window.checkoutConfig.payment.synra_pay — what the checkout page needs. The
 * Merchant ID and API key are NOT in it: only the store signs calls.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /** @var Config */
    private $config;
    /** @var UrlInterface */
    private $urlBuilder;
    /** @var AssetRepository */
    private $assets;

    public function __construct(Config $config, UrlInterface $urlBuilder, AssetRepository $assets)
    {
        $this->config     = $config;
        $this->urlBuilder = $urlBuilder;
        $this->assets     = $assets;
    }

    public function getConfig()
    {
        if (!$this->config->isActive()) {
            return [];
        }
        return [
            'payment' => [
                Config::CODE => [
                    'title'       => $this->config->title(),
                    'description' => $this->config->description(),
                    'icon'        => $this->assets->getUrl('Synra_Pay::images/synrapay.png'),
                    'mode'        => $this->config->mode(),
                    'sdkUrl'      => $this->config->sdkUrl(),
                    'sessionUrl'  => $this->urlBuilder->getUrl('synra/hs/session', ['_secure' => true]),
                    'confirmUrl'  => $this->urlBuilder->getUrl('synra/hs/confirm', ['_secure' => true]),
                    'successUrl'  => $this->urlBuilder->getUrl('checkout/onepage/success', ['_secure' => true]),
                    'hsField'     => HostedSession::HS_FIELD,
                    'dialCodes'   => \Synra\Pay\Model\DialCodes::CODES,
                    'i18n'        => [
                        'processing' => (string) __('Processing your payment…'),
                        'confirming' => (string) __('Confirming your payment…'),
                        'declined'   => (string) __('Your payment was not completed. Please try again or use another card.'),
                        'notReady'   => (string) __('The card form is still loading.'),
                        'incomplete' => (string) __('Please complete your card details.'),
                        'expired'    => (string) __('The card session expired. A new one is loading…'),
                        'sdkFailed'  => (string) __('Could not load the SynraPay card form.'),
                    ],
                ],
            ],
        ];
    }
}
