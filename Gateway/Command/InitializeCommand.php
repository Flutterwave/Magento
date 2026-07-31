<?php
namespace Flutterwave\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;

class InitializeCommand implements CommandInterface
{
    /**
     * @var \Flutterwave\Payment\Model\Api\Client
     */
    private $apiClient;
    
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    public function __construct(
        \Flutterwave\Payment\Model\Api\Client $apiClient,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->apiClient = $apiClient;
        $this->checkoutSession = $checkoutSession;
    }

    public function execute(array $commandSubject)
    {
        $stateObject = SubjectReader::readStateObject($commandSubject);
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();
        
        $response = $this->apiClient->createPaymentLink([
            'tx_ref' => $order->getIncrementId(),
            'amount' => $order->getGrandTotal(),
            'currency' => $order->getOrderCurrencyCode(),
            'redirect_url' => $this->getCallbackUrl($order->getStoreId()),
            'customer' => [
                'email' => $order->getCustomerEmail(),
                'name' => $order->getCustomerName()
            ]
        ]);

        $this->checkoutSession->setFlutterwavePaymentUrl($response['link']);
        
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);
    }

    private function getCallbackUrl($storeId)
    {
        // Implement URL generation with store context
        return '';
    }
}