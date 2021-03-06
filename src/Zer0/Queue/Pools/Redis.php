<?php

namespace Zer0\Queue\Pools;

use RedisClient\Exception\EmptyResponseException;
use RedisClient\Pipeline\PipelineInterface;
use RedisClient\RedisClient;
use Zer0\App;
use Zer0\Config\Interfaces\ConfigInterface;
use Zer0\Queue\Exceptions\IncorrectStateException;
use Zer0\Queue\Exceptions\WaitTimeoutException;
use Zer0\Queue\TaskAbstract;
use Zer0\Queue\TaskCollection;

/**
 * Class Redis
 * @package Zer0\Queue\Pools
 */
final class Redis extends Base
{

    /**
     * @var RedisClient
     */
    protected $redis;

    /**
     * @var RedisClient
     */
    protected $pubSubRedis;

    /**
     * @var string
     */
    protected $prefix;

    /**
     * @var bool
     */
    protected $saving = false;

    /**
     * @var int
     */
    protected $ttl;

    /**
     * Redis constructor.
     * @param ConfigInterface $config
     * @param App $app
     */
    public function __construct(ConfigInterface $config, App $app)
    {
        parent::__construct($config, $app);
        $this->redis = $this->app->broker('Redis')->get($config->redis ?? '');
        $this->prefix = $config->prefix ?? 'queue';
        $this->ttl = $config->ttl ?? 3600;
    }

    /**
     * @param TaskAbstract $task
     *
     * @return TaskAbstract
     * @throws \RedisClient\Exception\InvalidArgumentException
     * @throws \Zer0\Exceptions\InvalidArgumentException
     */
    public function push(TaskAbstract $task): TaskAbstract
    {
        $taskId = $task->getId();
        $autoId = $taskId === null;
        if ($autoId) {
            $task->setId($taskId = $this->nextId());
        }
        $task->beforeEnqueue();
        $task->beforePush();
        $channel = $task->getChannel();

        $payload = igbinary_serialize($task);

        $delayed = false;
        $pipeline = function (PipelineInterface $redis) use (
            $taskId,
            $task,
            $payload,
            $channel,
            &$delayed
        ) {
            $redis->publish($this->prefix . ':enqueue-channel:' . $channel, $payload);
            $redis->multi();
            $redis->sAdd($this->prefix . ':list-channels', $channel);
            $redis->rPush($this->prefix . ':channel:' . $channel, $taskId);
            $redis->incr($this->prefix . ':channel-total:' . $channel);
            $redis->set($this->prefix . ':input:' . $taskId, $payload, $this->ttl);
            $redis->del([
                $this->prefix . ':output:' . $taskId,
                $this->prefix . ':blpop:' . $taskId
            ]);

            $redis->exec();
        };

        if ($task->getDelay() > 0) {
            if ($this->redis->zAdd(
                $this->prefix . ':channel-pending:' . $channel,
                $task->getDelayOverwrite() ? [] : ['NX'],
                time() + $task->getDelay(),
                $taskId
            )) {
                $delayed = true;
                $this->redis->pipeline($pipeline);
            }
        }
        elseif (!$autoId && $task->getTimeoutSeconds() > 0) {
            if ($this->redis->zAdd(
                $this->prefix . ':channel-pending:' . $channel,
                [$taskId => time() + $task->getTimeoutSeconds()],
                'NX')) {
                $this->redis->pipeline($pipeline);
            }
        } else {
            $this->redis->pipeline($pipeline);
        }

        return $task;
    }

