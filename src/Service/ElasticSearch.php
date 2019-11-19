<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\AmpElasticsearch\Error;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
use Webmozart\Assert\Assert;

class ElasticSearch
{
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

    public function indexJob(JobInterface $job, string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($job, $indexName) {
            yield $this->client->indexDocument(
                $indexName,
                $job->getUuid(),
                (array)$this->normalizer->normalize($job, 'json')
            );
        });
    }

    public function fetchJob(string $uuid, string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($uuid, $indexName) {
            try {
                $response = yield $this->client->getDocument($indexName, $uuid);
            } catch (Error $error) {
                if ($error->getCode() === 404) {
                    throw new JobNotFoundException($uuid);
                }
                throw $error;
            }
            Assert::keyExists($response, '_source');
            return $this->normalizer->denormalize($response['_source'], Job::class, 'json');
        });
    }
}
