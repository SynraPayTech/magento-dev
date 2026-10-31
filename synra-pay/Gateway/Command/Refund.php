<?php
declare(strict_types=1);

namespace Synra\Pay\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Synra\Pay\Model\Api\Client;
use Synra\Pay\Model\Log;
use Synra\Pay\Model\OrderSettler;

/**
 * Credit memo → refund at SynraPay. PLACEHOLDER route (Client::PATH_REFUND),
 * same contract as the WooCommerce plugin, until the payment service
 * publishes the real one.
 */
class Refund implements CommandInterface
{
    /** @var Client */
    private $client;
    /** @var Log */
    private $log;

    public function __construct(Client $client, Log $log)
    {
        $this->client = $client;
        $this->log    = $log;
    }

    public function execute(array $commandSubject)
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $amount    = SubjectReader::readAmount($commandSubject);
        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        $order   = $payment->getOrder();
        $direct  = (string) $payment->getAdditionalInformation(OrderSettler::META_DIRECT_ORDER);
        if ($direct === '') {
            throw new LocalizedException(__('No SynraPay transaction on this order.'));
        }
        list($ok, $data, $err) = $this->client->refund([
            'order_id' => $direct,
            'amount'   => number_format(round((float) $amount, 2), 2, '.', ''),
            'currency' => strtoupper((string) $order->getOrderCurrencyCode()),
            'reason'   => 'Refund from Magento order #' . $order->getIncrementId(),
        ]);
        if (!$ok) {
            $this->log->error('refund failed on ' . $direct . ': ' . $err);
            throw new LocalizedException(__('SynraPay refund failed: %1', $err));
        }
        $refundId = isset($data['refund_id']) ? (string) $data['refund_id'] : (isset($data['refundId']) ? (string) $data['refundId'] : 'ok');
        $payment->setTransactionId($direct . '-refund-' . substr(md5((string) microtime(true)), 0, 8));
        $payment->setIsTransactionClosed(true);
        $order->addCommentToStatusHistory(sprintf('SynraPay: refund of %s %s requested (%s).', $order->getOrderCurrencyCode(), number_format((float) $amount, 2), $refundId), false, false);
        return null;
    }
}
