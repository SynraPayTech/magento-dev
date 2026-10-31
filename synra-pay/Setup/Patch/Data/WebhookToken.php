<?php
declare(strict_types=1);

namespace Synra\Pay\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Synra\Pay\Model\Config;

/** Generates the per-store webhook token once, on install. */
class WebhookToken implements DataPatchInterface
{
    /** @var ScopeConfigInterface */
    private $scopeConfig;
    /** @var WriterInterface */
    private $writer;
    /** @var Random */
    private $random;

    public function __construct(ScopeConfigInterface $scopeConfig, WriterInterface $writer, Random $random)
    {
        $this->scopeConfig = $scopeConfig;
        $this->writer      = $writer;
        $this->random      = $random;
    }

    public function apply()
    {
        $existing = trim((string) $this->scopeConfig->getValue(Config::PATH . 'webhook_token'));
        if ($existing === '') {
            $this->writer->save(Config::PATH . 'webhook_token', strtolower($this->random->getRandomString(32)));
        }
        return $this;
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
