<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Model\Api;

use Flutterwave\Payment\Model\Api\Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private Curl&MockObject $curl;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private UrlInterface&MockObject $urlBuilder;
    private Client $client;

    private array $payload = [
        'amount' => 100,
        'currency' => 'NGN',
        'order_id' => '000000055',
        'tx_ref' => 'MAG_000000055_ab1',
        'customer' => ['email' => 'buyer@example.com', 'name' => 'Ada Buyer'],
    ];

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->client = new Client($this->curl, $this->scopeConfig, $this->urlBuilder);
    }

    public function testCreatePaymentLinkPostsPayloadWithRedirectUrl(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('payment/flutterwave/api_key', ScopeInterface::SCOPE_STORE)
            ->willReturn('FLWSECK_TEST');
        $this->urlBuilder->method('getUrl')->with('flutterwave/payment/callback')->willReturn('https://shop.test/callback');

        $this->curl->expects($this->once())->method('setHeaders')->with([
            'Authorization' => 'Bearer FLWSECK_TEST',
            'Content-Type' => 'application/json',
        ]);
        $this->curl->expects($this->once())->method('post')->with(
            'https://api.flutterwave.com/v3/payments',
            json_encode($this->payload + ['redirect_url' => 'https://shop.test/callback'])
        );
        $this->curl->method('getBody')->willReturn(json_encode(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/x']]));

        $this->assertSame(['link' => 'https://checkout.flutterwave.com/x'], $this->client->createPaymentLink($this->payload));
    }

    public function testCreatePaymentLinkReturnsFalseOnError(): void
    {
        $this->curl->method('getBody')->willReturn(json_encode(['status' => 'error', 'message' => 'Invalid key']));

        $this->assertFalse($this->client->createPaymentLink($this->payload));
    }

    public function testVerifyTransactionIsNotImplemented(): void
    {
        $this->curl->expects($this->never())->method('get');

        $this->assertNull($this->client->verifyTransaction('999111'));
    }
}