    /**
     * @param string $channel
     * @param callable $cb
     * @throws \RedisClient\Exception\InvalidArgumentException
     */
    public function subscribe(string $channel, callable $cb): void
    {
        if ($this->pubSubRedis === null) {
            $broker = $this->app->broker('Redis');
            $config = clone $broker->getConfig();
            $config->timeout = 0.01;
            $this->pubSubRedis = $broker->instantiate($config);
        }
        $this->pubSubRedis->subscribe([
            $this->prefix . ':enqueue-channel:' . $channel,
            $this->prefix . ':complete-channel:' . $channel,
        ], function ($type, $chan, $data) use ($cb, $channel) {
            $event = null;
            if ($type === 'message') {
                [$type, $eventChannel] = explode(':', substr($chan, strlen($this->prefix . ':')));
                if ($channel !== $eventChannel) {
                    $event = null;
                } elseif ($type === 'enqueue-channel') {
                    $event = 'new';
                } elseif ($type === 'complete-channel') {
                    $event = 'complete';
                }
                try {
                    $data = igbinary_unserialize($data);
                } catch (\ErrorException $e) {
                    $event = null;
                }
            }
            return $cb($event, $event !== null ? $data : null);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function nextId(): int
    {
        return $this->redis->incr($this->prefix . ':task-seq');
    }

    /**
     * {@inheritdoc}
     */
    public function wait(TaskAbstract $task, int $timeout = 3): TaskAbstract
    {
        $taskId = $task->getId();
        if ($taskId === null) {
            throw new IncorrectStateException('\'id\' property must be set before wait() is called');
        }

        try {
            if (!$this->redis->blpop([$this->prefix . ':blpop:' . $taskId], $timeout)) {
                throw new WaitTimeoutException;
            }
        }  catch (EmptyResponseException $e) {
            throw new WaitTimeoutException;
        }

        $payload = $this->redis->get($this->prefix . ':output:' . $taskId);
        if ($payload === null) {
            throw new IncorrectStateException($this->prefix . ':output:' . $taskId . ' key does not exist');
        }

        return igbinary_unserialize($payload);
    }

    /**
     * {@inheritdoc}
     */
    public function waitCollection(TaskCollection $collection, float $timeout = 1): void
    {
        $a -= $dsfdsfs;
        $hash = [];
        $pending = $collection->pending();
        $successful = $collection->successful();
        $ready = $collection->ready();
        $failed = $collection->failed();
        foreach ($pending as $task) {
            $key = $this->prefix . ':blpop:' . $task->getId();
            $item =& $hash[$key];
            if ($item === null) {
                $item = [];
            }
            $item[] = $task;
        }
        $time = microtime(true);
        $first = true;
        $popped = 0;
        for (; ;) {
            if (!$hash) {
                return;
            }
            if (!$first) {
                if ($popped > 0) { // Redis BLPOP latency fix
                    return;
                }

                if (microtime(true) > $time + $timeout) {
                    return;
                }
            } else {
                $first = false;
            }
            try {
                $pop = $this->redis->blpop(array_keys($hash), 1);

                if ($pop === null) {
                    continue;
                }
            } catch (EmptyResponseException $e) {
                continue;
            }

            $key = array_key_first($pop);

            $tasks = $hash[$key] ?? null;
            if ($tasks === null) {
                continue;
            }
            unset($hash[$key]);

            ++$popped;

            $taskId = $tasks[0]->getId();

            foreach ($tasks as $prev) {
                $pending->detach($prev);
            }

            $tries = 0;
            payload:
            try {
                $payload = $this->redis->get($this->prefix . ':output:' . $taskId);
            } catch (EmptyResponseException $e) {
                if (++$tries > 2) {
                    $payload = null;
                }
                goto payload;
            }

            if ($payload !== null) {
                $task = igbinary_unserialize($payload);
            } else {
                /**
                 * @var $task TaskAbstract
                 */
                $task = $tasks[0];
                $task->setException(new IncorrectStateException($this->prefix . ':output:' . $taskId . ' key does not exist'));
            }
            $ready->attach($task);
            if ($task->hasException()) {
                $failed->attach($task);
            } else {
                $successful->attach($task);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function pop(?array $channels = null): ?TaskAbstract
    {
        if ($channels === null) {
            $channels = $this->listChannels();
        }
        $prefix = $this->prefix . ':channel:';
        $keys = [];
        foreach ($channels as $chan) {
            $keys[] = $prefix . $chan;
        }

        try {
            $reply = $this->redis->blpop($keys, 1);

            if (!$reply) {
                return null;
            }
        } catch (EmptyResponseException $e) {
            return null;
        }

        foreach ($reply as $key => $taskId) {
            break;
        }
        $channel = substr($key, strlen($prefix));
        $payload = $this->redis->get($this->prefix . ':input:' . $taskId);
        try {
            $task = igbinary_unserialize($payload);
        } catch (\ErrorException $e) {
            return null;
        }
        $task->setChannel($channel);

        return $task;
    }

    /**
     * {@inheritdoc}
     */
    public function listChannels(): array
    {
        return $this->redis->smembers($this->prefix . ':list-channels');
    }

    /**
     * @param string $channel
     * @param int $start
     * @param int $stop
     * @return array
     */
    public function pendingTasks(string $channel, int $start = 0, int $stop = -1): array
    {
        $items = $this->redis->zrange(
            $this->prefix . ':channel-pending:' . $channel,
            $start,
            $stop
        );
        $keys = [];
        foreach ($items as $value) {
            [$taskId, $timeout] = explode(':', $value);
            $keys[] = $this->prefix . ':input:' . $taskId;
        }
        if (!$keys) {
            return [];
        }
        $mget = $this->redis->mget($keys);
        $ret = [];
        foreach ($mget as $key => $item) {
            if (!is_string($item)) {
                continue;
            }
            try {
                $ret[] = igbinary_unserialize($item);
            } catch (\ErrorException $e) {
            }
        }
        return $ret;
    }

    /**
     * @param string $channel
     * @return array
     * @throws \RedisClient\Exception\InvalidArgumentException
     */
    public function getChannelStats(string $channel): array
    {
        $res = $this->redis->pipeline(function (PipelineInterface $pipeline) use ($channel) {
            $pipeline->multi();
            $pipeline->get($this->prefix . ':channel-total:' . $channel); // Total
            $pipeline->llen($this->prefix . ':channel:' . $channel);   // Backlog
            $pipeline->exec();
        });
        $res = $res[3];
        $stats = [
            'total' => (int)$res[0],
            'backlog' => $res[1],
        ];
        $stats['complete'] = $stats['total'] - $stats['backlog'];
        return $stats;
    }

    /**
     * @param string $channel
     */
    public function timedOutTasks(string $channel): void
    {
        $zset = $this->prefix . ':channel-pending:' . $channel;
        $redis = $this->redis;
        $redis->watch($zset);
        $res = $redis->zrangebyscore($zset, '-inf', time(), ['limit' => [0, 1]]);
        $keys = [];
        foreach ($res as $value) {
            [$taskId, $timeout] = explode(':', $value, 2);
            $keys[] = $this->prefix . ':input:' . $taskId;
        }
        $mget = $redis->mget($keys);
        foreach ($res as $value) {
            [$taskId, $timeout] = explode(':', $value, 2);
            if ($timeout === '0') {
                continue;
            }
            $redis->multi();
            $redis->zAdd($zset, [$value => time() + $timeout]);
            $redis->sadd($this->prefix . ':list-channels', $channel);
            $redis->rpush($this->prefix . ':channel:' . $channel, $taskId);
            $redis->exec();
        }
        $this->timedOutTasks($channel);
    }

    /**
     * @param TaskAbstract $task
     * @throws \RedisClient\Exception\InvalidArgumentException
     */
    public function complete(TaskAbstract $task): void
    {
        $this->redis->pipeline(function (PipelineInterface $redis) use ($task) {
            $redis->multi();

            $taskId = $task->getId();

            $payload = igbinary_serialize($task);

            $redis->publish($this->prefix . ':output:' . $taskId, $payload);
            $redis->set($this->prefix . ':output:' . $taskId, $payload, $this->ttl);

            $channel = $task->getChannel();

            $redis->publish($this->prefix . ':complete-channel:' . $channel, $payload);

            if ($task->getTimeoutSeconds() > 0) {
                $this->redis->zrem(
                    $this->prefix . ':channel-pending:' . $channel,
                    [
                        $task->getId()
                    ]
                );
            }

            $redis->rPush($this->prefix . ':blpop:' . $taskId, range(1, 10));
            $redis->expire($this->prefix . ':blpop:' . $taskId, 15 * 60);
            $redis->del($this->prefix . ':input:' . $taskId);

            $redis->exec();
        });
    }
}
