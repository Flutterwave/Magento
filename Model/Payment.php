<?php
namespace Flutterwave\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\Order;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Payment extends AbstractMethod
{
    public const TX_REF_PREFIX = 'MAG';
    public const STATUS_SUCCESSFUL = 'successful';

    protected $_code = 'flutterwave';
    protected $_isOffline = false;
    protected $checkoutSession;
    protected $order;
    protected $curl;
    protected $urlBuilder;
    protected $scopeConfig;
    protected $encryptor;

    public function __construct(
        Session $checkoutSession,
        Order $order,
        Curl $curl,
        UrlInterface $urlBuilder,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->order = $order;
        $this->curl = $curl;
        $this->urlBuilder = $urlBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    public function getSecretHash(): string
    {
        $value = (string) $this->scopeConfig->getValue('payment/flutterwave/secret_hash', ScopeInterface::SCOPE_STORE);
        return $value === '' ? '' : (string) $this->encryptor->decrypt($value);
    }

    /**
     * Extract the order increment id from a tx_ref built by startTransaction (MAG_<increment_id>_<unique>).
     */
    public function getOrderIncrementIdFromTxRef(string $txRef): ?string
    {
        $parts = explode('_', $txRef);
        if (count($parts) !== 3 || $parts[0] !== self::TX_REF_PREFIX || !ctype_digit($parts[1])) {
            return null;
        }
        return $parts[1];
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
            'currency' => $currency,
            'tx_ref' => self::TX_REF_PREFIX . "_" . $orderId . "_" . uniqid('old'),
            'redirect_url' => $callbackUrl,
            'customer' => ['email' => $email]
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

    /**
     * Verify a transaction by its Flutterwave transaction id (numeric), not by tx_ref.
     */
    public function verifyTransaction(string $transactionId) {
        if (!ctype_digit($transactionId)) {
            return [];
        }

        $apiKey = $this->scopeConfig->getValue('payment/flutterwave/api_key', ScopeInterface::SCOPE_STORE);

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json'
        ]);

        $this->curl->get('https://api.flutterwave.com/v3/transactions/' . $transactionId . '/verify');
        $response = json_decode($this->curl->getBody(), true);

        if (isset($response['data']['id'], $response['data']['tx_ref'], $response['data']['status'], $response['data']['amount'], $response['data']['currency'])) {
            return [
                'id' => (string) $response['data']['id'],
                'tx_ref' => (string) $response['data']['tx_ref'],
                'amount' => $response['data']['amount'],
                'currency' => (string) $response['data']['currency'],
                'status' => (string) $response['data']['status'],
            ];
        }

        return [];
    }
}
