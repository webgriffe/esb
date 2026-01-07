<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

final class BatchManagerFactory
{
    public function __construct(
        private readonly QueueBackendInterface $queueBackend,
        private readonly int $batchSize
    ) {
    }

    public function create(): BatchManagerInterface
    {
        return new BatchManager($this->queueBackend, $this->batchSize);
    }
}
