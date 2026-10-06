<?php
namespace Flutterwave\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Flutterwave\Payment\Model\Payment;
use Magento\Sales\Model\Order;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;
use Flutterwave\Payment\Model\Logger\FlutterwaveSignozLogger;

class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private LoggerInterface $logger;
    private JsonFactory $jsonFactory;
    private OrderFactory $orderFactory;
    private $payment;
    private HttpRequest $request;
    private FlutterwaveSignozLogger $signozLogger;

    public function __construct(
        LoggerInterface $logger,
        Payment $payment,
        JsonFactory $jsonFactory,
        HttpRequest $request,
        OrderFactory $orderFactory,
        FlutterwaveSignozLogger $signozLogger
    ) {
        $this->logger = $logger;
        $this->payment = $payment;
        $this->jsonFactory = $jsonFactory;
        $this->orderFactory = $orderFactory;
        $this->request = $request;
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

    public function execute()
    {
        $resultJson = $this->jsonFactory->create();

        try {
            $secretHash = $this->payment->getSecretHash();
            $signature = (string) $this->request->getHeader('verif-hash');

            if ($secretHash === '' || $signature === '' || !hash_equals($secretHash, $signature)) {
                $this->logger->warning('Flutterwave Webhook rejected: invalid or missing verif-hash.');
                $this->signozLogger->trackError('webhook.unauthorized', 'Invalid or missing verif-hash header.', '', null, ['configured' => $secretHash !== '']);
                return $resultJson->setData(['success' => false, 'message' => 'Unauthorized'])->setHttpResponseCode(401);
            }

            $payload = (string) $this->request->getContent();
            $this->logger->info('Flutterwave Webhook Payload: ' . $payload);

            $data = json_decode($payload, true);
            $transactionId = isset($data['data']['id']) ? (string) $data['data']['id'] : '';

            if ($transactionId === '' || !ctype_digit($transactionId)) {
                $this->signozLogger->trackError('webhook.invalid_payload', 'Invalid webhook payload: Missing transaction id.', '', null, ['payload' => $payload]);
                return $resultJson->setData(['success' => false, 'message' => 'Invalid webhook payload: Missing transaction id.'])->setHttpResponseCode(400);
            }

            // Only the verified transaction is trusted; the payload status and tx_ref are ignored.
            $verification = $this->payment->verifyTransaction($transactionId);
            $this->logger->info('Flutterwave Transaction Verification: ' . json_encode($verification));

            if (empty($verification)) {
                $this->signozLogger->trackError('webhook.verify_failed', 'Transaction could not be verified.', $transactionId, null, []);
                return $resultJson->setData(['success' => false, 'message' => 'Transaction could not be verified.'])->setHttpResponseCode(502);
            }

            $reference = $verification['tx_ref'];
            $this->signozLogger->trackRequestSent('webhook', $reference, '/flutterwave/payment/webhook', ['status' => $verification['status']]);

            $orderIncrementId = $this->payment->getOrderIncrementIdFromTxRef($reference);
            if ($orderIncrementId === null) {
                // Not a transaction created by this module; acknowledge so Flutterwave stops retrying.
                return $resultJson->setData(['success' => true, 'message' => 'Transaction not handled by this store.'])->setHttpResponseCode(200);
            }

            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);

            if (!$order || !$order->getId()) {
                throw new \Exception("Order with Increment ID {$orderIncrementId} not found.");
            }

            if (!in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true)) {
                return $resultJson->setData(['success' => true, 'message' => 'Order Already Processed'])->setHttpResponseCode(200);
            }

            if ($verification['status'] !== Payment::STATUS_SUCCESSFUL) {
                // Failed or pending attempts leave the order open so the customer can retry.
                return $resultJson->setData(['success' => true, 'message' => 'Payment not successful; order left unchanged.'])->setHttpResponseCode(200);
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
                    'webhook',
                    0.0,
                    ['order_id' => (string) $order->getIncrementId(), 'status' => $verification['status']]
                );

                $order->setState(Order::STATE_PROCESSING)
                      ->setStatus(Order::STATE_PROCESSING);
                $order->save();

                return $resultJson->setData(['success' => true, 'message' => 'Order Processed Successfully'])->setHttpResponseCode(200);
            }

            $order->setState(Order::STATE_HOLDED)
                  ->setStatus(Order::STATE_HOLDED)
                  ->addCommentToStatusHistory(__('Attention: New order has been placed on hold because of incorrect payment amount or currency. Please, look into it.  Amount Paid: '. $verification['currency'] .$verification['amount']));
            $order->save();

            return $resultJson->setData(['success' => true, 'message' => 'Order placed on hold'])->setHttpResponseCode(200);
        } catch (\Exception $e) {
            $this->logger->critical('Flutterwave Webhook Error: ' . $e->getMessage());
            $this->signozLogger->trackError('webhook.exception', $e->getMessage(), '', $e->getTraceAsString(), ['source' => 'webhook']);
            return $resultJson->setData(['success' => false, 'message' => 'Webhook processing failed'])->setHttpResponseCode(500);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
