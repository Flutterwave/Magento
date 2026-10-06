<?php
namespace Flutterwave\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;

class VerifyCommand implements CommandInterface
{
    /**
     * @var \Flutterwave\Payment\Model\Api\Client
     */
    private $apiClient;

    public function __construct(
        \Flutterwave\Payment\Model\Api\Client $apiClient
    ) {
        $this->apiClient = $apiClient;
    }

    public function execute(array $commandSubject)
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();
        $transactionId = $commandSubject['transaction_id'];
        
        $response = $this->apiClient->verifyTransaction($transactionId);
        
        if (($response['status'] ?? null) !== 'successful') {
            throw new \Exception('Payment verification failed');
        }
        
        $payment->setTransactionId($transactionId)
            ->setIsTransactionClosed(0)
            ->setAdditionalInformation('flutterwave_response', json_encode($response));
        
        if ($payment->getMethodInstance()->getConfigPaymentAction() === 'authorize_capture') {
            $payment->registerCaptureNotification($response['amount']);
        } else {
            $payment->authorize(true, $response['amount']);
        }
    }
}