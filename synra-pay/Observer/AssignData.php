<?php
declare(strict_types=1);

namespace Synra\Pay\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use Synra\Pay\Model\HostedSession;

/**
 * additional_data.synra_hs_order_id (the Direct API order the card fields are
 * on) → payment additional_information, for the initialize command.
 */
class AssignData extends AbstractDataAssignObserver
{
    public function execute(Observer $observer)
    {
        $data = $this->readDataArgument($observer);
        $additional = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additional)) {
            return;
        }
        $payment = $this->readPaymentModelArgument($observer);
        $id = isset($additional[HostedSession::HS_FIELD]) ? trim((string) $additional[HostedSession::HS_FIELD]) : '';
        if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{6,80}$/', $id)) {
            $payment->setAdditionalInformation(HostedSession::HS_FIELD, $id);
        } else {
            $payment->unsAdditionalInformation(HostedSession::HS_FIELD);
        }
    }
}
