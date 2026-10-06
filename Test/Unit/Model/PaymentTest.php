<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Model;

use Flutterwave\Payment\Model\Payment;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PaymentTest extends TestCase
{
    private Session&MockObject $checkoutSession;
    private Curl&MockObject $curl;
    private UrlInterface&MockObject $urlBuilder;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private EncryptorInterface&MockObject $encryptor;
    private Payment $payment;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLastRealOrder'])
            ->getMock();
        $this->curl = $this->createMock(Curl::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);

        $this->payment = new Payment(
            $this->checkoutSession,
            $this->createMock(Order::class),
            $this->curl,
            $this->urlBuilder,
            $this->scopeConfig,
            $this->encryptor
        );
    }

    public function testGetSecretHashDecryptsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->with('payment/flutterwave/secret_hash')->willReturn('encrypted');
        $this->encryptor->expects($this->once())->method('decrypt')->with('encrypted')->willReturn('my-hash');

        $this->assertSame('my-hash', $this->payment->getSecretHash());
    }

    public function testGetSecretHashReturnsEmptyWhenNotConfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->encryptor->expects($this->never())->method('decrypt');

        $this->assertSame('', $this->payment->getSecretHash());
    }

    #[DataProvider('txRefProvider')]
    public function testGetOrderIncrementIdFromTxRef(string $txRef, ?string $expected): void
    {
        $this->assertSame($expected, $this->payment->getOrderIncrementIdFromTxRef($txRef));
    }

    public static function txRefProvider(): array
    {
        return [
            'valid' => ['MAG_000000055_old65f0a1', '000000055'],
            'wrong prefix' => ['WOO_000000055_old65f0a1', null],
            'non numeric id' => ['MAG_55a_old65f0a1', null],
            'missing suffix' => ['MAG_000000055', null],
            'extra segment' => ['MAG_000000055_x_y', null],
            'empty' => ['', null],
        ];
    }

    public function testStartTransactionSendsV3PayloadAndReturnsLink(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $order->method('getIncrementId')->willReturn('000000055');
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->scopeConfig->method('getValue')->with('payment/flutterwave/api_key')->willReturn('FLWSECK_TEST');
        $this->urlBuilder->method('getUrl')->with('flutterwave/payment/callback')->willReturn('https://shop.test/flutterwave/payment/callback/');

        $this->curl->expects($this->once())->method('setHeaders')->with([
            'Authorization' => 'Bearer FLWSECK_TEST',
            'Content-Type' => 'application/json',
        ]);
        $this->curl->expects($this->once())->method('post')->with(
            'https://api.flutterwave.com/v3/payments',
            $this->callback(function (string $body): bool {
                $data = json_decode($body, true);
                $this->assertSame(100.0, (float) $data['amount']);
                $this->assertSame('NGN', $data['currency']);
                $this->assertMatchesRegularExpression('/^MAG_000000055_old[0-9a-f]+$/', $data['tx_ref']);
                $this->assertSame('https://shop.test/flutterwave/payment/callback/?client_reference=000000055', $data['redirect_url']);
                $this->assertSame(['email' => 'buyer@example.com'], $data['customer']);
                $this->assertSame('000000055', $this->payment->getOrderIncrementIdFromTxRef($data['tx_ref']));
                return true;
            })
        );
        $this->curl->method('getBody')->willReturn(json_encode(['data' => ['link' => 'https://checkout.flutterwave.com/x']]));

        $this->assertSame('https://checkout.flutterwave.com/x', $this->payment->startTransaction());
    }

    public function testStartTransactionReturnsFalseWithoutLink(): void
    {
        $this->checkoutSession->method('getLastRealOrder')->willReturn($this->createMock(Order::class));
        $this->curl->method('getBody')->willReturn(json_encode(['status' => 'error']));

        $this->assertFalse($this->payment->startTransaction());
    }

    public function testVerifyTransactionReturnsNormalisedData(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('FLWSECK_TEST');
        $this->curl->expects($this->once())->method('get')->with('https://api.flutterwave.com/v3/transactions/999111/verify');
        $this->curl->method('getBody')->willReturn(json_encode([
            'status' => 'success',
            'data' => [
                'id' => 999111,
                'tx_ref' => 'MAG_000000055_old1',
                'amount' => 100,
                'currency' => 'NGN',
                'status' => 'successful',
            ],
        ]));

        $this->assertSame([
            'id' => '999111',
            'tx_ref' => 'MAG_000000055_old1',
            'amount' => 100,
            'currency' => 'NGN',
            'status' => 'successful',
        ], $this->payment->verifyTransaction('999111'));
    }

    #[DataProvider('nonNumericIdProvider')]
    public function testVerifyTransactionRejectsNonNumericIdWithoutCallingApi(string $id): void
    {
        $this->curl->expects($this->never())->method('get');

        $this->assertSame([], $this->payment->verifyTransaction($id));
    }

    public static function nonNumericIdProvider(): array
    {
        return [
            'tx_ref' => ['MAG_000000055_old1'],
            'path traversal' => ['1/../../payments'],
            'empty' => [''],
        ];
    }

    public function testVerifyTransactionReturnsEmptyForIncompleteResponse(): void
    {
        $this->curl->method('getBody')->willReturn(json_encode(['status' => 'error', 'data' => ['id' => 1]]));

        $this->assertSame([], $this->payment->verifyTransaction('1'));
    }
}
