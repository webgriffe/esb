<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Success;
use Amp\Iterator;

class DummyRepeatProducer implements RepeatProducerInterface
{
    /**
     * @var array
     */
    public static $jobs;
    /**
     * @var int
     */
    private $interval;

    /**
     * DummyRepeatProducer constructor.
     * @param int $interval
     */
    public function __construct(int $interval = 1)
    {
        $this->interval = $interval;
    }

    /**
     * @return Promise
     * @throws \Error
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * @param mixed $data
     * @return Iterator
     * @throws \TypeError
     */
    public function produce($data = null): Iterator
    {
        $iterator = Iterator\fromIterable(self::$jobs);
        self::$jobs = [];
        return $iterator;
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return $this->interval;
    }
}
