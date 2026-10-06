<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Controller\Debug;

use Flutterwave\Payment\Controller\Debug\Check;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckTest extends TestCase
{
    private PaymentHelper&MockObject $paymentHelper;
    private Raw&MockObject $raw;
    private string $contents = '';
    private Check $controller;

    protected function setUp(): void
    {
        $this->paymentHelper = $this->createMock(PaymentHelper::class);
        $this->raw = $this->createMock(Raw::class);
        $this->raw->method('setContents')->willReturnCallback(function (string $contents) {
            $this->contents = $contents;
            return $this->raw;
        });
        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturn($this->raw);

        $this->controller = new Check($this->paymentHelper, $rawFactory);
    }

    public function testReportsPaymentMethodAvailability(): void
    {
        $method = $this->createMock(MethodInterface::class);
        $method->method('isActive')->willReturn(true);
        $method->method('canUseCheckout')->willReturn(false);
        $method->method('isAvailable')->willReturn(true);
        $this->paymentHelper->method('getMethodInstance')->with('flutterwave')->willReturn($method);

        $this->assertSame($this->raw, $this->controller->execute());
        $this->assertStringContainsString("Is Active: Yes\n", $this->contents);
        $this->assertStringContainsString("Can Use Checkout: No\n", $this->contents);
        $this->assertStringContainsString("Is Available: Yes\n", $this->contents);
    }

    public function testReportsErrorsEscaped(): void
    {
        $this->paymentHelper->method('getMethodInstance')->willThrowException(new \Exception('<b>missing</b>'));

        $this->controller->execute();
        $this->assertStringContainsString('<h1>Error</h1>', $this->contents);
        $this->assertStringContainsString('&lt;b&gt;missing&lt;/b&gt;', $this->contents);
    }
}
