<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp;
use Amp\Elasticsearch\Client;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
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
     * @var NormalizerInterface&DenormalizerInterface
     */
    private $normalizer;

    public function __construct(Client $client, $normalizer)
    {
        $this->client = $client;
        Assert::isInstanceOfAny($normalizer, [NormalizerInterface::class, DenormalizerInterface::class]);
        $this->normalizer = $normalizer;
    }

    public function indexJob(JobInterface $job): Amp\Promise
    {
        return Amp\call(function () use ($job) {
            yield $this->client->indexDocument(
                self::INDEX_NAME,
                $job->getUuid(),
                (array)$this->normalizer->normalize($job, 'json')
            );
        });
    }

    public function fetchJob(string $uuid): Amp\Promise
    {
        return Amp\call(function () use ($uuid) {
            $response = yield $this->client->getDocument(self::INDEX_NAME, $uuid);
            if (!$response['found']) {
                throw new JobNotFoundException($uuid);
            }
            Assert::keyExists($response, '_source');
            return $this->normalizer->denormalize($response['_source'], Job::class, 'json');
        });
    }
}
