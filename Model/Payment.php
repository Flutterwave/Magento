<?php
namespace Flutterwave\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\Order;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Payment extends AbstractMethod
{
    protected $_code = 'flutterwave';
    protected $_isOffline = false;
    protected $checkoutSession;
    protected $order;
    protected $curl;
    protected $urlBuilder;
    protected $scopeConfig;

    public function __construct(
        Session $checkoutSession,
        Order $order,
        Curl $curl,
        UrlInterface $urlBuilder,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->order = $order;
        $this->curl = $curl;
        $this->urlBuilder = $urlBuilder;
        $this->scopeConfig = $scopeConfig;
    }

    public function startTransaction()
    {
        $order = $this->checkoutSession->getLastRealOrder();
        $amount = $order->getGrandTotal();
        $currency = $order->getOrderCurrencyCode();
        $orderId = $order->getIncrementId();
        $email = $order->getCustomerEmail();

        $apiKey = $this->scopeConfig->getValue('payment/flutterwave/api_key', ScopeInterface::SCOPE_STORE);
        $callbackUrl = $this->urlBuilder->getUrl('flutterwave/payment/callback')."?client_reference=". $orderId;

        $requestData = [
            'amount' => $amount,
            'email' => $email,
            'currency' => $currency,
            'reference' => "MAG_".$order->getIncrementId()."_". uniqid('old'),
            'callback' => $callbackUrl
        ];

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json'
        ]);

        $this->curl->post('https://api.flutterwave.com/v3/payments', json_encode($requestData));
        $response = json_decode($this->curl->getBody(), true);

        if (isset($response['data']['link'])) {
            return $response['data']['link'];
        }
        return false;
    }

    public function verifyTransaction(string $reference) {
        $apiKey = $this->scopeConfig->getValue('payment/flutterwave/api_key', ScopeInterface::SCOPE_STORE);

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json'
        ]);

        $this->curl->get('https://api.flutterwave.com/v3/transactions/' . $reference . '/verify');
        $response = json_decode($this->curl->getBody(), true);

        if( isset( $response[ 'data' ] ) ) {
            return [
                'amount' => $response['data']['amount'],
                'currency' => $response['data']['currency'],
                'status' => $response['data']['status'],
            ];
        }

        return [];
    }
}
