<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Controller\Redirect;

use Flutterwave\Payment\Controller\Redirect\Index;
use Flutterwave\Payment\Model\Payment;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    private Payment&MockObject $payment;
    private ManagerInterface&MockObject $messageManager;
    private LoggerInterface&MockObject $logger;
    private Redirect&MockObject $redirect;
    private Index $controller;

    protected function setUp(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setUrl')->willReturnSelf();
        $this->redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->controller = new Index($redirectFactory, $this->payment, $this->messageManager, $this->logger);
    }

    public function testRedirectsToFlutterwaveCheckout(): void
    {
        $this->payment->method('startTransaction')->willReturn('https://checkout.flutterwave.com/pay/x');
        $this->redirect->expects($this->once())->method('setUrl')->with('https://checkout.flutterwave.com/pay/x');
        $this->redirect->expects($this->never())->method('setPath');
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testReturnsToCartWhenNoLinkIsReturned(): void
    {
        $this->payment->method('startTransaction')->willReturn(false);
        $this->redirect->expects($this->once())->method('setPath')->with('checkout/cart');
        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testReturnsToCartAndLogsWhenStartingTransactionFails(): void
    {
        $this->payment->method('startTransaction')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('debug')->with($this->stringContains('boom'));
        $this->redirect->expects($this->once())->method('setPath')->with('checkout/cart');

        $this->assertSame($this->redirect, $this->controller->execute());
    }
}
