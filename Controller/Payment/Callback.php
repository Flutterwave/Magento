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
use Flutterwave\Payment\Model\Logger\FlutterwaveSignozLogger;

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
    private FlutterwaveSignozLogger $signozLogger;

    public function __construct(
        OrderFactory $orderFactory,
        Session $checkoutSession,
        Payment $payment,
        RedirectFactory $resultRedirectFactory,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        ManagerInterface $messageManager,
        RequestInterface $request,
        FlutterwaveSignozLogger $signozLogger
    ) {
        $this->orderFactory = $orderFactory;
        $this->checkoutSession = $checkoutSession;
        $this->jsonFactory = $jsonFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->request = $request;
        $this->payment = $payment;
        $this->signozLogger = $signozLogger;
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

        $clientReference = isset($data['client_reference']) ? (string) $data['client_reference'] : '';
        $transactionId = isset($data['transaction_id']) ? (string) $data['transaction_id'] : '';
        $this->signozLogger->trackRequestSent('callback', $clientReference !== '' ? $clientReference : 'unknown', '/flutterwave/payment/callback', ['status' => (string) ($data['status'] ?? 'unknown')]);

        $redirect = $this->resultRedirectFactory->create();

        // Query parameters are attacker-controlled, so without a verifiable transaction nothing is changed.
        // Cancelled or abandoned payments leave the order pending so the customer can retry.
        if ($transactionId === '') {
            $this->messageManager->addErrorMessage(__('Your payment was not completed. Please try again.'));
            return $redirect->setPath('checkout/cart');
        }

        try {
            $verification = $this->payment->verifyTransaction($transactionId);
            $this->logger->info('Flutterwave Transaction Verification: ' . json_encode($verification));

            if (empty($verification)) {
                throw new \Exception("Transaction {$transactionId} could not be verified.");
            }

            $reference = $verification['tx_ref'];
            $orderIncrementId = $this->payment->getOrderIncrementIdFromTxRef($reference);

            if ($orderIncrementId === null || ($clientReference !== '' && $clientReference !== $orderIncrementId)) {
                throw new \Exception("Verified transaction {$transactionId} does not belong to order {$clientReference}.");
            }

            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            if (!$order || !$order->getId()) {
                throw new \Exception("Order not found for reference: $reference");
            }

            if (in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
                return $redirect->setPath('checkout/onepage/success');
            }

            if (!in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true)) {
                return $redirect->setPath('checkout/cart');
            }

            if ($verification['status'] !== Payment::STATUS_SUCCESSFUL) {
                $this->messageManager->addErrorMessage(__('Your payment could not be verified. Please try again.'));
                return $redirect->setPath('checkout/cart');
            }

            $order->getPayment()
                ->setLastTransId($verification['id'])
                ->setAdditionalInformation('flutterwave_transaction_id', $verification['id'])
                ->setAdditionalInformation('flutterwave_tx_ref', $reference);

            if (
                $this->amounts_equal($order->getGrandTotal(), $verification['amount']) &&
                $order->getOrderCurrencyCode() === $verification['currency']
            ) {
                $this->signozLogger->trackTransaction(
                    $reference,
                    (string) $order->getOrderCurrencyCode(),
                    (float) $verification['amount'],
                    'callback',
                    0.0,
                    ['order_id' => (string) $order->getIncrementId(), 'status' => $verification['status']]
                );

                $order->setState(Order::STATE_PROCESSING)
                      ->setStatus(Order::STATE_PROCESSING);
                $order->save();

                return $redirect->setPath('checkout/onepage/success');
            }

            $order->setState(Order::STATE_HOLDED)
                  ->setStatus(Order::STATE_HOLDED)
                  ->addCommentToStatusHistory(__('Attention: New order has been placed on hold because of incorrect payment amount or currency. Please, look into it.  Amount Paid: '. $verification['currency'] .$verification['amount']));
            $order->save();

            $this->messageManager->addErrorMessage(__('Your payment amount did not match the order total. Please contact support.'));
            return $redirect->setPath('checkout/cart');

        } catch (\Exception $e) {
            $this->logger->error('Flutterwave Redirect Error: ' . $e->getMessage());
            $this->signozLogger->trackError('callback.exception', $e->getMessage(), $clientReference, $e->getTraceAsString(), ['source' => 'callback']);
            $this->messageManager->addErrorMessage(__('A server error occurred. Please contact support.'));
            return $redirect->setPath('checkout/cart');
        }
    }
}
