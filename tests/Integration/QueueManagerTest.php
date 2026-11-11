<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Failure;
use Amp\Http\Client\SocketException;
use Amp\Http\Client\TimeoutException;
use Psr\Log\NullLogger;
use Webgriffe\AmpElasticsearch\Error;
use Webgriffe\Esb\BeanstalkTestCase;
use Webgriffe\Esb\Exception\ElasticSearch\JobNotFoundException;
use Webgriffe\Esb\Exception\FatalQueueException;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Service\ElasticSearch;
use Webgriffe\Esb\Service\QueueManager;

class QueueManagerTest extends BeanstalkTestCase
{
    private const TUBE = 'queue_manager_test_tube';
    private const JOB_UUID = 'c9c1d4e2-6a0c-4d0c-9a53-0d2bb2d8d6f1';

    private ?BeanstalkClient $beanstalkClient = null;

    protected function tearDownAsync()
    {
        $this->beanstalkClient?->quit();
    }

    /**
     * @dataProvider elasticSearchUnavailableProvider
     */
    public function testGetNextJobReleasesJobAndThrowsFatalExceptionWhenElasticSearchIsUnavailable(string $errorType)
    {
        $error = $this->createError($errorType);
        $queueManager = yield from $this->createQueueManagerFailingToFetchJobsWith($error);
        $this->pheanstalk->putInTube(self::TUBE, self::JOB_UUID);

        try {
            yield $queueManager->getNextJob();
            $this->fail('The expected FatalQueueException has not been thrown');
        } catch (FatalQueueException $exception) {
            $this->assertSame($error, $exception->getPrevious());
        }

        $this->assertReadyJobsCountInTube(1, self::TUBE);
        $this->assertReservedJobsCountInTube(0, self::TUBE);
        $this->assertBuriedJobsCountInTube(0, self::TUBE);
    }

    /**
     * @dataProvider jobSpecificErrorProvider
     */
    public function testGetNextJobBuriesJobAndThrowsJobNotFoundExceptionWhenJobCannotBeFetchedForJobSpecificReasons(
        string $errorType
    ) {
        $queueManager = yield from $this->createQueueManagerFailingToFetchJobsWith(
            $this->createError($errorType)
        );
        $this->pheanstalk->putInTube(self::TUBE, self::JOB_UUID);

        try {
            yield $queueManager->getNextJob();
            $this->fail('The expected JobNotFoundException has not been thrown');
        } catch (JobNotFoundException $exception) {
            $this->assertStringContainsString(self::JOB_UUID, $exception->getMessage());
        }

        $this->assertReadyJobsCountInTube(0, self::TUBE);
        $this->assertReservedJobsCountInTube(0, self::TUBE);
        $this->assertBuriedJobsCountInTube(1, self::TUBE);
    }

    public function elasticSearchUnavailableProvider(): array
    {
        return [
            'connection error' => ['connection_error'],
            'timeout' => ['timeout'],
            'internal server error' => ['internal_server_error'],
            'service unavailable' => ['service_unavailable'],
        ];
    }

    public function jobSpecificErrorProvider(): array
    {
        return [
            'job not found' => ['job_not_found'],
            'bad request' => ['bad_request'],
            'job document cannot be denormalized' => ['denormalization_error'],
        ];
    }

    private function createError(string $errorType): \Throwable
    {
        return match ($errorType) {
            'connection_error' => new SocketException('Connection refused'),
            'timeout' => new TimeoutException('Inactivity timeout exceeded'),
            'internal_server_error' => new Error(null, 500),
            'service_unavailable' => new Error('{"error":{"type":"no_shard_available_action_exception"}}', 503),
            'job_not_found' => new JobNotFoundException(self::JOB_UUID),
            'bad_request' => new Error(null, 400),
            'denormalization_error' => new \RuntimeException('Cannot denormalize the job'),
        };
    }

    private function createQueueManagerFailingToFetchJobsWith(\Throwable $error): \Generator
    {
        $elasticSearch = $this->createMock(ElasticSearch::class);
        $elasticSearch->method('fetchJob')->willReturn(new Failure($error));

        $this->setTimeout(5000);
        $this->beanstalkClient = new BeanstalkClient(self::getBeanstalkdConnectionUri());
        $queueManager = new QueueManager(
            new FlowConfig(self::TUBE, ['description' => 'Queue manager test flow']),
            $this->beanstalkClient,
            $elasticSearch,
            new NullLogger(),
            1000
        );
        yield $queueManager->boot();

        return $queueManager;
    }
}
