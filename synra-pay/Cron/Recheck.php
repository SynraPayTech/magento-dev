<?php
declare(strict_types=1);

namespace Synra\Pay\Cron;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Synra\Pay\Model\Api\Client;
use Synra\Pay\Model\Config;
use Synra\Pay\Model\Log;
use Synra\Pay\Model\OrderSettler;

/**
 * Every 5 minutes: SynraPay orders still pending_payment (the shopper left the
 * page mid-payment, or the card frame said ok before SynraPay was final) are
 * read again at SynraPay and settled when final. Orders older than 3 hours
 * are left to the webhook.
 */
class Recheck
{
    /** @var OrderCollectionFactory */
    private $collectionFactory;
    /** @var Client */
    private $client;
    /** @var OrderSettler */
    private $settler;
    /** @var Config */
    private $config;
    /** @var Log */
    private $log;

    public function __construct(OrderCollectionFactory $collectionFactory, Client $client, OrderSettler $settler, Config $config, Log $log)
    {
        $this->collectionFactory = $collectionFactory;
        $this->client            = $client;
        $this->settler           = $settler;
        $this->config            = $config;
        $this->log               = $log;
    }

    public function execute(): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }
        $c = $this->collectionFactory->create();
        $c->join(['p' => 'sales_order_payment'], 'p.parent_id = main_table.entity_id', [])
          ->addFieldToFilter('p.method', Config::CODE)
          ->addFieldToFilter('main_table.state', Order::STATE_PENDING_PAYMENT)
          ->addFieldToFilter('main_table.ext_order_id', ['notnull' => true])
          ->addFieldToFilter('main_table.created_at', ['gteq' => gmdate('Y-m-d H:i:s', time() - 3 * 3600)])
          ->addFieldToFilter('main_table.created_at', ['lteq' => gmdate('Y-m-d H:i:s', time() - 120)])
          ->setPageSize(50);

        /** @var Order $order */
        foreach ($c as $order) {
            $direct = (string) $order->getExtOrderId();
            if ($direct === '') {
                continue;
            }
            list($ok, $data) = $this->client->status($direct, 20);
            if (!$ok || !is_array($data)) {
                continue;
            }
            $outcome = $this->settler->applyStatus($order, $data, 're-check');
            $this->log->info('re-check #' . $order->getIncrementId() . ' → ' . $outcome);
        }
    }
}
