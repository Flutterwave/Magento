<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Block\Adminhtml;

use Flutterwave\Payment\Block\Adminhtml\System\Config\WebhookUrl;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

class WebhookUrlTest extends TestCase
{
    public function testRendersStorefrontWebhookUrl(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects($this->once())->method('getUrl')->with(
            'flutterwave/payment/webhook',
            ['_scope' => 0, '_nosid' => true, '_direct' => 'flutterwave/payment/webhook']
        )->willReturn('https://shop.test/flutterwave/payment/webhook');

        $objectManager = new ObjectManager($this);
        $objectManager->prepareObjectManager();
        $block = $objectManager->getObject(WebhookUrl::class, ['urlBuilder' => $urlBuilder]);

        $method = new \ReflectionMethod($block, '_getElementHtml');
        $html = $method->invoke($block, $this->createMock(AbstractElement::class));

        $this->assertStringContainsString('<strong>https://shop.test/flutterwave/payment/webhook</strong>', $html);
    }
}
