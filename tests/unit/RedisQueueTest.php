<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\queue\connector\Redis as RedisConnector;
use Lychee\queue\job\Redis as RedisJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Redis;

#[AllowMockObjectsWithoutExpectations]
class RedisQueueTest extends TestCase
{
    private Redis $redis;
    private RedisConnector $connector;
    private \Lychee\container\Container $app;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('redis 扩展未安装');
        }

        $this->redis = $this->createMock(Redis::class);

        $this->connector = new RedisConnector(
            ['queue' => 'default', 'retry_after' => 60],
            $this->redis
        );

        $this->app = new \Lychee\container\Container();
        $this->connector->setApp($this->app);
        $this->connector->setConnection('redis');
    }

    public function test_push_raw_pushes_to_list(): void
    {
        $this->redis->expects($this->once())
            ->method('rPush')
            ->with('default', '{"job":"TestJob","data":""}')
            ->willReturn(1);

        $result = $this->connector->pushRaw('{"job":"TestJob","data":""}');

        $this->assertNull($result);
    }

    public function test_push_raw_with_delay_uses_sorted_set(): void
    {
        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with(
                'default:delayed',
                $this->greaterThan(time()),
                '{"job":"TestJob","data":""}'
            )
            ->willReturn(1);

        $this->connector->pushRaw('{"job":"TestJob","data":""}', null, ['delay' => 10]);
    }

    public function test_size_counts_all_queues(): void
    {
        $this->redis->method('lLen')->willReturn(5);
        $this->redis->method('zCard')
            ->willReturnCallback(fn ($key) => match ($key) {
                'default:delayed'  => 2,
                'default:reserved' => 1,
                default            => 0,
            });

        $this->assertSame(8, $this->connector->size());
    }

    public function test_pop_returns_null_when_queue_empty(): void
    {
        $this->redis->method('lPop')->willReturn(false);
        $this->redis->method('zRangeByScore')->willReturn([]);

        $this->assertNull($this->connector->pop());
    }

    public function test_pop_returns_job_when_available(): void
    {
        $payload = json_encode([
            'job'      => 'TestJob',
            'data'     => ['foo' => 'bar'],
            'id'       => 'abc123',
            'attempts' => 0,
        ]);

        $this->redis->method('zRangeByScore')->willReturn([]);
        $this->redis->method('lPop')->willReturn($payload);
        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with('default:reserved', $this->greaterThan(time()), $this->stringContains('"attempts":1'))
            ->willReturn(1);

        $job = $this->connector->pop();

        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertSame('TestJob', $job->getName());
        $this->assertSame(1, $job->attempts());
        $this->assertSame('abc123', $job->getJobId());
    }

    public function test_pop_moves_delayed_jobs_to_main_queue(): void
    {
        $delayedPayload = json_encode(['job' => 'DelayedJob', 'data' => '']);

        // 第一次 zRangeByScore 返回延迟任务（migrate 阶段）
        // 第二次返回空（pop 阶段不再有 delayed）
        $this->redis->method('zRangeByScore')
            ->willReturnOnConsecutiveCalls(
                [$delayedPayload],  // delayed jobs
                [],                 // reserved jobs
                []                  // no more delayed
            );

        $this->redis->expects($this->once())
            ->method('zRemRangeByRank')
            ->with('default:delayed', 0, 0);

        $this->redis->expects($this->once())
            ->method('rPush')
            ->with('default', $delayedPayload);

        $this->redis->method('lPop')->willReturn(false);

        $this->assertNull($this->connector->pop());
    }

    public function test_delete_reserved_removes_from_reserved_set(): void
    {
        $job = new RedisJob(
            $this->app,
            $this->connector,
            'raw-body',
            'reserved-body',
            'redis',
            'default'
        );

        $this->redis->expects($this->once())
            ->method('zRem')
            ->with('default:reserved', 'reserved-body');

        $this->connector->deleteReserved('default', $job);
    }

    public function test_delete_and_release_moves_to_delayed(): void
    {
        $job = new RedisJob(
            $this->app,
            $this->connector,
            'raw-body',
            'reserved-body',
            'redis',
            'default'
        );

        $this->redis->expects($this->once())
            ->method('zRem')
            ->with('default:reserved', 'reserved-body');

        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with('default:delayed', $this->greaterThan(time()), 'reserved-body');

        $this->connector->deleteAndRelease('default', $job, 30);
    }

    public function test_delete_and_release_without_delay_pushes_to_queue(): void
    {
        $job = new RedisJob(
            $this->app,
            $this->connector,
            'raw-body',
            'reserved-body',
            'redis',
            'default'
        );

        $this->redis->expects($this->once())
            ->method('zRem')
            ->with('default:reserved', 'reserved-body');

        $this->redis->expects($this->once())
            ->method('rPush')
            ->with('default', 'reserved-body');

        $this->connector->deleteAndRelease('default', $job, 0);
    }
}
