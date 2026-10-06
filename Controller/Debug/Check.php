<?php
namespace Flutterwave\Payment\Controller\Debug;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

class Check implements ActionInterface
{
    protected $paymentHelper;
    protected $resultRawFactory;

    public function __construct(
        PaymentHelper $paymentHelper,
        RawFactory $resultRawFactory
    ) {
        $this->paymentHelper = $paymentHelper;
        $this->resultRawFactory = $resultRawFactory;
    }

    public function execute(): ResultInterface
    {
        try {
            $paymentMethod = $this->paymentHelper->getMethodInstance('flutterwave');

            $output = "<h1>Flutterwave Payment Method Debug</h1>";
            $output .= "<pre>";
            $output .= "Is Active: " . ($paymentMethod->isActive() ? 'Yes' : 'No') . "\n";
            $output .= "Can Use Checkout: " . ($paymentMethod->canUseCheckout() ? 'Yes' : 'No') . "\n";
            $output .= "Is Available: " . ($paymentMethod->isAvailable() ? 'Yes' : 'No') . "\n";
            $output .= "</pre>";
        } catch (\Exception $e) {
            $output = "<h1>Error</h1>";
            $output .= "An error occurred: " . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }

        return $this->resultRawFactory->create()->setContents($output);
    }
}
