<?php

declare(strict_types=1);

namespace Tests\unit;

use InvalidArgumentException;
use Lychee\container\Container;
use Lychee\queue\connector\Cmq as CmqConnector;
use Lychee\queue\job\Cmq as CmqJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class CmqQueueTest extends TestCase
{
    private FakeCmqConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new FakeCmqConnector();
        $this->connector->setApp(new Container());
        $this->connector->setConnection('cmq');
    }

    public function test_constructor_throws_on_incomplete_config(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CmqConnector(['secret_id' => 'ak']);
    }

    public function test_push_raw_sends_and_returns_msg_id(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0, 'msgId' => 'msg-1']);

        $id = $this->connector->pushRaw('{"job":"Foo","data":""}');

        $this->assertSame('msg-1', $id);

        $params = $this->connector->httpCalls[0][1];
        $this->assertSame('SendMessage', $params['Action']);
        $this->assertSame('default', $params['queueName']);
        $this->assertSame('{"job":"Foo","data":""}', $params['msgBody']);
        $this->assertSame(0, (int) $params['delaySeconds']);
        $this->assertNotEmpty($params['Signature']);
        $this->assertSame('HmacSHA1', $params['SignatureMethod']);
    }

    public function test_push_raw_with_delay_passes_delay_seconds(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0, 'msgId' => 'msg-2']);

        $this->connector->pushRaw('{}', null, ['delay' => 60]);

        $this->assertSame(60, (int) $this->connector->httpCalls[0][1]['delaySeconds']);
    }

    public function test_push_payload_contains_id_and_attempts(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0, 'msgId' => 'msg-3']);

        $this->connector->push('Foo', ['x' => 1]);

        $payload = json_decode($this->connector->httpCalls[0][1]['msgBody'], true);
        $this->assertNotEmpty($payload['id']);
        $this->assertSame(0, $payload['attempts']);
    }

    public function test_pop_returns_cmq_job(): void
    {
        $body                          = '{"job":"Foo","data":{"x":1}}';
        $this->connector->httpResponse = json_encode([
            'code'          => 0,
            'msgBody'       => $body,
            'receiptHandle' => 'handle-1',
        ]);

        $job = $this->connector->pop();

        $this->assertInstanceOf(CmqJob::class, $job);
        $this->assertSame($body, $job->getRawBody());
        $this->assertSame('handle-1', $job->getReceiptHandle());
        $this->assertSame(1, $job->attempts());
        $this->assertSame(0, (int) $this->connector->httpCalls[0][1]['pollingWaitSeconds']);
    }

    public function test_pop_returns_null_when_response_has_no_body(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0, 'msgBody' => null]);

        $this->assertNull($this->connector->pop());
    }

    public function test_pop_returns_null_on_no_message_error(): void
    {
        $this->connector->httpError = new RuntimeException('[CMQ ReceiveMessage] no message (code=10200)', 10200);

        $this->assertNull($this->connector->pop());
    }

    public function test_pop_throws_on_fatal_error(): void
    {
        $this->connector->httpError = new RuntimeException('[CMQ ReceiveMessage] InternalError (code=5000)', 5000);

        $this->expectException(RuntimeException::class);

        $this->connector->pop();
    }

    public function test_delete_message_sends_receipt_handle(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0]);

        $this->connector->deleteMessage('default', 'handle-1');

        $params = $this->connector->httpCalls[0][1];
        $this->assertSame('DeleteMessage', $params['Action']);
        $this->assertSame('handle-1', $params['receiptHandle']);
    }

    public function test_release_message_deletes_then_resends_attempts(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0, 'msgId' => 'msg-new']);

        $this->connector->releaseMessage('default', 'handle-1', 30, '{"attempts":2}');

        $this->assertCount(2, $this->connector->httpCalls);

        $delete = $this->connector->httpCalls[0][1];
        $this->assertSame('DeleteMessage', $delete['Action']);
        $this->assertSame('handle-1', $delete['receiptHandle']);

        $send = $this->connector->httpCalls[1][1];
        $this->assertSame('SendMessage', $send['Action']);
        $this->assertSame(30, (int) $send['delaySeconds']);
        $this->assertSame(2, json_decode($send['msgBody'], true)['attempts']);
    }

    public function test_release_message_without_payload_only_deletes(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0]);

        $this->connector->releaseMessage('default', 'handle-1', 0, '');

        $this->assertCount(1, $this->connector->httpCalls);
        $this->assertSame('DeleteMessage', $this->connector->httpCalls[0][1]['Action']);
    }

    public function test_non_zero_code_throws_runtime_exception(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 4440, 'message' => 'receipt handle error']);

        $this->expectException(RuntimeException::class);

        $this->connector->deleteMessage('default', 'handle-x');
    }

    public function test_size_reads_active_msg_num_via_cloud_api(): void
    {
        $this->connector->cloudHttpResponse = json_encode(['Response' => ['ActiveMsgNum' => 42]]);

        $this->assertSame(42, $this->connector->size());

        [, $body, $headers] = $this->connector->cloudHttpCalls[0];
        $this->assertSame('default', json_decode($body, true)['QueueName']);
        $this->assertContains('X-TC-Action: DescribeCmqQueueDetail', $headers);
        $this->assertContains('X-TC-Region: ap-guangzhou', $headers);
    }

    public function test_size_returns_zero_on_recoverable_error(): void
    {
        $this->connector->cloudHttpError = new RuntimeException('queue not exist');

        $this->assertSame(0, $this->connector->size());
    }

    public function test_job_delete_calls_delete_message(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0]);

        $job = new CmqJob($this->connector->app(), $this->connector, '{}', 'h', 'cmq', 'default');
        $job->delete();

        $this->assertTrue($job->isDeleted());
        $this->assertSame('DeleteMessage', $this->connector->httpCalls[0][1]['Action']);
    }

    public function test_job_release_increments_and_accumulates_attempts(): void
    {
        $this->connector->httpResponse = json_encode(['code' => 0, 'msgId' => 'x']);

        $job = new CmqJob($this->connector->app(), $this->connector, '{"attempts":2}', 'h', 'cmq', 'default');
        $job->release(5);

        $this->assertTrue($job->isReleased());
        $send = $this->connector->httpCalls[1][1];
        $this->assertSame(3, json_decode($send['msgBody'], true)['attempts']);
        $this->assertSame(5, (int) $send['delaySeconds']);
    }
}

