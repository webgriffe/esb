<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Success;
use Amp\Iterator;
use Webgriffe\Esb\Model\JobInterface;

final class DummyRepeatProducer implements RepeatProducerInterface
{
    /** @var JobInterface[] */
    public static array $jobs;

    /**
     * DummyRepeatProducer constructor.
     */
    public function __construct(
        private readonly int $interval = 1,
    ) {
    }

    /**
     * @return Success<null>
     * @throws \Error
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * @return Iterator<JobInterface>
     *
     * @throws \TypeError
     */
    public function produce(mixed $data = null): Iterator
    {
        $iterator = Iterator\fromIterable(self::$jobs);
        self::$jobs = [];
        return $iterator;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }
}
