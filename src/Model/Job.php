<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

use Ramsey\Uuid\Uuid;

final class Job implements JobInterface
{
    private readonly string $uuid;

    private readonly int $timeout;

    private readonly int $delay;

    private readonly int $priority;

    /**
     * @param array<string, mixed> $payloadData
     * @param array<JobEventInterface> $events
     *
     * TODO:    $events and $uuid constructor argument shouldn't be set by ProducerInterface implementations.
     *          They are declared as constructor arguments because the Serializer should be able to set them when
     *          deserializing/denormalizing Jobs.
     *          Maybe we need to change the design of this?
     */
    public function __construct(
        private readonly array $payloadData,
        int $timeout = 60,
        int $delay = 0,
        int $priority = 0,
        private array $events = [],
        ?string $uuid = null
    ) {
        if ($uuid === null) {
            $uuid = Uuid::uuid1()->toString();
        }
        $this->uuid = $uuid;

        $this->timeout = $timeout;
        $this->delay = $delay;
        $this->priority = $priority;
    }

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

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

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

    public function getLastEvent(): ?JobEventInterface
    {
        if (empty($this->events)) {
            return null;
        }

        return array_slice($this->events, -1)[0];
    }
}
