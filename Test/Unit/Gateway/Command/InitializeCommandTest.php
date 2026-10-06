<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Gateway\Command;

use Flutterwave\Payment\Gateway\Command\InitializeCommand;
use Flutterwave\Payment\Model\Api\Client;
use Magento\Checkout\Model\Session;
use Magento\Framework\DataObject;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;

class InitializeCommandTest extends TestCase
{
    public function testCreatesPaymentLinkAndSetsPendingPaymentState(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000055');
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getOrderCurrencyCode')->willReturn('NGN');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getCustomerName')->willReturn('Ada Buyer');

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDO->method('getPayment')->willReturn($payment);

        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('createPaymentLink')->with([
            'tx_ref' => '000000055',
            'amount' => 100.0,
            'currency' => 'NGN',
            'redirect_url' => '',
            'customer' => ['email' => 'buyer@example.com', 'name' => 'Ada Buyer'],
        ])->willReturn(['link' => 'https://checkout.flutterwave.com/x']);

        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->addMethods(['setFlutterwavePaymentUrl'])
            ->getMock();
        $session->expects($this->once())->method('setFlutterwavePaymentUrl')->with('https://checkout.flutterwave.com/x');

        $stateObject = new DataObject();
        (new InitializeCommand($client, $session))->execute(['payment' => $paymentDO, 'stateObject' => $stateObject]);

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $stateObject->getState());
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $stateObject->getStatus());
        $this->assertFalse($stateObject->getIsNotified());
    }
}
