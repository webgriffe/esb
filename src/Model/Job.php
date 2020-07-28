<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Model;

use Ramsey\Uuid\Uuid;

final class Job implements JobInterface
{
    /**
     * @var string
     */
    private $uuid;
    /**
     * @var array<string, mixed>
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
     * @param array<string, mixed> $payloadData
     * @param int $timeout
     * @param int $delay
     * @param int $priority
     * @param array<JobEventInterface> $events
     * @param string|null $uuid
     * @throws \Exception
     *
     * TODO:    $events and $uuid constructor argument shouldn't be set by ProducerInterface implementations.
     *          They are declared as constructor arguments because the Serializer should be able to set them when
     *          deserializing/denormalizing Jobs.
     *          Maybe we need to change the design of this?
     */
    public function __construct(
        array $payloadData,
        int $timeout = 60,
        int $delay = 0,
        $priority = 0,
        array $events = [],
        string $uuid = null
    ) {
        if ($uuid === null) {
            $uuid = Uuid::uuid1()->toString();
        }
        $this->uuid = $uuid;
        $this->payloadData = $payloadData;
        $this->timeout = $timeout;
        $this->delay = $delay;
        $this->priority = $priority;
        $this->events = $events;
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * @return array<string, mixed>
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
        if ($this->getLastEvent() !== null && $this->getLastEvent()->getTime() > $event->getTime()) {
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
