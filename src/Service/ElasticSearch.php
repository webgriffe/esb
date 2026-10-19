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
     * @param NormalizerInterface&DenormalizerInterface $normalizer
     */
    public function __construct(
        private readonly Client $client,
        private readonly NormalizerInterface $normalizer,
    ) {
        Assert::isInstanceOf($normalizer, DenormalizerInterface::class);
    }

    /**
     * @return Amp\Promise<null>
     */
    public function indexJob(JobInterface $job, string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($job, $indexName) {
            yield from $this->doIndexJob($job, $indexName);
        });
    }

    /**
     * @param JobInterface[] $jobs
     *
     * @return Amp\Promise<null>
     */
    public function bulkIndexJobs(array $jobs, string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($jobs, $indexName) {
            return yield from $this->doBulkIndexJobs($jobs, $indexName);
        });
    }

    /**
     * @return Amp\Promise<JobInterface>
     */
    public function fetchJob(string $uuid, string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($uuid, $indexName) {
            try {
                $response = yield from $this->doFetchJob($uuid, $indexName);
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
     * @return Amp\Promise<bool>
     */
    public function indexExists(string $indexName): Amp\Promise
    {
        return Amp\call(function () use ($indexName) {
            try {
                yield $this->client->existsIndex($indexName);
                return true;
            } catch (Error $error) {
                if ($error->getCode() === 404) {
                    return false;
                }
                throw $error;
            }
        });
    }

    public function setElasticSearchIndex(string $indexName): Amp\Promise
    {
        return $this->client->createIndex($indexName);
    }

    /**
     * @param array<string, mixed> $updateSettingsBody
     */
    public function setElasticSearchIndexSettings(string $indexName, ?array $updateSettingsBody = null): Amp\Promise
    {
        return $this->client->updateIndexSettings($indexName, $updateSettingsBody);
    }

    /**
     * @param array<string, mixed> $updateMappingBody
     */
    public function setElasticSearchIndexMapping(string $indexName, ?array $updateMappingBody = null): Amp\Promise
    {
        return $this->client->updateMappings($indexName, $updateMappingBody);
    }

    /**
     * @param array<string, mixed> $aliasBody
     */
    public function setElasticSearchIndexAlias(string $indexName, string $aliasName, ?array $aliasBody = null): Amp\Promise
    {
        return $this->client->createOrUpdateAlias($indexName, $aliasName, $aliasBody);
    }

    /**
     * @return Generator<Amp\Promise>
     * @throws ExceptionInterface
     */
    private function doIndexJob(JobInterface $job, string $indexName): Generator
    {
        $normalizedJob = (array)$this->normalizer->normalize($job, 'json');

        $retry = 0;
        while (true) {
            try {
                return yield $this->client->indexDocument($indexName, $job->getUuid(), $normalizedJob);
            } catch (Error $error) {
                $errorData = $error->getData();
                if (null === $errorData) {
                    throw $error;
                }
                $errorType = $errorData['error']['type'] ?? null;
                if ($errorType === 'no_shard_available_action_exception' &&
                    ++$retry <= self::NO_SHARD_AVAILABLE_INDEX_MAX_RETRY) {
                    // TODO Log no shard available and number of retrials
                    yield Amp\delay(1000);

                    continue;
                }

                throw $error;
            }
        }
    }

    /**
     * @param JobInterface[] $jobs
     *
     * @return Generator<Amp\Promise>
     * @throws ExceptionInterface
     */
    private function doBulkIndexJobs(array $jobs, string $indexName): Generator
    {
        $body = [];
        foreach ($jobs as $job) {
            $body[] = ['index' => ['_id' => $job->getUuid()]];
            $body[] = (array)$this->normalizer->normalize($job, 'json');
        }

        return yield $this->client->bulk($body, $indexName);
    }

    /**
     * @return Generator<Amp\Promise>
     */
    private function doFetchJob(string $uuid, string $indexName): Generator
    {
        $retry = 0;
        while (true) {
            try {
                return yield $this->client->getDocument($indexName, $uuid);
            } catch (Error $error) {
                $errorData = $error->getData();
                if (null === $errorData) {
                    throw $error;
                }
                $errorType = $errorData['error']['type'] ?? null;
                if ($errorType === 'no_shard_available_action_exception' &&
                    ++$retry < self::NO_SHARD_AVAILABLE_INDEX_MAX_RETRY) {
                    // TODO Log no shard available retrials
                    yield Amp\delay(1000);

                    continue;
                }

                throw $error;
            }
        }
    }
}
