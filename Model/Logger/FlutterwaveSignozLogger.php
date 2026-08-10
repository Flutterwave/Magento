<?php

declare(strict_types=1);

namespace Flutterwave\Payment\Model\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class FlutterwaveSignozLogger
{
    private const SIGNOZ_BASE_URL = 'https://signozservice-prod.f4b-flutterwave.com';
    private const SIGNOZ_EVENT_PATH = '/events';
    private const LIBRARY = 'Magento';
    private const INTERNAL_SIGNOZ_API_KEY = '%%SIGNOZ_API_KEY%%';

    private ScopeConfigInterface $scopeConfig;
    private Curl $curl;
    private LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    public function trackRequestSent(string $method, string $reference, string $path, array $context = []): void
    {
        $this->sendEvent('request.sent', [
            'app_id' => $this->getAppId(),
            'environment' => $this->getEnvironment(),
            'api_version' => 'v3',
            'library' => self::LIBRARY,
            'library_version' => '2.x',
            'method' => $method,
            'path' => $path,
            'reference' => $this->sanitizeReference($reference),
            'context' => $context,
        ]);
    }

    public function trackTransaction(
        string $reference,
        string $currency,
        float $amount,
        string $method,
        float $fee = 0.0,
        array $context = []
    ): void {
        $this->sendEvent('app.transaction', [
            'app_id' => $this->getAppId(),
            'environment' => $this->getEnvironment(),
            'library' => self::LIBRARY,
            'library_version' => '2.x',
            'reference' => $this->sanitizeReference($reference),
            'currency' => $currency,
            'amount' => $amount,
            'fee' => $fee,
            'method' => $method,
            'context' => $context,
        ]);
    }

    public function trackError(
        string $errorCode,
        string $errorMessage,
        string $reference = '',
        ?string $stackTrace = null,
        array $context = []
    ): void {
        $payload = [
            'app_id' => $this->getAppId(),
            'environment' => $this->getEnvironment(),
            'library' => self::LIBRARY,
            'library_version' => '2.x',
            'error_code' => $errorCode,
            'error_message' => $this->truncate($errorMessage, 4096),
            'context' => $context,
        ];

        if ($stackTrace !== null && $stackTrace !== '') {
            $payload['error_stacktrace'] = $this->truncate($stackTrace, 16384);
        }

        if ($reference !== '') {
            $payload['reference'] = $this->sanitizeReference($reference);
        }

        $this->sendEvent('app.error', $payload);
    }

    private function sendEvent(string $eventName, array $payload): void
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            $internalKey = trim((string) self::INTERNAL_SIGNOZ_API_KEY);
            if ($internalKey !== '' && $internalKey !== '%%SIGNOZ_API_KEY%%') {
                $headers['Authorization'] = 'Bearer ' . $internalKey;
            }

            $this->curl->setHeaders($headers);

            $this->curl->post(
                self::SIGNOZ_BASE_URL . self::SIGNOZ_EVENT_PATH,
                json_encode([
                    'name' => $eventName,
                    'data' => $payload,
                    'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
                ], JSON_THROW_ON_ERROR)
            );

            $statusCode = (int) $this->curl->getStatus();
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning('Flutterwave SigNoz event failed with HTTP status: ' . $statusCode);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Flutterwave SigNoz logger failed: ' . $e->getMessage());
        }
    }

    private function isEnabled(): bool
    {
        return true;
    }

    private function getAppId(): string
    {
        $internalKey = trim((string) self::INTERNAL_SIGNOZ_API_KEY);
        return $internalKey !== '' && $internalKey !== '%%SIGNOZ_API_KEY%%' ? $internalKey : 'internal';
    }

    private function getEnvironment(): string
    {
        $mode = $this->scopeConfig->getValue('payment/flutterwave/environment', ScopeInterface::SCOPE_STORE);
        return ($mode === 'live' || $mode === 'production' || $mode === '1') ? 'production' : 'sandbox';
    }

    private function sanitizeReference(string $reference): string
    {
        $reference = trim((string) $reference);
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $reference) ?: 'unknown';
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit);
    }
}
