<?php
namespace Flutterwave\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Flutterwave\Payment\Model\Payment;
use Magento\Sales\Model\Order;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private LoggerInterface $logger;
    private JsonFactory $jsonFactory;
    private OrderFactory $orderFactory;
    private $payment;
    private $messageManager;

    public function __construct(
        LoggerInterface $logger,
        Payment $payment,
        JsonFactory $jsonFactory,
        ManagerInterface $messageManager,
        OrderFactory $orderFactory
    ) {
        $this->logger = $logger;
        $this->payment = $payment;
        $this->jsonFactory = $jsonFactory;
        $this->orderFactory = $orderFactory;
        $this->messageManager = $messageManager;
    }

    /**
	 * Get the Ip of the current request.
	 *
	 * @return string
	 */
	public function getFlutterwaveClientIp() {
		$ip_keys = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip_list = explode( ',', $_SERVER[ $key ] );
				foreach ( $ip_list as $ip ) {
					$ip = trim( $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return $ip;
					}
				}
			}
		}

		return 'UNKNOWN';
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

            // if ( '52.3.180.49' !== $this->getFlutterwaveClientIp() ) {
            //     $this->logger->info( 'Faudulent Webhook Notification Attempt [Access Restricted]: ' . (string) $this->getFlutterwaveClientIp() );
            //     return $resultJson->setData(
            //         array(
            //             'status'  => 'error',
            //             'message' => 'Unauthorized Access (Restriction)',
            //         )
            //     )->setHttpResponseCode(401);
            // }

            $payload = file_get_contents('php://input');
            $this->logger->info('Flutterwave Webhook Payload: ' . $payload);

            $data = json_decode($payload, true);

            if (empty($data['data']['reference'])) {
                throw new \Exception('Invalid webhook payload: Missing reference.');
            }

            $reference = $data['data']['reference'];
            $status = $data['data']['status'];
            $parts = explode('_', $reference);

            if (count($parts) < 2 || !is_numeric($parts[1])) {
                throw new \Exception('Invalid reference format: ' . $reference);
            }

            $orderIncrementId = $parts[1];
            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);

            if (!$order || !$order->getId()) {
                throw new \Exception("Order with Increment ID {$orderIncrementId} not found.");
            }

            if(in_array($order->getState(), [ Order::STATE_PROCESSING, Order::STATE_COMPLETE ])) {
                return $resultJson->setData(['success' => true, 'message' => 'Order Already Processed Successfully'])->setHttpResponseCode(400);
            }

            //TODO: get the current order status and make sure it is not completed. if so return a response stating it has been processed.
            try {
                $verification = $this->payment->verifyTransaction($data['reference']);
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

                    return $resultJson->setData(['success' => true, 'message' => 'Order Processed Successfully'])->setHttpResponseCode(201);
                } elseif ($status === 'cancelled' &&
                    $verification['status'] === 'pending'
                ){
                    $order->setState(Order::STATE_CANCELED)
                    ->setStatus(Order::STATE_CANCELED);
                    $order->save();
                    return $resultJson->setData(['success' => true, 'message' => 'Order Processed Successfully'])->setHttpResponseCode(201);
                } elseif( !$this->amounts_equal($order->getGrandTotal(), $verification['amount'] ) || $order->getOrderCurrencyCode() !== $verification['currency'] ){
                    $order->setState(Order::STATE_HOLDED)
                          ->setStatus(Order::STATE_HOLDED)
                          ->addCommentToStatusHistory(__('Attention: New order has been placed on hold because of incorrect payment amount or currency. Please, look into it.  Amount Paid: '. $verification['currency'] .$verification['amount']));
                    return $resultJson->setData(['success' => true, 'message' => 'Order Processed Successfully'])->setHttpResponseCode(201);
                } else {
                    $order->setState(Order::STATE_CANCELED)
                          ->setStatus(Order::STATE_CANCELED)
                          ->addCommentToStatusHistory(__('Payment Status could not be verified. Please try resending the webhook for this transaction.'));
                    $order->save();

                    $this->messageManager->addErrorMessage(__('Your payment could not be verified. Please try again.'));
                    $resultJson->setData(['success' => true, 'message' => 'Order Processed Successfully'])->setHttpResponseCode(201);
                }

            } catch (\Exception $e) {
                $this->logger->error('Flutterwave Redirect Error: ' . $e->getMessage());
                $this->messageManager->addErrorMessage(__('A server error occurred. Please contact support.'));
                return $resultJson->setData(['success' => false, 'message' => $e->getMessage()])->setHttpResponseCode(500);
            }


            return $resultJson->setData(['success' => true, 'message' => 'Webhook for Order' . $orderIncrementId . ' received and processed'])->setHttpResponseCode(201);
        } catch (\Exception $e) {
            $this->logger->critical('Flutterwave Webhook Error: ' . $e->getMessage());
            return $resultJson->setData(['success' => false, 'message' => $e->getMessage()])->setHttpResponseCode(500);
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
