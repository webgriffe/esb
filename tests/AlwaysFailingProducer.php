<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\JobInterface;

final class AlwaysFailingProducer implements RepeatProducerInterface
{
    public function __construct(
        private readonly int $interval,
    ) {
    }

    public function init(): Promise
    {
        return new Success();
    }

    /**
     * @return Iterator<JobInterface> An Amp Iterator that must emit Jobs.
     */
    public function produce(mixed $data = null): Iterator
    {
        throw new \RuntimeException('You cannot expect anything good from an always failing producer!');
    }

    public function getInterval(): int
    {
        return $this->interval;
    }
}
