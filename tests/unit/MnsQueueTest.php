<?php

declare(strict_types=1);

namespace Tests\unit;

use AliyunMNS\Client;
use AliyunMNS\Exception\MessageNotExistException;
use AliyunMNS\Exception\MnsException;
use AliyunMNS\Model\QueueAttributes;
use AliyunMNS\Queue;
use AliyunMNS\Requests\SendMessageRequest;
use AliyunMNS\Responses\GetQueueAttributeResponse;
use AliyunMNS\Responses\ReceiveMessageResponse;
use AliyunMNS\Responses\SendMessageResponse;
use InvalidArgumentException;
use Lychee\container\Container;
use Lychee\queue\connector\Mns as MnsConnector;
use Lychee\queue\job\Mns as MnsJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class MnsQueueTest extends TestCase
{
    private FakeMnsQueue $queue;

    private MnsConnector $connector;

    private Container $app;

    protected function setUp(): void
    {
        $this->queue = new FakeMnsQueue();

        $this->connector = new MnsConnector(
            ['queue' => 'default', 'wait_seconds' => 5],
            new FakeMnsClient($this->queue),
        );

        $this->app = new Container();
        $this->connector->setApp($this->app);
        $this->connector->setConnection('mns');
    }

    public function test_constructor_throws_on_incomplete_config(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MnsConnector(['access_id' => 'ak']);
    }

    public function test_push_raw_sends_message_and_returns_id(): void
    {
        $this->queue->sendReturn = new FakeSendMessageResponse('msg-abc');

        $id = $this->connector->pushRaw('{"job":"Foo","data":""}');

        $this->assertSame('msg-abc', $id);
        $this->assertCount(1, $this->queue->sent);
        $this->assertSame('{"job":"Foo","data":""}', $this->queue->sent[0]->getMessageBody());
        $this->assertSame(0, $this->queue->sent[0]->getDelaySeconds());
    }

    public function test_push_raw_with_delay_passes_delay_seconds(): void
    {
        $this->queue->sendReturn = new FakeSendMessageResponse('msg-later');

        $this->connector->pushRaw('{}', null, ['delay' => 60]);

        $this->assertSame(60, $this->queue->sent[0]->getDelaySeconds());
    }

    public function test_push_payload_contains_id_and_attempts(): void
    {
        $this->queue->sendReturn = new FakeSendMessageResponse('id');

        $this->connector->push('Foo', ['x' => 1]);

        $payload = json_decode($this->queue->sent[0]->getMessageBody(), true);
        $this->assertNotEmpty($payload['id']);
        $this->assertSame(0, $payload['attempts']);
    }

    public function test_pop_returns_mns_job(): void
    {
        $body                       = '{"job":"Foo","data":{"x":1}}';
        $this->queue->receiveReturn = new FakeReceiveMessageResponse($body, 'handle-1');

        $job = $this->connector->pop();

        $this->assertInstanceOf(MnsJob::class, $job);
        $this->assertSame($body, $job->getRawBody());
        $this->assertSame('handle-1', $job->getReceiptHandle());
        $this->assertSame(1, $job->attempts());
    }

    public function test_pop_returns_null_when_queue_empty(): void
    {
        $this->queue->receiveReturn = new MessageNotExistException(404, 'message not exist');

        $this->assertNull($this->connector->pop());
    }

    public function test_pop_throws_on_fatal_error(): void
    {
        $this->queue->receiveReturn = new MnsException(500, 'InternalServerError');

        $this->expectException(RuntimeException::class);

        $this->connector->pop();
    }

    public function test_delete_message_uses_receipt_handle(): void
    {
        $this->connector->deleteMessage('default', 'handle-1');

        $this->assertSame(['handle-1'], $this->queue->deleted);
    }

    public function test_release_message_deletes_and_resends(): void
    {
        $this->queue->sendReturn = new FakeSendMessageResponse('msg-new');

        $this->connector->releaseMessage('default', 'handle-1', 30, '{"attempts":2}');

        $this->assertSame(['handle-1'], $this->queue->deleted);
        $this->assertCount(1, $this->queue->sent);
        $this->assertSame(2, json_decode($this->queue->sent[0]->getMessageBody(), true)['attempts']);
        $this->assertSame(30, $this->queue->sent[0]->getDelaySeconds());
    }

    public function test_release_message_without_payload_only_deletes(): void
    {
        $this->connector->releaseMessage('default', 'handle-1', 0, '');

        $this->assertSame(['handle-1'], $this->queue->deleted);
        $this->assertSame([], $this->queue->sent);
    }

    public function test_size_reads_active_messages(): void
    {
        $this->queue->attributeReturn = new FakeGetQueueAttributeResponse(new FakeQueueAttributes(42));

        $this->assertSame(42, $this->connector->size());
    }

    public function test_size_returns_zero_on_recoverable_error(): void
    {
        $this->queue->attributeReturn = new MnsException(404, 'queue not exist');

        $this->assertSame(0, $this->connector->size());
    }

    public function test_job_delete_calls_delete_message(): void
    {
        $job = new MnsJob($this->app, $this->connector, '{}', 'job-handle', 'mns', 'default');
        $job->delete();

        $this->assertTrue($job->isDeleted());
        $this->assertSame(['job-handle'], $this->queue->deleted);
    }

    public function test_job_release_writes_incremented_attempts(): void
    {
        $this->queue->sendReturn = new FakeSendMessageResponse('id');

        $job = new MnsJob($this->app, $this->connector, '{}', 'h', 'mns', 'default');
        $job->release(15);

        $this->assertTrue($job->isReleased());
        $payload = json_decode($this->queue->sent[0]->getMessageBody(), true);
        $this->assertSame(1, $payload['attempts']);
        $this->assertSame(15, $this->queue->sent[0]->getDelaySeconds());
    }

    public function test_job_release_accumulates_attempts_across_retries(): void
    {
        $this->queue->sendReturn = new FakeSendMessageResponse('id');

        $job = new MnsJob($this->app, $this->connector, '{"attempts":2}', 'h', 'mns', 'default');
        $job->release(5);

        $this->assertSame(3, json_decode($this->queue->sent[0]->getMessageBody(), true)['attempts']);
    }
}

