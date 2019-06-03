<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Model;

interface JobInterface
{
    /**
     * @return array
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
}
