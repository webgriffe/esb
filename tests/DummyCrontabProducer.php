<?php

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\Job;

final class DummyCrontabProducer implements CrontabProducerInterface
{
    /**
     * @var Job[]
     */
    public static array $jobs = [];

    public function getCrontab(): string
    {
        return '0 * * * *'; // Runs every hour at minute 0
    }

    /**
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
}
