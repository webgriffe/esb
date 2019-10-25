<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp;
use Amp\Elasticsearch\Client;
use Webgriffe\Esb\Model\JobInterface;
use Webmozart\Assert\Assert;

class ElasticSearch
{
    // TODO: Saving everything on the same index seems a bad idea...
    public const INDEX_NAME = 'esb_job';

    /**
     * @var Client
     */
    private $client;
    /**
     * @var string
     */
    private $indexRefresh;

    public function __construct(Client $client, array $options = [])
    {
        $this->client = $client;
        $indexRefresh = $options['indexRefresh'] ?? 'false';
        Assert::oneOf($indexRefresh, ['true', 'false', 'wait_for']);
        $this->indexRefresh = $indexRefresh;
    }

    public function indexNewJob(JobInterface $job): Amp\Promise
    {
        return Amp\call(function () use ($job) {
            yield $this->client->indexDocument(
                self::INDEX_NAME,
                '',
                $this->convertJobToDocument($job),
                ['refresh' => $this->indexRefresh]
            );
        });
    }

    private function convertJobToDocument(JobInterface $job): array
    {
        return [
            'job' => [
                'payloadData' => $job->getPayloadData(),
                'priority' => $job->getPriority(),
                'delay' => $job->getDelay(),
                'timeout' => $job->getTimeout(),
            ]
        ];
    }

    /**
     * @return string
     */
    public function getIndexRefresh(): string
    {
        return $this->indexRefresh;
    }
}
