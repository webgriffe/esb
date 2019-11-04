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
     * @var JobEventInterface[]
     */
    private $events;

    /**
     * Job constructor.
     * @param array $payloadData
     * @param int $timeout
     * @param int $delay
     * @param int $priority
     * @param array $events
     */
    public function __construct(
        array $payloadData,
        int $timeout = 60,
        int $delay = 0,
        $priority = 0,
        array $events = []
    ) {
        $this->payloadData = $payloadData;
        $this->timeout = $timeout;
        $this->delay = $delay;
        $this->priority = $priority;
        $this->events = $events;
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

    /**
     * @param JobEventInterface $event
     */
    public function addEvent(JobEventInterface $event): void
    {
        if ($this->getLastEvent() && $this->getLastEvent()->getTime() > $event->getTime()) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Cannot add event happened before the last one. Last event happened at "%s", ' .
                    'an event happened at "%s" has been given.',
                    $this->getLastEvent()->getTime()->format('c'),
                    $event->getTime()->format('c')
                )
            );
        }
        $this->events[] = $event;
    }

    /**
     * @return JobEventInterface[]
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return JobEventInterface|null
     */
    public function getLastEvent(): ?JobEventInterface
    {
        if (empty($this->events)) {
            return null;
        }
        return array_slice($this->events, -1)[0];
    }
}
