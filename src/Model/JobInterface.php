<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

interface JobInterface
{
    public function getUuid(): string;

    /**
     * @return array<string, mixed>
     */
    public function getPayloadData(): array;

    public function getTimeout(): int;

    public function getDelay(): int;

    public function getPriority(): int;

    public function addEvent(JobEventInterface $event): void;

    /**
     * @return JobEventInterface[]
     */
    public function getEvents(): array;

    public function getLastEvent(): ?JobEventInterface;
}
