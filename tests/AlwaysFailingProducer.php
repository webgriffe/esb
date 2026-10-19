<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;
use Amp\Success;

final class AlwaysFailingProducer implements RepeatProducerInterface
{
    public function __construct(
        private readonly int $interval,
    ) {
    }

    /**
     * @return Promise
     */
    public function init(): Promise
    {
        return new Success();
    }

    /**
     * @return Iterator An Amp Iterator that must emit Jobs.
     */
    public function produce(mixed $data = null): Iterator
    {
        throw new \RuntimeException('You cannot expect anything good from an always failing producer!');
    }

    /**
     * @return int
     */
    public function getInterval(): int
    {
        return $this->interval;
    }
}