/**
 * 手写 MNS 测试替身：记录调用，避免真实网络请求。
 */
class FakeMnsClient extends Client
{
    public function __construct(private readonly Queue $queue)
    {
    }

    public function getQueueRef($queueName, $base64 = true): Queue
    {
        return $this->queue;
    }
}

class FakeMnsQueue extends Queue
{
    /** @var list<SendMessageRequest> */
    public array $sent = [];

    /** @var list<string> */
    public array $deleted = [];

    public Throwable|FakeSendMessageResponse|null $sendReturn = null;

    public ReceiveMessageResponse|Throwable|null $receiveReturn = null;

    public GetQueueAttributeResponse|Throwable|null $attributeReturn = null;

    public function __construct()
    {
    }

    public function sendMessage($param): SendMessageResponse
    {
        $this->sent[] = $param;

        if ($this->sendReturn instanceof Throwable) {
            throw $this->sendReturn;
        }

        return $this->sendReturn ?? new FakeSendMessageResponse('');
    }

    public function receiveMessage($waitSeconds = null): ReceiveMessageResponse
    {
        if ($this->receiveReturn instanceof Throwable) {
            throw $this->receiveReturn;
        }

        return $this->receiveReturn ?? new FakeReceiveMessageResponse('', '');
    }

    public function deleteMessage($receiptHandle): void
    {
        $this->deleted[] = (string) $receiptHandle;
    }

    public function getAttribute(): GetQueueAttributeResponse
    {
        if ($this->attributeReturn instanceof Throwable) {
            throw $this->attributeReturn;
        }

        return $this->attributeReturn ?? new FakeGetQueueAttributeResponse(null);
    }
}

class FakeSendMessageResponse extends SendMessageResponse
{
    public function __construct(private readonly string $fakeId)
    {
    }

    public function getMessageId(): string
    {
        return $this->fakeId;
    }
}

class FakeReceiveMessageResponse extends ReceiveMessageResponse
{
    public function __construct(
        private readonly string $fakeBody,
        private readonly string $fakeReceiptHandle,
    ) {
    }

    public function getMessageBody(): string
    {
        return $this->fakeBody;
    }

    public function getReceiptHandle(): string
    {
        return $this->fakeReceiptHandle;
    }
}

class FakeQueueAttributes extends QueueAttributes
{
    public function __construct(private readonly ?int $fakeActiveMessages)
    {
    }

    public function getActiveMessages(): ?int
    {
        return $this->fakeActiveMessages;
    }
}

class FakeGetQueueAttributeResponse extends GetQueueAttributeResponse
{
    public function __construct(private readonly ?QueueAttributes $attributes)
    {
    }

    public function getQueueAttributes(): ?QueueAttributes
    {
        return $this->attributes;
    }
}
