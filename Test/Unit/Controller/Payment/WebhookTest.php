<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Controller\Payment;

use Flutterwave\Payment\Controller\Payment\Webhook;
use Flutterwave\Payment\Model\Logger\FlutterwaveSignozLogger;
use Flutterwave\Payment\Model\Payment;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookTest extends TestCase
{
    private const SECRET = 'webhook-secret';
    private const TX_REF = 'MAG_000000055_old1';

    private Payment&MockObject $payment;
    private HttpRequest&MockObject $request;
    private OrderFactory&MockObject $orderFactory;
    private FlutterwaveSignozLogger&MockObject $signozLogger;
    private Json&MockObject $result;
    private Order&MockObject $order;
    private OrderPayment&MockObject $orderPayment;
    private Webhook $controller;

    private array $responseData = [];
    private ?int $responseCode = null;

    protected function setUp(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getSecretHash')->willReturn(self::SECRET);
        $this->payment->method('getOrderIncrementIdFromTxRef')->willReturnCallback(
            static fn (string $txRef) => $txRef === self::TX_REF ? '000000055' : null
        );

        $this->request = $this->createMock(HttpRequest::class);
        $this->signozLogger = $this->createMock(FlutterwaveSignozLogger::class);

        $this->result = $this->createMock(Json::class);
        $this->result->method('setData')->willReturnCallback(function (array $data) {
            $this->responseData = $data;
            return $this->result;
        });
        $this->result->method('setHttpResponseCode')->willReturnCallback(function (int $code) {
            $this->responseCode = $code;
            return $this->result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->result);

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

        $this->controller = new Webhook(
            $this->createMock(LoggerInterface::class),
            $this->payment,
            $jsonFactory,
            $this->request,
            $this->orderFactory,
            $this->signozLogger
        );
    }

    private function givenRequest(?string $signature, array $payload): void
    {
        $this->request->method('getHeader')->with('verif-hash')->willReturn($signature ?? false);
        $this->request->method('getContent')->willReturn(json_encode($payload));
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

    private function chargeCompleted(array $data = []): array
    {
        return ['event' => 'charge.completed', 'data' => array_merge(['id' => 999111, 'tx_ref' => self::TX_REF, 'status' => 'successful'], $data)];
    }

    public static function badSignatureProvider(): array
    {
        return [
            'missing header' => [null],
            'empty header' => [''],
            'wrong hash' => ['guess'],
        ];
    }

    #[DataProvider('badSignatureProvider')]
    public function testRejectsRequestsWithoutValidSignature(?string $signature): void
    {
        $this->givenRequest($signature, $this->chargeCompleted());
        $this->payment->expects($this->never())->method('verifyTransaction');
        $this->order->expects($this->never())->method('save');

        $this->assertSame($this->result, $this->controller->execute());
        $this->assertSame(401, $this->responseCode);
        $this->assertFalse($this->responseData['success']);
    }

    public function testRejectsAllRequestsWhenSecretHashNotConfigured(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getSecretHash')->willReturn('');
        $payment->expects($this->never())->method('verifyTransaction');
        $this->request->method('getHeader')->willReturn('');

        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->result);
        $controller = new Webhook($this->createMock(LoggerInterface::class), $payment, $jsonFactory, $this->request, $this->orderFactory, $this->signozLogger);

        $controller->execute();
        $this->assertSame(401, $this->responseCode);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'not json' => [null],
            'missing id' => [['data' => ['tx_ref' => self::TX_REF]]],
            'non numeric id' => [['data' => ['id' => '99/../1']]],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function testRejectsPayloadWithoutNumericTransactionId(?array $payload): void
    {
        $this->request->method('getHeader')->willReturn(self::SECRET);
        $this->request->method('getContent')->willReturn($payload === null ? 'not-json' : json_encode($payload));
        $this->payment->expects($this->never())->method('verifyTransaction');

        $this->controller->execute();
        $this->assertSame(400, $this->responseCode);
    }

    public function testReturnsBadGatewayWhenTransactionCannotBeVerified(): void
    {
        $this->givenRequest(self::SECRET, $this->chargeCompleted());
        $this->payment->method('verifyTransaction')->willReturn([]);
        $this->order->expects($this->never())->method('save');

        $this->controller->execute();
        $this->assertSame(502, $this->responseCode);
    }

    public function testMarksOrderProcessingForVerifiedSuccessfulPayment(): void
    {
        $this->givenRequest(self::SECRET, $this->chargeCompleted());
        $this->givenVerification();
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);

        $this->order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING);
        $this->order->expects($this->once())->method('save');
        $this->orderPayment->expects($this->once())->method('setLastTransId')->with('999111');
        $this->signozLogger->expects($this->once())->method('trackTransaction');

        $this->controller->execute();
        $this->assertSame(200, $this->responseCode);
        $this->assertSame('Order Processed Successfully', $this->responseData['message']);
    }

    public function testUsesVerifiedTxRefNotPayloadToFindOrder(): void
    {
        // Payload claims to be for a different order; only the verified tx_ref counts.
        $this->givenRequest(self::SECRET, $this->chargeCompleted(['tx_ref' => 'MAG_000000099_x', 'status' => 'failed']));
        $this->givenVerification();
        $this->order->method('getState')->willReturn(Order::STATE_NEW);

        $this->payment->expects($this->once())->method('getOrderIncrementIdFromTxRef')->with(self::TX_REF);
        $this->order->expects($this->once())->method('loadByIncrementId')->with('000000055');
        $this->order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING);

        $this->controller->execute();
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
        $this->givenRequest(self::SECRET, $this->chargeCompleted());
        $this->givenVerification($overrides);
        $this->order->method('getState')->willReturn(Order::STATE_NEW);

        $this->order->expects($this->once())->method('setState')->with(Order::STATE_HOLDED);
        $this->order->expects($this->once())->method('addCommentToStatusHistory');
        $this->order->expects($this->once())->method('save');

        $this->controller->execute();
        $this->assertSame(200, $this->responseCode);
        $this->assertSame('Order placed on hold', $this->responseData['message']);
    }

    public static function unsuccessfulStatusProvider(): array
    {
        return [['failed'], ['pending'], ['cancelled'], ['success']];
    }

    #[DataProvider('unsuccessfulStatusProvider')]
    public function testNeverCancelsOrChangesOrderForUnsuccessfulPayment(string $status): void
    {
        $this->givenRequest(self::SECRET, $this->chargeCompleted(['status' => 'successful']));
        $this->givenVerification(['status' => $status]);
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);

        $this->order->expects($this->never())->method('setState');
        $this->order->expects($this->never())->method('save');

        $this->controller->execute();
        $this->assertSame(200, $this->responseCode);
    }

    public static function finalStateProvider(): array
    {
        return [[Order::STATE_PROCESSING], [Order::STATE_COMPLETE], [Order::STATE_CANCELED], [Order::STATE_HOLDED], [Order::STATE_CLOSED]];
    }

    #[DataProvider('finalStateProvider')]
    public function testLeavesOrdersOutsidePendingStatesUntouched(string $state): void
    {
        $this->givenRequest(self::SECRET, $this->chargeCompleted());
        $this->givenVerification();
        $this->order->method('getState')->willReturn($state);

        $this->order->expects($this->never())->method('save');

        $this->controller->execute();
        $this->assertSame(200, $this->responseCode);
        $this->assertSame('Order Already Processed', $this->responseData['message']);
    }

    public function testAcknowledgesTransactionsNotCreatedByThisStore(): void
    {
        $this->givenRequest(self::SECRET, $this->chargeCompleted());
        $this->givenVerification(['tx_ref' => 'WOO_123_abc']);
        $this->orderFactory->expects($this->never())->method('create');

        $this->controller->execute();
        $this->assertSame(200, $this->responseCode);
        $this->assertSame('Transaction not handled by this store.', $this->responseData['message']);
    }

    public function testReturnsServerErrorWithoutLeakingDetailsWhenOrderMissing(): void
    {
        $this->givenRequest(self::SECRET, $this->chargeCompleted());
        $this->givenVerification();
        $missing = $this->createMock(Order::class);
        $missing->method('loadByIncrementId')->willReturnSelf();
        $missing->method('getId')->willReturn(null);
        $orderFactory = $this->createMock(OrderFactory::class);
        $orderFactory->method('create')->willReturn($missing);

        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->result);
        $controller = new Webhook($this->createMock(LoggerInterface::class), $this->payment, $jsonFactory, $this->request, $orderFactory, $this->signozLogger);
        $this->signozLogger->expects($this->once())->method('trackError')->with('webhook.exception');

        $controller->execute();
        $this->assertSame(500, $this->responseCode);
        $this->assertSame('Webhook processing failed', $this->responseData['message']);
    }

    public function testCsrfValidationIsSkippedBecauseSignatureIsVerified(): void
    {
        $request = $this->createMock(RequestInterface::class);

        $this->assertNull($this->controller->createCsrfValidationException($request));
        $this->assertTrue($this->controller->validateForCsrf($request));
    }

    public static function amountsProvider(): array
    {
        return [
            'equal' => [100.00, 100.00, true],
            'within epsilon' => [100.00, 100.005, true],
            'outside epsilon' => [100.00, 100.02, false],
            'numeric strings' => ['100.00', '100', true],
        ];
    }

    #[DataProvider('amountsProvider')]
    public function testAmountsEqual(float|string $a, float|string $b, bool $expected): void
    {
        $this->assertSame($expected, $this->controller->amounts_equal($a, $b));
    }
}
