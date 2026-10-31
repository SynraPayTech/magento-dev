<?php
declare(strict_types=1);

namespace Synra\Pay\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Finds the Magento order behind a SynraPay reference. The Direct API order id
 * is stored on sales_order.ext_order_id (indexed, exact); the merchant order id
 * (MAG-…) lives in the payment's additional_information.
 */
class OrderFinder
{
    /** @var OrderCollectionFactory */
    private $collectionFactory;

    public function __construct(OrderCollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }

    public function byDirectOrderId(string $directOrderId): ?Order
    {
        if ($directOrderId === '') {
            return null;
        }
        $c = $this->collectionFactory->create();
        $c->addFieldToFilter('ext_order_id', $directOrderId)->setPageSize(1);
        $o = $c->getFirstItem();
        return $o && $o->getId() ? $o : null;
    }

    public function byMerchantOrderId(string $merchantOrderId): ?Order
    {
        if ($merchantOrderId === '' || !preg_match('/^MAG-[A-Z0-9-]{8,40}$/', $merchantOrderId)) {
            return null;
        }
        $c = $this->collectionFactory->create();
        $c->join(['p' => 'sales_order_payment'], 'p.parent_id = main_table.entity_id', [])
          ->addFieldToFilter('p.method', Config::CODE)
          ->addFieldToFilter('p.additional_information', ['like' => '%"' . OrderSettler::META_MERCHANT_ORDER . '":"' . $merchantOrderId . '"%'])
          ->setPageSize(1);
        $o = $c->getFirstItem();
        return $o && $o->getId() ? $o : null;
    }

    /** @return OrderInterface|Order|null */
    public function find(string $merchantOrderId, string $directOrderId): ?Order
    {
        return $this->byDirectOrderId($directOrderId) ?: $this->byMerchantOrderId($merchantOrderId);
    }
}
