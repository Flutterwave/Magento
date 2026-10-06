<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Gateway\Command;

use Flutterwave\Payment\Gateway\Command\VerifyCommand;
use Flutterwave\Payment\Model\Api\Client;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VerifyCommandTest extends TestCase
{
    private Client&MockObject $client;
    private Payment&MockObject $payment;
    private PaymentDataObjectInterface&MockObject $paymentDO;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('setTransactionId')->willReturnSelf();
        $this->payment->method('setIsTransactionClosed')->willReturnSelf();
        $this->payment->method('setAdditionalInformation')->willReturnSelf();
        $this->paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $this->paymentDO->method('getPayment')->willReturn($this->payment);
    }

    private function execute(): void
    {
        (new VerifyCommand($this->client))->execute(['payment' => $this->paymentDO, 'transaction_id' => '999111']);
    }

    private function givenPaymentAction(string $action): void
    {
        $method = $this->createMock(MethodInterface::class);
        $method->method('getConfigPaymentAction')->willReturn($action);
        $this->payment->method('getMethodInstance')->willReturn($method);
    }

    public static function unsuccessfulResponseProvider(): array
    {
        return [
            'failed' => [['status' => 'failed']],
            'legacy success value' => [['status' => 'success']],
            'no response' => [null],
        ];
    }

    #[DataProvider('unsuccessfulResponseProvider')]
    public function testThrowsWhenTransactionIsNotSuccessful(?array $response): void
    {
        $this->client->method('verifyTransaction')->with('999111')->willReturn($response);
        $this->payment->expects($this->never())->method('setTransactionId');

        $this->expectExceptionMessage('Payment verification failed');
        $this->execute();
    }

    public function testRegistersCaptureForAuthorizeCapture(): void
    {
        $this->client->method('verifyTransaction')->willReturn(['status' => 'successful', 'amount' => 100]);
        $this->givenPaymentAction('authorize_capture');

        $this->payment->expects($this->once())->method('setTransactionId')->with('999111');
        $this->payment->expects($this->once())->method('registerCaptureNotification')->with(100);
        $this->payment->expects($this->never())->method('authorize');

        $this->execute();
    }

    public function testAuthorizesForOtherPaymentActions(): void
    {
        $this->client->method('verifyTransaction')->willReturn(['status' => 'successful', 'amount' => 100]);
        $this->givenPaymentAction('authorize');

        $this->payment->expects($this->once())->method('authorize')->with(true, 100);
        $this->payment->expects($this->never())->method('registerCaptureNotification');

        $this->execute();
    }
}
