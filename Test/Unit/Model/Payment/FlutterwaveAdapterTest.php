<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Model\Payment;

use Flutterwave\Payment\Block\Form\Flutterwave as FormBlock;
use Flutterwave\Payment\Block\Info\Flutterwave as InfoBlock;
use Flutterwave\Payment\Model\Api\Client;
use Flutterwave\Payment\Model\Logger\FlutterwaveSignozLogger;
use Flutterwave\Payment\Model\Payment\FlutterwaveAdapter;
use Magento\Checkout\Model\Session;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Config\ValueHandlerInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FlutterwaveAdapterTest extends TestCase
{
    private Client&MockObject $client;
    private Session&MockObject $session;
    private UrlInterface&MockObject $urlBuilder;
    private FlutterwaveSignozLogger&MockObject $signoz;
    private array $config = ['title' => 'Flutterwave', 'api_key' => 'FLWSECK_TEST'];

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->addMethods(['setFlutterwavePaymentUrl'])
            ->getMock();
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->urlBuilder->method('getUrl')->with('flutterwave/payment/callback', ['_secure' => true])->willReturn('https://shop.test/callback');
        $this->signoz = $this->createMock(FlutterwaveSignozLogger::class);
    }

    private function createAdapter(?FlutterwaveSignozLogger $signoz): FlutterwaveAdapter
    {
        $handler = $this->createMock(ValueHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(fn (array $subject) => $this->config[$subject['field']] ?? null);
        $pool = $this->createMock(ValueHandlerPoolInterface::class);
        $pool->method('get')->willReturn($handler);

        return new FlutterwaveAdapter(
            $this->createMock(ManagerInterface::class),
            $pool,
            $this->createMock(PaymentDataObjectFactory::class),
            FlutterwaveAdapter::CODE,
            FormBlock::class,
            InfoBlock::class,
            $this->client,
            $this->session,
            $this->urlBuilder,
            null,
            null,
            null,
            $this->createMock(LoggerInterface::class),
            $signoz
        );
    }

    private function givenOrderOn(FlutterwaveAdapter $adapter): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000055');
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getCustomerName')->willReturn('Ada Buyer');
        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $adapter->setInfoInstance($payment);
    }

    public function testInitializeCreatesLinkAndSetsPendingPayment(): void
    {
        $adapter = $this->createAdapter($this->signoz);
        $this->givenOrderOn($adapter);

        $this->signoz->expects($this->once())->method('trackRequestSent')->with(
            'payment_link',
            $this->matchesRegularExpression('/^MAG_000000055_ab[0-9a-f]+$/'),
            '/v3/payments',
            ['order_id' => '000000055', 'amount' => 100.0, 'currency' => 'NGN']
        );
        $this->client->expects($this->once())->method('createPaymentLink')->with($this->callback(function (array $payload): bool {
            $this->assertMatchesRegularExpression('/^MAG_000000055_ab[0-9a-f]+$/', $payload['tx_ref']);
            $this->assertSame('https://shop.test/callback', $payload['redirect_url']);
            $this->assertSame(['email' => 'buyer@example.com', 'name' => 'Ada Buyer'], $payload['customer']);
            $this->assertSame(['title' => 'Flutterwave', 'logo' => ''], $payload['customizations']);
            return true;
        }))->willReturn(['link' => 'https://checkout.flutterwave.com/x']);
        $this->session->expects($this->once())->method('setFlutterwavePaymentUrl')->with('https://checkout.flutterwave.com/x');

        $state = new DataObject();
        $this->assertSame($adapter, $adapter->initialize('authorize', $state));
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $state->getState());
        $this->assertSame('pending_payment', $state->getStatus());
        $this->assertFalse($state->getIsNotified());
    }

    public function testInitializeWorksWithoutSignozLoggerAndFailsWithoutLink(): void
    {
        $adapter = $this->createAdapter(null);
        $this->givenOrderOn($adapter);
        $this->client->method('createPaymentLink')->willReturn(false);
        $this->session->expects($this->never())->method('setFlutterwavePaymentUrl');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Could not generate Flutterwave payment link');
        $adapter->initialize('authorize', new DataObject());
    }

    public function testIsAvailableRequiresApiKey(): void
    {
        $this->assertTrue($this->createAdapter(null)->isAvailable());

        $this->config['api_key'] = '';
        $this->assertFalse($this->createAdapter(null)->isAvailable());
    }
}
