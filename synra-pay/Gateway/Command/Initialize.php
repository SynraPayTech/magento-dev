<?php
declare(strict_types=1);

namespace Synra\Pay\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Synra\Pay\Model\Config;
use Synra\Pay\Model\HostedSession;
use Synra\Pay\Model\Log;
use Synra\Pay\Model\OrderSettler;

/**
 * "Place Order" with hosted session: Magento has validated the quote and is
 * creating the order. Link it to the Direct API order the card fields were
 * opened on (same amount and currency, or refuse) and leave it pending — the
 * page then submits the card and asks the store to confirm. No charge happens
 * here and no order email goes out until the payment is confirmed.
 */
class Initialize implements CommandInterface
{
    /** @var HostedSession */
    private $hostedSession;
    /** @var Config */
    private $config;
    /** @var Log */
    private $log;

    public function __construct(HostedSession $hostedSession, Config $config, Log $log)
    {
        $this->hostedSession = $hostedSession;
        $this->config        = $config;
        $this->log           = $log;
    }

    public function execute(array $commandSubject)
    {
        $paymentDO   = SubjectReader::readPayment($commandSubject);
        $stateObject = isset($commandSubject['stateObject']) ? $commandSubject['stateObject'] : null;
        if (!$stateObject instanceof \Magento\Framework\DataObject) {
            throw new CommandException(__('State object should be provided.'));
        }
        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        /** @var Order $order */
        $order = $payment->getOrder();

        $posted = (string) $payment->getAdditionalInformation(HostedSession::HS_FIELD);
        $hs     = $this->hostedSession->posted($posted);
        if (!$hs) {
            throw new LocalizedException(__('Please complete your card details first.'));
        }
        if ((int) $hs['quote_id'] !== (int) $order->getQuoteId()) {
            $this->hostedSession->clear();
            throw new LocalizedException(__('The card form belongs to another cart. It has been refreshed — please place the order again.'));
        }

        $total = OrderSettler::amountOf($order);
        if ((string) $hs['amount'] !== $total || strtoupper((string) $hs['currency']) !== strtoupper((string) $order->getOrderCurrencyCode())) {
            $this->hostedSession->clear();
            $this->log->info('total changed: session ' . $hs['amount'] . ' ' . $hs['currency'] . ' vs order ' . $total . ' ' . $order->getOrderCurrencyCode());
            throw new LocalizedException(__('Your order total changed. The card form has been refreshed — please place the order again.'));
        }

        $payment->unsAdditionalInformation(HostedSession::HS_FIELD);
        $payment->setAdditionalInformation(OrderSettler::META_DIRECT_ORDER, $hs['order_id']);
        $payment->setAdditionalInformation(OrderSettler::META_MERCHANT_ORDER, $hs['merchant_order_id']);
        $payment->setAdditionalInformation(OrderSettler::META_ENV, $this->config->mode());
        $payment->setLastTransId($hs['order_id']);
        $order->setExtOrderId($hs['order_id']);
        $order->setCanSendNewEmailFlag(false);

        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PENDING_PAYMENT));
        $stateObject->setIsNotified(false);

        $order->addCommentToStatusHistory(sprintf('SynraPay: awaiting card payment (hosted session, order %s).', $hs['merchant_order_id']), false, false);
        return null;
    }
}
