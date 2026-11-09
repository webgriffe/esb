<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

final class BatchManagerFactory
{
    public function __construct(
        private readonly int $batchSize
    ) {
    }

    public function create(QueueBackendInterface $queueBackend): BatchManagerInterface
    {
        return new BatchManager($queueBackend, $this->batchSize);
    }
}
