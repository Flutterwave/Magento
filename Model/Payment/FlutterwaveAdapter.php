<?php
namespace Flutterwave\Payment\Model\Payment;

use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Validator\ValidatorPoolInterface;
use Magento\Payment\Model\Method\Adapter;
use Magento\Framework\Event\ManagerInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Command\CommandManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\UrlInterface;

class FlutterwaveAdapter extends Adapter
{
    const CODE = 'flutterwave';

    /**
     * @var \Flutterwave\Payment\Model\Api\Client
     */
    protected $apiClient;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    public function __construct(
        ManagerInterface $eventManager,
        ValueHandlerPoolInterface $valueHandlerPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        string $code,
        string $formBlockType,
        string $infoBlockType,
        \Flutterwave\Payment\Model\Api\Client $apiClient,
        \Magento\Checkout\Model\Session $checkoutSession,
        UrlInterface $urlBuilder,
        ?CommandPoolInterface $commandPool = null,
        ?ValidatorPoolInterface $validatorPool = null,
        ?CommandManagerInterface $commandExecutor = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct(
            $eventManager,
            $valueHandlerPool,
            $paymentDataObjectFactory,
            $code,
            $formBlockType,
            $infoBlockType,
            $commandPool,
            $validatorPool,
            $commandExecutor,
            $logger
        );

        $this->apiClient = $apiClient;
        $this->checkoutSession = $checkoutSession;
        $this->_urlBuilder = $urlBuilder;
        $this->_logger = $logger;
    }

    /**
     * Initialize payment
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order_id = $order->getIncrementId();

        $response = $this->apiClient->createPaymentLink([
            'tx_ref' => "MAG_". $order_id ."_". uniqid('ab'),
            'amount' => $order->getGrandTotal(),
            'currency' => $order->getOrderCurrencyCode(),
            'redirect_url' => $this->getCallbackUrl(),
            'order_id' => $order_id,
            'customer' => [
                'email' => $order->getCustomerEmail(),
                'name' => $order->getCustomerName()
            ],
            'customizations' => [
                'title' => $this->getConfigData('title'),
                'logo' => $this->getStoreLogoUrl()
            ]
        ]);

        if (empty($response['link'])) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Could not generate Flutterwave payment link')
            );
        }

        $this->checkoutSession->setFlutterwavePaymentUrl($response['link']);

        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        return $this;
    }

    public function isAvailable(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        // Check if API key is configured
        if (!$this->getConfigData('api_key')) {
            return false;
        }

        // Add any additional availability checks here
        return true;
    }

    protected function getCallbackUrl()
    {
        return $this->_urlBuilder->getUrl('flutterwave/payment/callback', ['_secure' => true]);
    }

    protected function getStoreLogoUrl()
    {
        // Implement your logo URL retrieval logic
        return '';
    }
}