<?php
declare(strict_types=1);

namespace Synra\Pay\Controller\Hs;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Synra\Pay\Model\Config;
use Synra\Pay\Model\HostedSession;
use Synra\Pay\Model\Log;

/**
 * POST /synra/hs/session — the checkout page asks for card fields.
 *
 * Creates the Direct API order for the quote (or reuses the one already open
 * for the same amount, currency and customer) and opens a hosted session on
 * it. Only the client_token goes back; the signing key never does. The
 * form_key is checked by Magento's CSRF validator before this runs.
 */
class Session extends CsrfJson implements HttpPostActionInterface
{
    /** @var HttpRequest */
    private $request;
    /** @var JsonFactory */
    private $jsonFactory;
    /** @var HostedSession */
    private $hostedSession;
    /** @var Config */
    private $config;
    /** @var Log */
    private $log;

    public function __construct(
        HttpRequest $request,
        HttpResponse $response,
        FormKeyValidator $formKeyValidator,
        JsonFactory $jsonFactory,
        HostedSession $hostedSession,
        Config $config,
        Log $log
    ) {
        $this->response         = $response;
        $this->formKeyValidator = $formKeyValidator;
        $this->request       = $request;
        $this->jsonFactory   = $jsonFactory;
        $this->hostedSession = $hostedSession;
        $this->config        = $config;
        $this->log           = $log;
    }

    public function execute()
    {
        $json = $this->jsonFactory->create();
        if (!$this->config->isActive()) {
            return $json->setHttpResponseCode(404)->setData(['ok' => false, 'error' => (string) __('Hosted session is not enabled.')]);
        }
        try {
            // 30 sessions per shopper and 60 per IP in 15 minutes: a checkout needs a handful.
            $this->hostedSession->guard('session', 30, 15 * 60);
            $input = [];
            foreach (['email', 'phone', 'phone_country_code', 'country', 'first_name', 'last_name'] as $k) {
                $input[$k] = (string) $this->request->getParam($k, '');
            }
            $fresh = (string) $this->request->getParam('fresh', '') !== '';
            $out   = $this->hostedSession->open($input, $fresh);
            return $json->setData(['ok' => true] + $out);
        } catch (LocalizedException $e) {
            return $json->setData(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->log->error('session: ' . $e->getMessage());
            return $json->setHttpResponseCode(500)->setData(['ok' => false, 'error' => (string) __('Could not start the card session.')]);
        }
    }
}
