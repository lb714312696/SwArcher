<?php
namespace Swlib\Archer;
class Queue {
    private static $instance, $size = 8192, $concurrent = 2048;
    /**
     * 队列的size，默认为8192。当待执行的Task数量超过size时，再投递Task会导致协程切换，直到待执行的Task数量小于size后才可恢复
     * 调用该方法改变size，必须在第一次投递任何Task之前调用。建议在 onWorkerStart 中调用
     *
     * @param unknown $size
     */
    public static function setQueueSize(int $size): void {
        self::$size = $size;
    }
    /**
     * 最大并发数，默认为2048。
     * 调用该方法改变concurrent，必须在第一次投递任何Task之前调用。建议在 onWorkerStart 中调用
     *
     * @param int $concurrent
     */
    public static function setConcurrent(int $concurrent): void {
        self::$concurrent = $concurrent;
    }
    // public static function set
    public static function getInstance(): Queue {
        if (! isset(self::$instance)) {
            self::$instance = new self();
            \Swoole\Coroutine::create([
                self::$instance,
                'loop'
            ]);
        }
        return self::$instance;
    }
    private $channel_queuing, $channel_running;
    private function __construct() {
        $this->channel_queuing = new \Swoole\Coroutine\Channel(self::$size);
        $this->channel_running = new \Swoole\Coroutine\Channel(self::$concurrent);
    }
    public function push(Task $task): bool {
        return $this->channel_queuing->push($task);
    }
    public function loop() {
        do {
            $task = $this->channel_queuing->pop();
            if (! $task instanceof Task)
                throw new Exception\RuntimeException('Queue pop error');
            $this->channel_running->push(true);
            \Swoole\Coroutine::create([
                $task,
                'execute'
            ]);
            unset($task);
        } while ( true );
    }
    public function isEmpty(): bool {
        return $this->channel_queuing->isEmpty();
    }
    public function isFull(): bool {
        return $this->channel_queuing->isFull();
    }
    /**
     *
     * @return array 返回三个数字，第一个是队列中待执行的Task数量，第二个是超过队列size的待执行Task数量，第三个是正在执行中的Task数量
     */
    public function stats(): array {
        $stats = $this->channel_queuing->stats();
        $stats2 = $this->channel_running->stats();
        return [
            $stats['queue_num'],
            $stats['producer_num'],
            $stats2['queue_num']
        ];
    }
    /**
     * 不要手动调用该方法！
     */
    public function taskOver(): void {
        $this->channel_running->pop(0.001);
    }
}