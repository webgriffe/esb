<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp;
use Generator;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\AmpElasticsearch\Error;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\JobInterface;
use Webmozart\Assert\Assert;

class ElasticSearch
{
    private const NO_SHARD_AVAILABLE_INDEX_MAX_RETRY = 10;

    /**
     * @var Client
     */
    private $client;
    /**
     * @var NormalizerInterface&DenormalizerInterface
     */
    private $normalizer;

    /**
     * @param Client $client
     * @param NormalizerInterface&DenormalizerInterface $normalizer
     */
    public function __construct(Client $client, $normalizer)
    {
        $this->client = $client;
        Assert::isInstanceOf($normalizer, NormalizerInterface::class);
        Assert::isInstanceOf($normalizer, DenormalizerInterface::class);
        $this->normalizer = $normalizer;
    }

    /**
     * @param JobInterface $job
     * @param string $indexName
     * @return Amp\Promise<null>
     */
    public function indexJob(JobInterface $job, string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($job, $indexName) {
            yield from $this->doIndexJob($job, $indexName, 0);
        });
    }

    /**
     * @param JobInterface[] $jobs
     * @param string $indexName
     * @return Amp\Promise<null>
     */
    public function bulkIndexJobs(array $jobs, string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($jobs, $indexName) {
            yield from $this->doBulkIndexJobs($jobs, $indexName);
        });
    }

    /**
     * @param string $uuid
     * @param string $indexName
     * @return Amp\Promise<JobInterface>
     */
    public function fetchJob(string $uuid, string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($uuid, $indexName) {
            try {
                $response = yield from $this->doFetchJob($uuid, $indexName, 0);
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

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @param JobInterface $job
     * @param string $indexName
     * @param int $retry
     * @return Generator<Amp\Promise>
     * @throws ExceptionInterface
     */
    private function doIndexJob(JobInterface $job, string $indexName, int $retry): Generator
    {
        try {
            yield $this->client->indexDocument(
                $indexName,
                $job->getUuid(),
                (array)$this->normalizer->normalize($job, 'json')
            );
        } catch (Error $error) {
            $errorData = $error->getData();
            if (null === $errorData) {
                throw $error;
            }
            $errorType = $errorData['error']['type'] ?? null;
            if ($errorType === 'no_shard_available_action_exception' &&
                $retry < self::NO_SHARD_AVAILABLE_INDEX_MAX_RETRY) {
                // TODO Log no shard available retrials and refactor
                yield Amp\delay(1000);
                yield from $this->doIndexJob($job, $indexName, ++$retry);
                return;
            }
            throw $error;
        }
    }

    /**
     * @param JobInterface[] $jobs
     * @param string $indexName
     * @return Generator
     * @throws ExceptionInterface
     */
    private function doBulkIndexJobs(array $jobs, string $indexName): Generator
    {
        $body = [];
        foreach ($jobs as $job) {
            $body[] = ['index' => ['_id' => $job->getUuid()]];
            $body[] = (array)$this->normalizer->normalize($job, 'json');
        }
        yield $this->client->bulk($body, $indexName);
    }

    /**
     * @param string $uuid
     * @param string $indexName
     * @param int $retry
     * @return Generator<Amp\Promise>
     */
    private function doFetchJob(string $uuid, string $indexName, int $retry): Generator
    {
        try {
            return yield $this->client->getDocument(
                $indexName,
                $uuid
            );
        } catch (Error $error) {
            $errorData = $error->getData();
            if (null === $errorData) {
                throw $error;
            }
            $errorType = $errorData['error']['type'] ?? null;
            if ($errorType === 'no_shard_available_action_exception' &&
                $retry < self::NO_SHARD_AVAILABLE_INDEX_MAX_RETRY) {
                // TODO Log no shard available retrials and refactor
                yield Amp\delay(1000);
                return yield from $this->doFetchJob($uuid, $indexName, ++$retry);
            }
            throw $error;
        }
    }
}
