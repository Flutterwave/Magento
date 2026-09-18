<?php
namespace Flutterwave\Payment\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;

class Client {
    protected $curl;
    protected $scopeConfig;
    protected $urlBuilder;

    public function __construct(
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder
    )
    {
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
    }

    public function createPaymentLink(array $payload = []) {
        $amount = $payload['amount'];
        $currency = $payload['currency'];
        $orderId = $payload['order_id'];
        $email = $payload['customer']['email'];
        $reference = $payload['tx_ref'];

        $apiKey = $this->scopeConfig->getValue('payment/flutterwave/api_key', ScopeInterface::SCOPE_STORE);
        $callbackUrl = $this->urlBuilder->getUrl('flutterwave/payment/callback');

        $requestData = [
            ... $payload,
            'redirect_url' => $callbackUrl
        ];

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json'
        ]);

        $this->curl->post('https://api.flutterwave.com/v3/payments', json_encode($requestData));
        $response = json_decode($this->curl->getBody(), true);

        // Callers read $response['link'], so return the response data rather than the bare link.
        if (isset($response['data']['link'])) {
            return $response['data'];
        }
        return false;
    }

    public function verifyTransaction(string $transactionId) {

    }
}
