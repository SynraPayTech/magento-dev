<?php
declare(strict_types=1);

namespace Synra\Pay\Controller\Hs;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Synra\Pay\Model\Api\Client;
use Synra\Pay\Model\Config;
use Synra\Pay\Model\HostedSession;
use Synra\Pay\Model\Log;
use Synra\Pay\Model\OrderSettler;

/**
 * POST /synra/hs/confirm — after fields.submit(), the page asks whether the
 * ORDER is paid. GET /direct/payment/{order_id}/status decides; the page's
 * own view of the result is never trusted. Only the order this checkout
 * session just placed can be asked about.
 *
 *   paid    → { status:'paid', redirect }   invoice + processing, cart emptied
 *   failed  → { status:'failed', error }    order canceled, quote restored for a retry
 *   pending → { status:'pending' }          ask again
 */
class Confirm extends CsrfJson implements HttpPostActionInterface
{
    /** @var HttpRequest */
    private $request;
    /** @var JsonFactory */
    private $jsonFactory;
    /** @var CheckoutSession */
    private $checkoutSession;
    /** @var OrderRepositoryInterface */
    private $orderRepository;
    /** @var HostedSession */
    private $hostedSession;
    /** @var OrderSettler */
    private $settler;
    /** @var Client */
    private $client;
    /** @var Config */
    private $config;
    /** @var CacheInterface */
    private $cache;
    /** @var UrlInterface */
    private $urlBuilder;
    /** @var Log */
    private $log;

    public function __construct(
        HttpRequest $request,
        HttpResponse $response,
        FormKeyValidator $formKeyValidator,
        JsonFactory $jsonFactory,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        HostedSession $hostedSession,
        OrderSettler $settler,
        Client $client,
        Config $config,
        CacheInterface $cache,
        UrlInterface $urlBuilder,
        Log $log
    ) {
        $this->request         = $request;
        $this->response        = $response;
        $this->formKeyValidator = $formKeyValidator;
        $this->jsonFactory     = $jsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->hostedSession   = $hostedSession;
        $this->settler         = $settler;
        $this->client          = $client;
        $this->config          = $config;
        $this->cache           = $cache;
        $this->urlBuilder      = $urlBuilder;
        $this->log             = $log;
    }

    public function execute()
    {
        $json = $this->jsonFactory->create();
        try {
            // Polling every 1.5 s for 90 s is 60 reads; allow a few payments' worth.
            $this->hostedSession->guard('confirm', 300, 15 * 60);
        } catch (LocalizedException $e) {
            return $json->setHttpResponseCode(429)->setData(['ok' => false, 'status' => 'error', 'error' => $e->getMessage()]);
        }

        $postedId = (int) $this->request->getParam('order_id', 0);
        $lastId   = (int) $this->checkoutSession->getLastOrderId();
        if ($postedId <= 0 || $lastId <= 0 || $postedId !== $lastId) {
            return $json->setHttpResponseCode(404)->setData(['ok' => false, 'status' => 'error', 'error' => (string) __('Order not found.')]);
        }
        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($lastId);
        } catch (\Throwable $e) {
            return $json->setHttpResponseCode(404)->setData(['ok' => false, 'status' => 'error', 'error' => (string) __('Order not found.')]);
        }
        if ($order->getPayment()->getMethod() !== Config::CODE) {
            return $json->setHttpResponseCode(400)->setData(['ok' => false, 'status' => 'error', 'error' => (string) __('Not a SynraPay order.')]);
        }
        if ($this->settler->isPaid($order)) {
            return $json->setData($this->paid());
        }
        if ($this->settler->isFinalLost($order)) {
            return $json->setData(['ok' => false, 'status' => 'failed', 'error' => (string) __('Your payment was not completed.')]);
        }

        $direct = (string) $order->getPayment()->getAdditionalInformation(OrderSettler::META_DIRECT_ORDER);
        if ($direct === '') {
            return $json->setHttpResponseCode(400)->setData(['ok' => false, 'status' => 'error', 'error' => (string) __('No card session on this order.')]);
        }

        $frameOk = (string) $this->request->getParam('frame_ok', '') !== '';
        // Approved in the frame already: give the status read 6 s, then move on
        // (pending + success page below) rather than keep the shopper waiting.
        $cacheKey = 'synra_hs_st_' . md5($direct);
        $cached   = $this->cache->load($cacheKey);
        $cached   = $cached ? json_decode((string) $cached, true) : null;
        if (is_array($cached) && count($cached) === 3) {
            list($ok, $data, $err) = $cached;
        } else {
            list($ok, $data, $err) = $this->client->status($direct, $frameOk ? 6 : 45);
            $this->cache->save((string) json_encode([$ok, $data, $err]), $cacheKey, ['SYNRA_PAY'], 2);
        }
        if (!$ok && !$frameOk) {
            return $json->setData(['ok' => false, 'status' => 'pending', 'error' => $err]);
        }
        if (!is_array($data)) {
            $data = ['status' => 'PENDING'];
        }

        $status   = strtoupper(isset($data['status']) ? (string) $data['status'] : '');
        $awaiting = isset($data['confirmation']) && $data['confirmation'] === 'awaiting_mastercard';
        $this->log->info('confirm #' . $order->getIncrementId() . ' → ' . $status . ($awaiting ? ' (awaiting_mastercard)' : '') . ($frameOk ? ' frame:ok' : ''));

        $finalPaid = !$awaiting && in_array($status, ['CAPTURED', 'AUTHORIZED'], true);
        $finalLost = !$awaiting && in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED', 'DECLINED'], true);

        // Approved in the card frame but not yet final at SynraPay (PENDING, or
        // CAPTURED still awaiting Mastercard's notification): do not keep the
        // shopper waiting. The order stays pending_payment and is settled by
        // the webhook or the cron re-check.
        if ($frameOk && !$finalPaid && !$finalLost) {
            $this->settler->markAwaiting($order);
            $this->hostedSession->clear();
            return $json->setData($this->paid());
        }

        $outcome = $this->settler->applyStatus($order, $data, 'confirm');
        if ($outcome === 'paid') {
            $this->hostedSession->clear();
            return $json->setData($this->paid());
        }
        if ($outcome === 'failed') {
            $reason = !empty($data['error_message']) ? (string) $data['error_message'] : (!empty($data['reason']) ? (string) $data['reason'] : strtolower($status));
            // The quote comes back so the shopper can place the order again;
            // a failed Direct API order cannot be paid, so the next session
            // opens a new one.
            $this->hostedSession->clear();
            $this->checkoutSession->restoreQuote();
            return $json->setData(['ok' => false, 'status' => 'failed', 'error' => $reason]);
        }
        return $json->setData(['ok' => false, 'status' => 'pending']);
    }

    private function paid(): array
    {
        return ['ok' => true, 'status' => 'paid', 'redirect' => $this->urlBuilder->getUrl('checkout/onepage/success', ['_secure' => true])];
    }
}
