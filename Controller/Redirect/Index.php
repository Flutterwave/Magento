<?php
namespace Flutterwave\Payment\Controller\Redirect;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Flutterwave\Payment\Model\Payment;
use Psr\Log\LoggerInterface;

class Index implements HttpGetActionInterface
{
    private $resultRedirectFactory;
    private $payment;
    private $messageManager;
    private $logger;

    public function __construct(
        RedirectFactory $resultRedirectFactory,
        Payment $payment,
        ManagerInterface $messageManager,
        LoggerInterface $logger
    ) {
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->payment = $payment;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
    }

    public function execute()
    {
        try {
            $checkoutUrl = $this->payment->startTransaction();
            if ($checkoutUrl) {
                return $this->resultRedirectFactory->create()->setUrl($checkoutUrl);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Flutterwave Redirect Error: ' . $e->getMessage());
        }

        $this->messageManager->addErrorMessage(__('Unable to initiate Flutterwave checkout.'));
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }
}
