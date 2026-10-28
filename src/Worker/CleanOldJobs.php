<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Worker;

use function Amp\call;
use Amp\Promise;
use Amp\Success;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\WorkerInterface;

class CleanOldJobs implements WorkerInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly int $maxAgeInDays,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function work(JobInterface $job): Promise
    {
        return call(function () {
            $oldDocuments = yield $this->client->search(
                ['range' => ['lastEvent.time' => ['lte' => sprintf('now-%sd', $this->maxAgeInDays)]]]
            );

            foreach ($oldDocuments['hits']['hits'] as $oldDocument) {
                yield $this->client->deleteDocument($oldDocument['_index'], $oldDocument['_id']);
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function init(): Promise
    {
        return new Success();
    }
}
