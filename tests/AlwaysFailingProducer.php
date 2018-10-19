<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;
use Amp\Success;

class AlwaysFailingProducer implements RepeatProducerInterface
{
    /**
     * @var int
     */
    private $interval;

    public function __construct(int $interval)
    {
        $this->interval = $interval;
    }

    /**
     * @return Promise
     */
    public function init(): Promise
    {
        return new Success();
    }

    /**
     * @param mixed $data
     * @return Iterator An Amp Iterator that must emit Jobs.
     */
    public function produce($data = null): Iterator
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
