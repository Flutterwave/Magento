<?php
namespace Flutterwave\Payment\Controller\Payment;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Checkout\Model\Session;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\App\ActionInterface;
use Flutterwave\Payment\Model\Payment;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

class Callback implements ActionInterface
{
    protected $orderFactory;
    protected $checkoutSession;
    protected $jsonFactory;
    private $resultRedirectFactory;
    protected $logger;
    protected $request;
    private $payment;
    private $messageManager;

    public function __construct(
        OrderFactory $orderFactory,
        Session $checkoutSession,
        Payment $payment,
        RedirectFactory $resultRedirectFactory,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        ManagerInterface $messageManager,
        RequestInterface $request
    ) {
        $this->orderFactory = $orderFactory;
        $this->checkoutSession = $checkoutSession;
        $this->jsonFactory = $jsonFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->request = $request;
        $this->payment = $payment;
    }

    /**
	 * Check Amount Equals.
	 *
	 * Checks to see whether the given amounts are equal using a proper floating
	 * point comparison with an Epsilon which ensures that insignificant decimal
	 * places are ignored in the comparison.
	 *
	 * eg. 100.00 is equal to 100.0001
	 *
	 * @param Float $amount1 1st amount for comparison.
	 * @param Float $amount2  2nd amount for comparison.
	 * @since 2.3.3
	 * @return bool
	 */
	public function amounts_equal( $amount1, $amount2 ): bool {
		return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > 0.01 );
	}

    public function execute(): ResultInterface
    {
        $data = $this->request->getParams();
        $this->logger->info('Flutterwave Redirect Callback Data: ' . json_encode($data));

        $reference = $data['client_reference'] ?? null;
        $status = $data['status'] ?? null;

        $redirect = $this->resultRedirectFactory->create();

        if (!$reference || !$status) {
            $this->logger->error('Flutterwave Redirect: Missing reference or status.');
            $this->messageManager->addErrorMessage(__('Invalid payment callback data.'));
            return $redirect->setPath('checkout/cart');
        }

        try {
            $order = $this->orderFactory->create()->loadByIncrementId($reference);
            if (!$order || !$order->getId()) {
                throw new \Exception("Order not found for reference: $reference");
            }

            $verification = $this->payment->verifyTransaction($data['tx_ref']);
            $this->logger->info('Flutterwave Transaction Verification: ' . json_encode($verification));

            if (
                $status === 'success' &&
                $verification['status'] === 'success' &&
                $this->amounts_equal($order->getGrandTotal(), $verification['amount']) &&
                $order->getOrderCurrencyCode() === $verification['currency']
            ) {
                $order->setState(Order::STATE_PROCESSING)
                      ->setStatus(Order::STATE_PROCESSING);
                $order->save();

                return $redirect->setPath('checkout/onepage/success');
            } elseif ($status === 'cancelled' &&
                $verification['status'] === 'pending'
            ){
                $order->setState(Order::STATE_CANCELED)
                ->setStatus(Order::STATE_CANCELED);
                $order->save();
                return $redirect->setPath('checkout/cart');
            } elseif( !$this->amounts_equal($order->getGrandTotal(), $verification['amount'] ) || $order->getOrderCurrencyCode() !== $verification['currency'] ){
                $order->setState(Order::STATE_HOLDED)
                      ->setStatus(Order::STATE_HOLDED)
                      ->addCommentToStatusHistory(__('Attention: New order has been placed on hold because of incorrect payment amount or currency. Please, look into it.  Amount Paid: '. $verification['currency'] .$verification['amount']));
                return $redirect->setPath('checkout/cart');
            } else {
                $order->setState(Order::STATE_CANCELED)
                      ->setStatus(Order::STATE_CANCELED)
                      ->addCommentToStatusHistory(__('Payment Status could not be verified.'));
                $order->save();

                $this->messageManager->addErrorMessage(__('Your payment could not be verified. Please try again.'));
                return $redirect->setPath('checkout/cart');
            }

        } catch (\Exception $e) {
            $this->logger->error('Flutterwave Redirect Error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('A server error occurred. Please contact support.'));
            return $redirect->setPath('checkout/cart');
        }
    }
}
