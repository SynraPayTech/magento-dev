<?php
declare(strict_types=1);

namespace Synra\Pay\Model;

use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Settles a Magento order from what SynraPay says about the Direct API order:
 * paid → invoice (captured at SynraPay) + processing; lost → canceled;
 * amount mismatch → on hold for a human.
 */
class OrderSettler
{
    const META_DIRECT_ORDER   = 'synra_direct_order_id';
    const META_MERCHANT_ORDER = 'synra_merchant_order_id';
    const META_ENV            = 'synra_env';
    const META_AWAITING       = 'synra_awaiting_confirmation';
    const META_TRANSACTION    = 'synra_transaction_id';
    const META_AUTH_CODE      = 'synra_authorization_code';

    /** @var InvoiceService */
    private $invoiceService;
    /** @var TransactionFactory */
    private $transactionFactory;
    /** @var OrderRepositoryInterface */
    private $orderRepository;
    /** @var OrderSender */
    private $orderSender;
    /** @var Log */
    private $log;

    public function __construct(
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        Log $log
    ) {
        $this->invoiceService     = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->orderRepository    = $orderRepository;
        $this->orderSender        = $orderSender;
        $this->log                = $log;
    }

    public function isPaid(Order $order): bool
    {
        return (float) $order->getTotalPaid() > 0
            || in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true);
    }

    public function isFinalLost(Order $order): bool
    {
        return in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true);
    }

    public static function amountOf(Order $order): string
    {
        return number_format(round((float) $order->getGrandTotal(), 2), 2, '.', '');
    }

    /**
     * The Transaction ID the merchant dashboard shows: transaction_id, which every
     * Direct API answer carries and which always equals the Direct API order_id
     * (one transaction id). Falls back to the order id when the answer lacks it.
     */
    public static function transactionIdOf(array $data, string $direct): string
    {
        return !empty($data['transaction_id']) ? (string) $data['transaction_id'] : $direct;
    }

    /**
     * The order is paid at SynraPay: record it once. $data is the Direct API
     * status answer (or the webhook body mapped to its keys).
     */
    public function markPaid(Order $order, array $data, string $source): void
    {
        if ($this->isPaid($order)) {
            return;
        }
        $payment = $order->getPayment();
        $direct  = isset($data['order_id']) ? (string) $data['order_id'] : (string) $payment->getAdditionalInformation(self::META_DIRECT_ORDER);
        $paidAmt = isset($data['amount']) ? number_format(round((float) $data['amount'], 2), 2, '.', '') : null;
        $paidCcy = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
        $total   = self::amountOf($order);

        if ($paidAmt !== null && ($paidAmt !== $total || ($paidCcy && $paidCcy !== strtoupper((string) $order->getOrderCurrencyCode())))) {
            $this->hold($order, sprintf(
                'SynraPay: %s reports %s %s paid but the order total is %s %s — review before fulfilling.',
                $source, (string) $paidCcy, (string) $paidAmt, (string) $order->getOrderCurrencyCode(), $total
            ));
            return;
        }

        // The Direct API order id stays in META_DIRECT_ORDER (status and refund
        // calls key on it); what Magento shows as the transaction is the
        // dashboard's Transaction ID, which is the same value.
        $txnId      = self::transactionIdOf($data, $direct);
        $authCode   = !empty($data['authorization_code']) ? (string) $data['authorization_code'] : '';
        $merchantId = (string) $payment->getAdditionalInformation(self::META_MERCHANT_ORDER);
        if ($merchantId === '' && !empty($data['merchant_order_id'])) {
            $merchantId = (string) $data['merchant_order_id'];
            $payment->setAdditionalInformation(self::META_MERCHANT_ORDER, $merchantId);
        }
        $payment->setAdditionalInformation(self::META_DIRECT_ORDER, $direct);
        $payment->setAdditionalInformation(self::META_TRANSACTION, $txnId);
        if ($authCode !== '') {
            $payment->setAdditionalInformation(self::META_AUTH_CODE, $authCode);
        }
        $payment->unsAdditionalInformation(self::META_AWAITING);
        $payment->setTransactionId($txnId);
        $payment->setLastTransId($txnId);
        $payment->setIsTransactionClosed(true);
        $payment->addTransaction(Transaction::TYPE_CAPTURE);

        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->setTransactionId($txnId);
            $invoice->register();
            $invoice->getOrder()->setIsInProcess(true);
            $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder())->save();
        }

        $status = strtolower(isset($data['status']) ? (string) $data['status'] : 'captured');
        $order->setState(Order::STATE_PROCESSING)->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        $order->addCommentToStatusHistory(sprintf(
            'SynraPay: payment %s (hosted session, via %s). Merchant order ID %s, Transaction ID %s%s',
            $status, $source, $merchantId !== '' ? $merchantId : '-', $txnId,
            $authCode !== '' ? ', auth code ' . $authCode : ''
        ), false, false);
        $order->setCanSendNewEmailFlag(true);
        $this->orderRepository->save($order);
        $this->log->info('order #' . $order->getIncrementId() . ' paid via ' . $source . ' (transaction ' . $txnId . ', order ' . $direct . ')');

        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }
        } catch (\Throwable $e) {
            $this->log->warning('order email failed for #' . $order->getIncrementId() . ': ' . $e->getMessage());
        }
    }

    /** The payment was lost (failed, cancelled, expired, declined): the order is canceled, once. */
    public function markFailed(Order $order, string $reason, string $source): void
    {
        if ($this->isPaid($order) || $this->isFinalLost($order)) {
            return;
        }
        $comment = sprintf('SynraPay: payment %s (via %s).', $reason, $source);
        if ($order->canCancel()) {
            $order->cancel();
            $order->addCommentToStatusHistory($comment, false, false);
        } else {
            $order->addCommentToStatusHistory($comment, false, false);
        }
        $this->orderRepository->save($order);
        $this->log->info('order #' . $order->getIncrementId() . ' failed via ' . $source . ': ' . $reason);
    }

    /** Approved in the card frame but not final at SynraPay yet: remembered so the cron re-checks it. */
    public function markAwaiting(Order $order): void
    {
        $payment = $order->getPayment();
        if ($payment->getAdditionalInformation(self::META_AWAITING)) {
            return;
        }
        $payment->setAdditionalInformation(self::META_AWAITING, gmdate('c'));
        $order->addCommentToStatusHistory(
            'SynraPay: the card frame reported the payment approved; waiting for SynraPay\'s final confirmation (webhook or re-check) before the order is marked paid.',
            false,
            false
        );
        $this->orderRepository->save($order);
    }

    public function hold(Order $order, string $comment): void
    {
        if ($order->canHold()) {
            $order->hold();
        }
        $order->addCommentToStatusHistory($comment, false, false);
        $this->orderRepository->save($order);
        $this->log->warning('order #' . $order->getIncrementId() . ' on hold: ' . $comment);
    }

    /**
     * Apply a Direct API status answer to the order. Returns 'paid', 'failed'
     * or 'pending'.
     */
    public function applyStatus(Order $order, array $data, string $source): string
    {
        $status   = strtoupper(isset($data['status']) ? (string) $data['status'] : '');
        $awaiting = isset($data['confirmation']) && $data['confirmation'] === 'awaiting_mastercard';
        if (!$awaiting && in_array($status, ['CAPTURED', 'AUTHORIZED'], true)) {
            $this->markPaid($order, $data, $source);
            return 'paid';
        }
        if (!$awaiting && in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED', 'DECLINED'], true)) {
            $reason = !empty($data['error_message']) ? (string) $data['error_message'] : (!empty($data['reason']) ? (string) $data['reason'] : strtolower($status));
            $this->markFailed($order, $reason, $source);
            return 'failed';
        }
        return 'pending';
    }
}
