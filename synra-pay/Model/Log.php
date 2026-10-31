<?php
declare(strict_types=1);

namespace Synra\Pay\Model;

use Psr\Log\LoggerInterface;

/**
 * var/log/synra-pay.log. Errors and warnings always; info only with Debug log on.
 * Never receives card data or keys.
 */
class Log
{
    /** @var LoggerInterface */
    private $logger;
    /** @var Config */
    private $config;

    public function __construct(LoggerInterface $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function error(string $message): void
    {
        $this->logger->error($message);
    }

    public function warning(string $message): void
    {
        $this->logger->warning($message);
    }

    public function info(string $message): void
    {
        if ($this->config->debug()) {
            $this->logger->info($message);
        }
    }
}