/**
 * CMQ 连接器测试替身：拦截全部 HTTP 调用，不发真实网络请求。
 */
class FakeCmqConnector extends CmqConnector
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $httpCalls = [];

    /** @var list<array{0: string, 1: string, 2: array<string>}> */
    public array $cloudHttpCalls = [];

    public string $httpResponse = '{"code":0}';

    public ?Throwable $httpError = null;

    public string $cloudHttpResponse = '{"Response":{}}';

    public ?Throwable $cloudHttpError = null;

    public function __construct()
    {
        parent::__construct([
            'secret_id'  => 'AK-test',
            'secret_key' => 'SK-test',
            'region'     => 'ap-guangzhou',
            'endpoint'   => 'https://cmq-gz.example.com',
            'queue'      => 'default',
        ]);
    }

    public function app(): Container
    {
        return $this->app;
    }

    protected function doHttpPost(string $url, array $params): string
    {
        $this->httpCalls[] = [$url, $params];

        if ($this->httpError !== null) {
            throw $this->httpError;
        }

        return $this->httpResponse;
    }

    protected function doHttpPostWithHeaders(string $url, string $body, array $headers): string
    {
        $this->cloudHttpCalls[] = [$url, $body, $headers];

        if ($this->cloudHttpError !== null) {
            throw $this->cloudHttpError;
        }

        return $this->cloudHttpResponse;
    }
}
