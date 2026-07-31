<?php
namespace Flutterwave\Payment\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;

class WebhookUrl extends Field
{
    protected $urlBuilder;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        UrlInterface $urlBuilder,
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        // $webhookUrl = $this->urlBuilder->getUrl('flutterwave/payment/webhook');
        $webhookUrl = $this->urlBuilder->getUrl('flutterwave/payment/webhook', ['_scope' => 0, '_nosid' => true, '_direct' => 'flutterwave/payment/webhook']);
        return '<div style="padding:5px 0;color:red;"><strong>' . $webhookUrl . '</strong></div>';
    }
}
