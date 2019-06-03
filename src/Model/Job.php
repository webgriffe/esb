<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Model;

final class Job implements JobInterface
{
    /**
     * @var array
     */
    private $payloadData;
    /**
     * @var int
     */
    private $timeout;
    /**
     * @var int
     */
    private $delay;
    /**
     * @var int
     */
    private $priority;

    /**
     * Job constructor.
     * @param array $payloadData
     * @param int $timeout
     * @param int $delay
     * @param int $priority
     */
    public function __construct(array $payloadData, int $timeout = 60, int $delay = 0, $priority = 0)
    {
        $this->payloadData = $payloadData;
        $this->timeout = $timeout;
        $this->delay = $delay;
        $this->priority = $priority;
    }

    /**
     * @return array
     */
    public function getPayloadData(): array
    {
        return $this->payloadData;
    }

    /**
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * @return int
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }
}
