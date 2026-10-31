<?php
declare(strict_types=1);

namespace Synra\Pay\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Synra\Pay\Model\Config;
use Synra\Pay\Model\Log;
use Synra\Pay\Model\OrderFinder;
use Synra\Pay\Model\OrderSettler;

/**
 * POST /synra/webhook/index/token/<per-store token> — SynraPay's signed webhook,
 * the source of truth for every order.
 *
 *   x-synrapay-signature          HMAC-SHA512( "{timestamp}.{rawBody}", signing secret )   hex
 *   x-synrapay-timestamp          unix seconds, refused when older than 5 minutes
 *   x-synrapay-signature-version  v2
 *   <x_api_key_name>              <x_api_key>   (optional second factor)
 *
 * Body: { mid, orderId (merchant_order_id), transactionId (Direct order id),
 *         amount, currency, status: success|failed|cancelled|expired, … }
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** @var HttpRequest */
    private $request;
    /** @var RawFactory */
    private $rawFactory;
    /** @var Config */
    private $config;
    /** @var OrderFinder */
    private $finder;
    /** @var OrderSettler */
    private $settler;
    /** @var Log */
    private $log;

    public function __construct(HttpRequest $request, RawFactory $rawFactory, Config $config, OrderFinder $finder, OrderSettler $settler, Log $log)
    {
        $this->request    = $request;
        $this->rawFactory = $rawFactory;
        $this->config     = $config;
        $this->finder     = $finder;
        $this->settler    = $settler;
        $this->log        = $log;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $res = $this->rawFactory->create();

        $token = (string) $this->request->getParam('token', '');
        if ($token === '' || !hash_equals($this->config->webhookToken(), $token)) {
            return $res->setHttpResponseCode(404)->setContents('not found');
        }

        $raw  = (string) $this->request->getContent();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return $res->setHttpResponseCode(400)->setContents('invalid payload');
        }
        if (!$this->verify($raw)) {
            $this->log->warning('webhook refused: signature did not verify (event ' . (string) $this->request->getHeader('x-synrapay-event') . ')');
            return $res->setHttpResponseCode(401)->setContents('invalid signature');
        }

        $merchantOrderId = isset($body['orderId']) ? (string) $body['orderId'] : (isset($body['merchant_order_id']) ? (string) $body['merchant_order_id'] : '');
        $directOrderId   = isset($body['transactionId']) ? (string) $body['transactionId'] : (isset($body['order_id']) ? (string) $body['order_id'] : '');
        $status          = strtolower((string) (isset($body['status']) ? $body['status'] : ''));

        $order = $this->finder->find($merchantOrderId, $directOrderId);
        if (!$order) {
            $this->log->info('webhook: no order for ' . $merchantOrderId . ' / ' . $directOrderId . ' (' . $status . ')');
            return $res->setHttpResponseCode(200)->setContents('ok');
        }

        $data = [
            'order_id' => $directOrderId !== '' ? $directOrderId : (string) $order->getPayment()->getAdditionalInformation(OrderSettler::META_DIRECT_ORDER),
            'status'   => $status,
        ];
        if (isset($body['amount'])) {
            $data['amount'] = $body['amount'];
        }
        if (isset($body['currency'])) {
            $data['currency'] = $body['currency'];
        }
        if (!empty($body['gatewayTransactionId'])) {
            $data['gateway_transaction_id'] = (string) $body['gatewayTransactionId'];
        } elseif (!empty($body['gateway_transaction_id'])) {
            $data['gateway_transaction_id'] = (string) $body['gateway_transaction_id'];
        }

        if (in_array($status, ['success', 'captured', 'paid', 'authorized'], true)) {
            $data['status'] = 'CAPTURED';
            $this->settler->applyStatus($order, $data, 'webhook');
        } elseif (in_array($status, ['failed', 'cancelled', 'canceled', 'expired', 'declined'], true)) {
            $data['status'] = $status === 'canceled' ? 'CANCELLED' : strtoupper($status);
            if (!empty($body['reason'])) {
                $data['reason'] = (string) $body['reason'];
            }
            $this->settler->applyStatus($order, $data, 'webhook');
        } else {
            $this->log->info('webhook: ignored status ' . $status . ' for #' . $order->getIncrementId());
        }
        return $res->setHttpResponseCode(200)->setContents('ok');
    }

    private function verify(string $raw): bool
    {
        $secret = $this->config->webhookSecret();
        if ($secret === '' || $raw === '') {
            return false;
        }
        // SynraPay signs "timestamp.body" (v2) and always sends the timestamp: a
        // delivery without one, or an old one, is a replay and is refused.
        $ts = (int) $this->request->getHeader('x-synrapay-timestamp');
        if ($ts <= 0 || abs(time() - $ts) > 300) {
            return false;
        }
        $version = strtolower(trim((string) $this->request->getHeader('x-synrapay-signature-version')));
        if ($version !== 'v2' && $version !== '2') {
            return false;
        }
        $expected = hash_hmac('sha512', $ts . '.' . $raw, $secret);
        $received = strtolower(trim((string) $this->request->getHeader('x-synrapay-signature')));
        if ($received === '' || !hash_equals($expected, $received)) {
            return false;
        }
        $name = $this->config->webhookKeyName();
        $val  = $this->config->webhookKeyValue();
        if ($name !== '' && $val !== '') {
            $got = (string) $this->request->getHeader($name);
            if (!hash_equals($val, $got)) {
                return false;
            }
        }
        return true;
    }
}
