<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Controller\Payment;

use Flutterwave\Payment\Controller\Payment\Callback;
use Flutterwave\Payment\Model\Logger\FlutterwaveSignozLogger;
use Flutterwave\Payment\Model\Payment;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CallbackTest extends TestCase
{
    private const TX_REF = 'MAG_000000055_old1';

    private Payment&MockObject $payment;
    private RequestInterface&MockObject $request;
    private ManagerInterface&MockObject $messageManager;
    private FlutterwaveSignozLogger&MockObject $signozLogger;
    private OrderFactory&MockObject $orderFactory;
    private Redirect&MockObject $redirect;
    private Order&MockObject $order;
    private OrderPayment&MockObject $orderPayment;
    private Callback $controller;

    private ?string $redirectPath = null;

    protected function setUp(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getOrderIncrementIdFromTxRef')->willReturnCallback(
            static fn (string $txRef) => $txRef === self::TX_REF ? '000000055' : null
        );

        $this->request = $this->createMock(RequestInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->signozLogger = $this->createMock(FlutterwaveSignozLogger::class);

        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnCallback(function (string $path) {
            $this->redirectPath = $path;
            return $this->redirect;
        });
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->orderPayment = $this->createMock(OrderPayment::class);
        $this->orderPayment->method('setLastTransId')->willReturnSelf();
        $this->orderPayment->method('setAdditionalInformation')->willReturnSelf();

        $this->order = $this->createMock(Order::class);
        $this->order->method('getId')->willReturn(55);
        $this->order->method('getIncrementId')->willReturn('000000055');
        $this->order->method('getGrandTotal')->willReturn(100.0);
        $this->order->method('getOrderCurrencyCode')->willReturn('NGN');
        $this->order->method('getPayment')->willReturn($this->orderPayment);
        $this->order->method('setState')->willReturnSelf();
        $this->order->method('setStatus')->willReturnSelf();
        $this->order->method('addCommentToStatusHistory')->willReturnSelf();
        $this->order->method('loadByIncrementId')->willReturnSelf();

        $this->orderFactory = $this->createMock(OrderFactory::class);
        $this->orderFactory->method('create')->willReturn($this->order);

        $this->controller = $this->createController($this->orderFactory);
    }

    private function createController(OrderFactory $orderFactory): Callback
    {
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        return new Callback(
            $orderFactory,
            $this->createMock(Session::class),
            $this->payment,
            $redirectFactory,
            $this->createMock(JsonFactory::class),
            $this->createMock(LoggerInterface::class),
            $this->messageManager,
            $this->request,
            $this->signozLogger
        );
    }

    private function givenParams(array $params): void
    {
        $this->request->method('getParams')->willReturn($params);
    }

    private function givenVerification(array $overrides = []): void
    {
        $this->payment->method('verifyTransaction')->with('999111')->willReturn(array_merge([
            'id' => '999111',
            'tx_ref' => self::TX_REF,
            'amount' => 100,
            'currency' => 'NGN',
            'status' => 'successful',
        ], $overrides));
    }

    private function redirectParams(array $overrides = []): array
    {
        return array_merge([
            'client_reference' => '000000055',
            'status' => 'successful',
            'tx_ref' => self::TX_REF,
            'transaction_id' => '999111',
        ], $overrides);
    }

    public function testMarksOrderProcessingForVerifiedSuccessfulPayment(): void
    {
        $this->givenParams($this->redirectParams());
        $this->givenVerification();
        $this->order->method('getState')->willReturn(Order::STATE_NEW);

        $this->order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING);
        $this->order->expects($this->once())->method('save');
        $this->orderPayment->expects($this->once())->method('setLastTransId')->with('999111');
        $this->signozLogger->expects($this->once())->method('trackTransaction');

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame('checkout/onepage/success', $this->redirectPath);
    }

    public function testCancelledRedirectWithoutTransactionIdLeavesOrderPending(): void
    {
        $this->givenParams(['client_reference' => '000000055', 'status' => 'cancelled', 'tx_ref' => self::TX_REF]);
        $this->payment->expects($this->never())->method('verifyTransaction');
        $this->orderFactory->expects($this->never())->method('create');
        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $this->controller->execute();
        $this->assertSame('checkout/cart', $this->redirectPath);
    }

    public function testUnverifiableTransactionChangesNothing(): void
    {
        $this->givenParams($this->redirectParams());
        $this->payment->method('verifyTransaction')->willReturn([]);
        $this->order->expects($this->never())->method('save');
        $this->signozLogger->expects($this->once())->method('trackError')->with('callback.exception');

        $this->controller->execute();
        $this->assertSame('checkout/cart', $this->redirectPath);
    }

    public function testRejectsTransactionBelongingToAnotherOrder(): void
    {
        // Finding 5: attacker's own transaction presented against someone else's order.
        $this->givenParams($this->redirectParams(['client_reference' => '000000077']));
        $this->givenVerification();
        $this->orderFactory->expects($this->never())->method('create');

        $this->controller->execute();
        $this->assertSame('checkout/cart', $this->redirectPath);
    }

    public function testRejectsTransactionNotCreatedByThisStore(): void
    {
        $this->givenParams($this->redirectParams());
        $this->givenVerification(['tx_ref' => 'WOO_1_abc']);
        $this->orderFactory->expects($this->never())->method('create');

        $this->controller->execute();
        $this->assertSame('checkout/cart', $this->redirectPath);
    }

    public function testFindsOrderFromVerifiedTxRefWhenClientReferenceAbsent(): void
    {
        $this->givenParams($this->redirectParams(['client_reference' => null]));
        $this->givenVerification();
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->order->expects($this->once())->method('loadByIncrementId')->with('000000055');

        $this->controller->execute();
        $this->assertSame('checkout/onepage/success', $this->redirectPath);
    }

    public function testMissingOrderRedirectsToCart(): void
    {
        $this->givenParams($this->redirectParams());
        $this->givenVerification();
        $missing = $this->createMock(Order::class);
        $missing->method('loadByIncrementId')->willReturnSelf();
        $missing->method('getId')->willReturn(null);
        $orderFactory = $this->createMock(OrderFactory::class);
        $orderFactory->method('create')->willReturn($missing);
        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $this->createController($orderFactory)->execute();
        $this->assertSame('checkout/cart', $this->redirectPath);
    }

    public static function paidStateProvider(): array
    {
        return [[Order::STATE_PROCESSING], [Order::STATE_COMPLETE]];
    }

    #[DataProvider('paidStateProvider')]
    public function testAlreadyPaidOrderGoesToSuccessPage(string $state): void
    {
        $this->givenParams($this->redirectParams());
        $this->givenVerification();
        $this->order->method('getState')->willReturn($state);
        $this->order->expects($this->never())->method('save');

        $this->controller->execute();
        $this->assertSame('checkout/onepage/success', $this->redirectPath);
    }

    public static function closedStateProvider(): array
    {
        return [[Order::STATE_CANCELED], [Order::STATE_HOLDED], [Order::STATE_CLOSED]];
    }

    #[DataProvider('closedStateProvider')]
    public function testClosedOrderIsNotReopened(string $state): void
    {
        $this->givenParams($this->redirectParams());
        $this->givenVerification();
        $this->order->method('getState')->willReturn($state);
        $this->order->expects($this->never())->method('save');

        $this->controller->execute();
        $this->assertSame('checkout/cart', $this->redirectPath);
    }

    public static function unsuccessfulStatusProvider(): array
    {
        return [['failed'], ['pending'], ['success']];
    }

    #[DataProvider('unsuccessfulStatusProvider')]
    public function testUnsuccessfulPaymentNeverCancelsOrder(string $status): void
    {
        $this->givenParams($this->redirectParams(['status' => 'successful']));
        $this->givenVerification(['status' => $status]);
        $this->order->method('getState')->willReturn(Order::STATE_NEW);

        $this->order->expects($this->never())->method('setState');
        $this->order->expects($this->never())->method('save');
        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $this->controller->execute();
        $this->assertSame('checkout/cart', $this->redirectPath);
    }

    public static function amountMismatchProvider(): array
    {
        return [
            'amount' => [['amount' => 1]],
            'currency' => [['currency' => 'USD']],
        ];
    }

    #[DataProvider('amountMismatchProvider')]
    public function testHoldsAndSavesOrderOnAmountOrCurrencyMismatch(array $overrides): void
    {
        $this->givenParams($this->redirectParams());
        $this->givenVerification($overrides);
        $this->order->method('getState')->willReturn(Order::STATE_NEW);

        $this->order->expects($this->once())->method('setState')->with(Order::STATE_HOLDED);
        $this->order->expects($this->once())->method('save');

        $this->controller->execute();
        $this->assertSame('checkout/cart', $this->redirectPath);
    }

    public function testAmountsEqual(): void
    {
        $this->assertTrue($this->controller->amounts_equal(100, '100.009'));
        $this->assertFalse($this->controller->amounts_equal(100, 99));
    }
}
