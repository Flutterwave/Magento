<?php
namespace Flutterwave\Payment\Controller\Debug;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Check implements ActionInterface
{
    protected $paymentHelper;

    public function __construct(
        PaymentHelper $paymentHelper
    ) {
        $this->paymentHelper = $paymentHelper;
    }

    public function execute()
    {
        try {
            $paymentMethod = $this->paymentHelper->getMethodInstance('flutterwave');

            echo "<h1>Flutterwave Payment Method Debug</h1>";
            echo "<pre>";
            echo "Is Active: " . ($paymentMethod->isActive() ? 'Yes' : 'No') . "\n";
            echo "Can Use Checkout: " . ($paymentMethod->canUseCheckout() ? 'Yes' : 'No') . "\n";
            echo "Is Available: " . ($paymentMethod->isAvailable() ? 'Yes' : 'No') . "\n";
            echo "</pre>";
        } catch (\Exception $e) {
            echo "<h1>Error</h1>";
            echo "An error occurred: " . $e->getMessage();
        }

        exit;
    }
}
