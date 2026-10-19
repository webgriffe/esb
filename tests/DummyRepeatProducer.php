<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Success;
use Amp\Iterator;

final class DummyRepeatProducer implements RepeatProducerInterface
{
    public static array $jobs;

    /**
     * DummyRepeatProducer constructor.
     */
    public function __construct(
        private readonly int $interval = 1,
    ) {
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
     * @throws \TypeError
     */
    public function produce(mixed $data = null): Iterator
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
