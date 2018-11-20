<?php
/**
 * Copyright: Swlib
 * Author: fdream <fdream.lhl@foxmail.com>
 * Date: 2018/11/19
 */
namespace Swlib;
use Swlib\Archer\Queue;

class Archer {
    /**
     * 投递一个Task进入队列异步执行，该方法立即返回
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后将该异常作为第三个参数传递给$finish_callback，若未设置则会产生一个warnning。
     * 注意2：Task执行时的协程与当前协程不是同一个
     * 注意3：Task执行时的协程与回调函数执行时的协程是同一个
     *
     * @param callable $task_callback
     *            需要执行的函数
     * @param array $params
     *            传递进$task_callback中的参数，可缺省
     * @param callable $finish_callback
     *            $task_callback完成后触发的回调，参数1为Task的id，参数2为$task_callback的返回值，参数3为Task内抛出的\Throwable异常，参数2和3只会存在一个。可缺省
     * @throws Archer\Exception\AddNewTaskFailException
     * @return int|NULL Task的id
     */
    public static function task(callable $task_callback, ?array $params = null, ?callable $finish_callback = null): int {
        $task = new Archer\Task\Async($task_callback, $params, $finish_callback);
        if (! Queue::getInstance()->push($task))
            throw new Archer\Exception\AddNewTaskFailException();
        return $task->getId();
    }
    /**
     * 投递一个Task进入队列，同时当前协程挂起。当该Task执行完成后，会恢复投递的协程，并返回Task的返回值。
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后在这里抛出。
     * 注意2：Task执行时的协程与当前协程不是同一个
     *
     * @param callable $task_callback
     *            需要执行的函数
     * @param array $params
     *            传递进$task_callback中的参数，可缺省
     * @param float $timeout
     *            超时时间，超时后函数会直接返回。注意：超时返回后Task仍会继续执行，不会中断。若缺省则表示不会超时
     * @throws Archer\Exception\AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     * @throws Archer\Exception\TaskTimeoutException 超时时抛出的Exception，注意这个超时不会影响Task的执行。
     * @return mixed $task_callback的返回值
     */
    public static function taskWait(callable $task_callback, ?array $params = null, ?float $timeout = null) {
        if (isset($timeout))
            $start_time = microtime(true);

        $result_receiver = new \Swoole\Coroutine\Channel();
        $task = new Archer\Task\Co($task_callback, $params, $result_receiver);
        if (! Queue::getInstance()->push($task))
            throw new Archer\Exception\AddNewTaskFailException();

        if (isset($timeout)) {
            // 由于上面的操作可能会发生协程切换占用时间，这里调整一下pop的timeout减少时间误差
            $time_pass = microtime(true) - $start_time;
            if ($time_pass < $timeout) {
                $result = $result_receiver->pop($timeout - $time_pass);
                // Task将会把返回值放入数组中push进channel中，以区分开用户可能会返回的false和因超时返回的false
                if (is_array($result))
                    return current($result);
                elseif ($result instanceof \Throwable)
                    throw $result;
            }
            throw new Archer\Exception\TaskTimeoutException();
        } else {
            $result = $result_receiver->pop();
            if ($result instanceof \Throwable)
                throw $result;
            return current($result);
        }
    }
    /**
     * 获取多Task的处理容器，每次执行都是获取一个全新的对象
     *
     * @return Archer\MultiTask
     */
    public static function getMultiTask(): Archer\MultiTask {
        return new Archer\MultiTask();
    }
}