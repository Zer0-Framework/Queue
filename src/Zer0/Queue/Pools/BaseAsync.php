<?php

namespace Zer0\Queue\Pools;

use Zer0\App;
use Zer0\Config\Interfaces\ConfigInterface;
use Zer0\Queue\Exceptions\WaitTimeoutException;
use Zer0\Queue\TaskAbstract;
use Zer0\Queue\TaskCollection;

/**
 * Class BaseAsync
 * @package Zer0\Queue\Pools
 */
abstract class BaseAsync
{
    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var App
     */
    protected $app;

    /**
     * Base constructor.
     * @param ConfigInterface $config
     * @param App $app
     */
    public function __construct(ConfigInterface $config, App $app)
    {
        $this->config = $config;
        $this->app = $app;
    }

    /**
     * @param callable $cb (int $id)
     */
    abstract public function nextId(callable $cb): void;

    /**
     * @param TaskAbstract $task
     * @param int $seconds
     * @param callable $cb (?TaskAbstract $task)
     */
    abstract public function wait(TaskAbstract $task, int $seconds, callable $cb): void;

    /**
     * @param TaskAbstract $task
     * @param callable $cb (TaskAbstract $success, BaseAsync $pool)
     */
    abstract public function push(TaskAbstract $task, ?callable $cb = null): void;

    /**
     * @param TaskAbstract $task
     * @deprecated
     * @param callable $cb (TaskAbstract $success, BaseAsync $pool)
     */
    final public function enqueue(TaskAbstract $task, ?callable $cb = null): void {
        $this->push($task, $cb);
    }

    /**
     * @param TaskAbstract $task
     * @param int $seconds
     * @param callable $cb
     */
    public function pushWait(TaskAbstract $task, int $seconds, callable $cb): void
    {
        $this->enqueue($task, function (TaskAbstract $task) use ($seconds, $cb): void {
            $this->wait($task, $seconds, $cb);
        });
    }

    /**
     * @param TaskAbstract $task
     * @param int $seconds
     * @param callable $cb
     */
    public function enqueueWait(TaskAbstract $task, int $seconds, callable $cb): void
    {
        $this->pushWait($task, $seconds, $cb);
    }

    /**
     * @param TaskAbstract $task
     * @param callable $cb
     */
    public function assignId(TaskAbstract $task, callable $cb): void
    {
        if ($task->getId() === null) {
            $this->nextId(static function (int $id) use ($task, $cb): void {
                $task->setId($id);
                $cb($task);
            });
        }
    }

    /**
     * @param TaskAbstract ...$tasks
     * @return TaskCollection
     */
    final public function collection(...$tasks): TaskCollection
    {
        $collection = new TaskCollection(...$tasks);
        $collection->setPoolAsync($this);
        return $collection;
    }

    /**
     * @param TaskCollection $collection
     * @param callable $cb
     */
    abstract public function waitCollection(TaskCollection $collection, callable $cb): void;
    
    /**
     * @param TaskAbstract $task
     */
    abstract public function complete(TaskAbstract $task): void;

    /**
     * @param array|null $channels
     * @param callable $cb (TaskAbstract $task)
     */
    abstract public function pop(?array $channels, callable $cb): void;

    /**
     * @param callable $cb (array $channels)
     */
    abstract public function listChannels(callable $cb): void;

    /**
     * @param string $channel
     */
    abstract public function timedOutTasks(string $channel): void;
}
