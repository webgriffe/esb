<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\CallableMaker;
use Amp\Loop;
use Monolog\Logger;
use Webgriffe\Esb\Callback\CrontabProducersRunner;
use Webgriffe\Esb\Callback\HttpServerRunner;
use Webgriffe\Esb\Callback\RepeatProducersRunner;
use Webgriffe\Esb\CrontabProducerInterface;
use Webgriffe\Esb\DateTimeBuilderInterface;
use Webgriffe\Esb\FlowInterface;
use Webgriffe\Esb\HttpRequestProducerInterface;
use Webgriffe\Esb\Model\QueuedJob;
use Webgriffe\Esb\NonUtf8Cleaner;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\RepeatProducerInterface;
use Webgriffe\Esb\WorkerInterface;
use function Amp\call;

class FlowManager
{
    use CallableMaker;

    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var FlowInterface[]
     */
    private $flows = [];

    /**
     * @var array
     */
    private $workCounts = [];

    /**
     * @var DateTimeBuilderInterface
     */
    private $dateTimeBuilder;

    /**
     * @var int
     */
    private $httpServerPort;

    /**
     * @var ProducerInterface[]
     */
    private $producers = [];

    /**
     * FlowManager constructor.
     * @param BeanstalkClientFactory $beanstalkClientFactory
     * @param Logger $logger
     * @param DateTimeBuilderInterface $dateTimeBuilder
     * @param int $httpServerPort
     */
    public function __construct(
        BeanstalkClientFactory $beanstalkClientFactory,
        Logger $logger,
        DateTimeBuilderInterface $dateTimeBuilder,
        int $httpServerPort
    ) {
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
        $this->dateTimeBuilder = $dateTimeBuilder;
        $this->httpServerPort = $httpServerPort;
    }

    public function bootFlows()
    {
        if (!\count($this->flows)) {
            $this->logger->notice('No flow to start.');
            return;
        }

        foreach ($this->flows as $flow) {
            $worker = $flow->getWorker();
            $this->producers[] = $flow->getProducer();
            for ($instanceIndex = 1; $instanceIndex <= $worker->getInstancesCount(); $instanceIndex++) {
                Loop::defer(function () use ($worker, $instanceIndex) {
                    yield call($this->callableFromInstanceMethod('bootWorkerInstance'), $worker, $instanceIndex);
                });
            }
        }

        $this->bootProducers();
    }

    /**
     * @param FlowInterface $flow
     */
    public function addFlow(FlowInterface $flow)
    {
        $this->flows[] = $flow;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param WorkerInterface $worker
     * @param int             $instanceIndex
     *
     * @return \Generator
     */
    private function bootWorkerInstance(WorkerInterface $worker, int $instanceIndex): \Generator
    {
        $beanstalkClient = $this->beanstalkClientFactory->create();

        yield $worker->init();
        yield $beanstalkClient->watch($worker->getTube());
        yield $beanstalkClient->ignore('default');

        $this->logger->info(
            'A Worker has been successfully initialized',
            ['worker' => \get_class($worker), 'instance_index' => $instanceIndex]
        );

        while ($rawJob = yield $beanstalkClient->reserve()) {
            $job = new QueuedJob($rawJob[0], unserialize($rawJob[1], ['allowed_classes' => false]));

            $logContext = [
                'worker' => \get_class($worker),
                'instance_index' => $instanceIndex,
                'job_id' => $job->getId(),
                'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData())
            ];
            $this->logger->info('Worker reserved a Job', $logContext);

            try {
                if (!array_key_exists($job->getId(), $this->workCounts)) {
                    $this->workCounts[$job->getId()] = 0;
                }
                ++$this->workCounts[$job->getId()];

                yield $worker->work($job);
                $this->logger->info('Successfully worked a Job', $logContext);

                yield $beanstalkClient->delete($job->getId());
                unset($this->workCounts[$job->getId()]);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'An error occurred while working a Job.',
                    array_merge($logContext, ['error' => $e->getMessage()])
                );

                if ($this->workCounts[$job->getId()] >= 5) {
                    yield $beanstalkClient->bury($job->getId());
                    $this->logger->critical(
                        'A Job reached maximum work retry limit and has been buried',
                        array_merge($logContext, ['last_error' => $e->getMessage()])
                    );
                    unset($this->workCounts[$job->getId()]);
                    continue;
                }

                yield $beanstalkClient->release($job->getId(), $worker->getReleaseDelay());
                $this->logger->info('Worker released a Job', $logContext);
            }
        }
    }

    private function bootProducers()
    {
        /** @var BeanstalkClientFactory $beanstalkClientFactory */
        $beanstalkClientFactory = $this->beanstalkClientFactory;
        /** @var Logger $logger */
        $logger = $this->logger;

        if (!\count($this->producers)) {
            $logger->notice('No producer to start.');
            return;
        }

        /** @var RepeatProducerInterface[] $repeatProducers */
        $repeatProducers = [];
        /** @var HttpRequestProducerInterface[] $httpRequestProducers */
        $httpRequestProducers = [];
        /** @var CrontabProducerInterface[] $crontabProducers */
        $crontabProducers = [];
        foreach ($this->producers as $producer) {
            if ($producer instanceof RepeatProducerInterface) {
                $repeatProducers[] = $producer;
            } elseif ($producer instanceof  HttpRequestProducerInterface) {
                $httpRequestProducers[] = $producer;
            } elseif ($producer instanceof  CrontabProducerInterface) {
                $crontabProducers[] = $producer;
            } else {
                throw new \RuntimeException(sprintf('Unknown producer type "%s".', \get_class($producer)));
            }
        }

        if (\count($repeatProducers)) {
            Loop::defer(
                new RepeatProducersRunner($repeatProducers, $beanstalkClientFactory, $logger)
            );
        }

        if (\count($httpRequestProducers)) {
            Loop::defer(
                new HttpServerRunner($httpRequestProducers, $this->httpServerPort, $beanstalkClientFactory, $logger)
            );
        }

        if (\count($crontabProducers)) {
            Loop::defer(
                new CrontabProducersRunner(
                    $crontabProducers,
                    $beanstalkClientFactory,
                    $this->dateTimeBuilder,
                    $logger
                )
            );
        }
    }
}
