<?php

namespace Zer0\Queue\Worker;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Timer;
use Zer0\App;
use Zer0\Queue\Pools\BaseAsync;
use Zer0\Queue\TaskAbstract;

/**
 * Class Application
 * @package InterpalsD
 */
final class Application extends \PHPDaemon\Core\AppInstance
{
    /**
     * @var App
     */
    public $app;

    /**
     * @var BaseAsync
     */
    protected $pool;

    /**
     * @var \SplObjectStorage
     */
    protected $tasks;

    /**
     * Called when the worker is ready to go.
     * @return void
     */
    public function onReady()
    {
        $app = null;
        require ZERO_ROOT . '/vendor/zer0-framework/core/src/bootstrap.php';

        define('ZERO_ASYNC', 1);

        $this->app = $app;

        $this->tasks = new \SplObjectStorage;

        $this->pool = $this->app->factory('QueueAsync');

        $this->poll();

        setTimeout(function (Timer $timer) {
            $this->pool->listChannels(function ($channels) {
                foreach ($channels as $channel) {
                    $this->pool->timedOutTasks($channel);
                }
            });
            $timer->timeout(5e6);
        }, 1);
    }

    /**
     *
     */
    public function poll()
    {
        $this->pool->poll($this->config->channels->value ?? null, function (?TaskAbstract $task) {
            if ($task) {
                $this->tasks->attach($task);
                $task->setCallback(function (TaskAbstract $task) {
                    $this->pool->complete($task);
                    $this->tasks->detach($task);
                });
                Daemon::$process->setState(Daemon::WSTATE_BUSY);
                $task();
                Daemon::$process->setState(Daemon::WSTATE_IDLE);
            }
            if (!Daemon::$process->isTerminated() && !Daemon::$process->reload) {
                $this->poll();
            }
        });
    }
}
