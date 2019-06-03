<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Model;

interface QueuedJobInterface
{
    /**
     * @return int
     */
    public function getId(): int;

    /**
     * @return array
     */
    public function getPayloadData(): array;
}
