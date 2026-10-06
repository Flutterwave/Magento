<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Model\Logger;

use Flutterwave\Payment\Model\Logger\FlutterwaveSignozLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FlutterwaveSignozLoggerTest extends TestCase
{
    private const ENDPOINT = 'https://signozservice-prod.f4b-flutterwave.com/events';

    private ScopeConfigInterface&MockObject $scopeConfig;
    private Curl&MockObject $curl;
    private LoggerInterface&MockObject $logger;
    private FlutterwaveSignozLogger $signoz;
    private ?array $sent = null;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body): void {
            $this->assertSame(self::ENDPOINT, $url);
            $this->sent = json_decode($body, true);
        });
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->signoz = new FlutterwaveSignozLogger($this->scopeConfig, $this->curl, $this->logger);
    }

    private function givenStatus(int $status): void
    {
        $this->curl->method('getStatus')->willReturn($status);
    }

    public function testTrackRequestSentPostsEvent(): void
    {
        $this->givenStatus(202);
        $this->curl->expects($this->once())->method('setHeaders')->with([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);
        $this->logger->expects($this->never())->method('warning');

        $this->signoz->trackRequestSent('webhook', ' MAG_1_x ', '/flutterwave/payment/webhook', ['status' => 'successful']);

        $this->assertSame('request.sent', $this->sent['name']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/', $this->sent['timestamp']);
        $this->assertSame([
            'app_id' => 'internal',
            'environment' => 'sandbox',
            'api_version' => 'v3',
            'library' => 'Magento',
            'library_version' => '2.x',
            'method' => 'webhook',
            'path' => '/flutterwave/payment/webhook',
            'reference' => 'MAG_1_x',
            'context' => ['status' => 'successful'],
        ], $this->sent['data']);
    }

    public function testTrackTransactionPostsEvent(): void
    {
        $this->givenStatus(200);

        $this->signoz->trackTransaction('MAG_1_x', 'NGN', 100.5, 'callback', 1.5, ['order_id' => '1']);

        $this->assertSame('app.transaction', $this->sent['name']);
        $this->assertSame('NGN', $this->sent['data']['currency']);
        $this->assertEquals(100.5, $this->sent['data']['amount']);
        $this->assertEquals(1.5, $this->sent['data']['fee']);
        $this->assertSame('callback', $this->sent['data']['method']);
        $this->assertSame(['order_id' => '1'], $this->sent['data']['context']);
    }

    public function testTrackErrorIncludesOptionalFieldsAndTruncates(): void
    {
        $this->givenStatus(200);

        $this->signoz->trackError('webhook.exception', str_repeat('m', 5000), 'bad ref/../x', str_repeat('s', 20000), ['a' => 1]);

        $data = $this->sent['data'];
        $this->assertSame('app.error', $this->sent['name']);
        $this->assertSame('webhook.exception', $data['error_code']);
        $this->assertSame(4096, strlen($data['error_message']));
        $this->assertSame(16384, strlen($data['error_stacktrace']));
        $this->assertSame('bad-ref-x', $data['reference']);
    }

    public function testTrackErrorOmitsEmptyOptionalFields(): void
    {
        $this->givenStatus(200);

        $this->signoz->trackError('callback.invalid', 'short', '', '');

        $this->assertSame('short', $this->sent['data']['error_message']);
        $this->assertArrayNotHasKey('error_stacktrace', $this->sent['data']);
        $this->assertArrayNotHasKey('reference', $this->sent['data']);
    }

    public function testReferenceWithOnlyInvalidCharactersIsReplaced(): void
    {
        $this->givenStatus(200);

        $this->signoz->trackRequestSent('callback', '', '/x');

        $this->assertSame('unknown', $this->sent['data']['reference']);
    }

    public static function environmentProvider(): array
    {
        return [
            ['live', 'production'],
            ['production', 'production'],
            ['1', 'production'],
            ['sandbox', 'sandbox'],
            [null, 'sandbox'],
        ];
    }

    #[DataProvider('environmentProvider')]
    public function testEnvironmentMapping(?string $configured, string $expected): void
    {
        $this->givenStatus(200);
        $this->scopeConfig->method('getValue')->with('payment/flutterwave/environment')->willReturn($configured);

        $this->signoz->trackRequestSent('webhook', 'r', '/x');

        $this->assertSame($expected, $this->sent['data']['environment']);
    }

    public function testLogsWarningOnNonSuccessStatus(): void
    {
        $this->givenStatus(500);
        $this->logger->expects($this->once())->method('warning')->with('Flutterwave SigNoz event failed with HTTP status: 500');

        $this->signoz->trackRequestSent('webhook', 'r', '/x');
    }

    public function testNeverThrowsWhenTransportFails(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willThrowException(new \RuntimeException('connection refused'));
        $this->logger->expects($this->once())->method('warning')->with('Flutterwave SigNoz logger failed: connection refused');

        (new FlutterwaveSignozLogger($this->scopeConfig, $curl, $this->logger))->trackRequestSent('webhook', 'r', '/x');
    }
}
