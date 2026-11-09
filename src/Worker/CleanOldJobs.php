<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Worker;

use Amp\Promise;
use Amp\Success;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\WorkerInterface;
use Webmozart\Assert\Assert;
use function Amp\call;

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

            Assert::isArray($oldDocuments);
            Assert::keyExists($oldDocuments, 'hits');
            Assert::isArray($oldDocuments['hits']);
            Assert::keyExists($oldDocuments['hits'], 'hits');
            Assert::isArray($oldDocuments['hits']['hits']);
            foreach ($oldDocuments['hits']['hits'] as $oldDocument) {
                Assert::isArray($oldDocument);
                Assert::string($oldDocument['_index'] ?? null);
                Assert::string($oldDocument['_id'] ?? null);
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
