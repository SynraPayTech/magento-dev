<?php
declare(strict_types=1);

namespace Synra\Pay\Controller\Hs;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;

/**
 * The checkout page's AJAX calls carry Magento's form_key. A missing or stale
 * one is answered with a JSON 403 the page can show ("refresh the page")
 * instead of Magento's HTML redirect.
 */
abstract class CsrfJson implements CsrfAwareActionInterface
{
    /** @var FormKeyValidator */
    protected $formKeyValidator;
    /** @var HttpResponse */
    protected $response;

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $this->response->setHttpResponseCode(403);
        $this->response->setHeader('Content-Type', 'application/json', true);
        $this->response->setContent((string) json_encode([
            'ok'     => false,
            'status' => 'error',
            'error'  => (string) __('Your session expired. Please refresh the page.'),
        ]));
        return new InvalidRequestException($this->response);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }
}
