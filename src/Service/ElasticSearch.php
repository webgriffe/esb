<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp;
use Amp\Elasticsearch\Client;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
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
     * @var NormalizerInterface
     */
    private $normalizer;
    /**
     * @var string
     */
    private $indexRefresh;

    public function __construct(Client $client, NormalizerInterface $normalizer, array $options = [])
    {
        $this->client = $client;
        $this->normalizer = $normalizer;
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
                (array)$this->normalizer->normalize($job, 'json'),
                ['refresh' => $this->indexRefresh]
            );
        });
    }
}
