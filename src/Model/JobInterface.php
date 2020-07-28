<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Model;

interface JobInterface
{
    /**
     * @return string
     */
    public function getUuid(): string;

    /**
     * @return array<string, mixed>
     */
    public function getPayloadData(): array;

    /**
     * @return int
     */
    public function getTimeout(): int;

    /**
     * @return int
     */
    public function getDelay(): int;

    /**
     * @return int
     */
    public function getPriority(): int;

    /**
     * @param JobEventInterface $tag
     */
    public function addEvent(JobEventInterface $tag): void;

    /**
     * @return JobEventInterface[]
     */
    public function getEvents(): array;

    /**
     * @return JobEventInterface|null
     */
    public function getLastEvent(): ?JobEventInterface;
}
