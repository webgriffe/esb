<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use Amp\CallableMaker;
use Amp\Loop;
use Cron\CronExpression;
use Monolog\Logger;
use Webgriffe\Esb\CrontabProducerInterface;
use Webgriffe\Esb\DateTimeBuilderInterface;
use Webgriffe\Esb\FlowInterface;
use Webgriffe\Esb\HttpRequestProducerInterface;
use Webgriffe\Esb\JobsQueuer;
use Webgriffe\Esb\Model\QueuedJob;
use Webgriffe\Esb\NonUtf8Cleaner;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\RepeatProducerInterface;
use Webgriffe\Esb\WorkerInterface;

class FlowManager
{
    const CRON_TICK_SECONDS = 60;

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
     * @var HttpProducersServer
     */
    private $httpProducersServer;

    /**
     * FlowManager constructor.
     * @param BeanstalkClientFactory $beanstalkClientFactory
     * @param Logger $logger
     * @param HttpProducersServer $httpProducersServer
     * @param DateTimeBuilderInterface $dateTimeBuilder
     */
    public function __construct(
        BeanstalkClientFactory $beanstalkClientFactory,
        Logger $logger,
        HttpProducersServer $httpProducersServer,
        DateTimeBuilderInterface $dateTimeBuilder
    ) {
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
        $this->dateTimeBuilder = $dateTimeBuilder;
        $this->httpProducersServer = $httpProducersServer;
    }

    public function bootFlows()
    {
        Loop::defer(function () {
            if (!\count($this->flows)) {
                $this->logger->notice('No flow to start.');
                return;
            }

            foreach ($this->flows as $flow) {
                yield from $this->bootFlowProducer($flow);
                $this->bootFlowWorkerInstances($flow);
            }
        });
    }

    /**
     * @param FlowInterface $flow
     */
    public function addFlow(FlowInterface $flow)
    {
        $this->flows[] = $flow;
    }

    private function bootFlowWorkerInstances(FlowInterface $flow)
    {
        for ($instanceId = 1; $instanceId <= $flow->getWorker()->getInstancesCount(); $instanceId++) {
            Loop::defer(function () use ($flow, $instanceId) {
                $beanstalkClient = $this->beanstalkClientFactory->create();
                $worker = $flow->getWorker();

                yield $worker->init();
                yield $beanstalkClient->watch($flow->getTube());
                yield $beanstalkClient->ignore('default');

                $this->logger->info(
                    'A Worker instance has been successfully initialized',
                    ['worker' => \get_class($worker), 'instance_id' => $instanceId]
                );

                while ($rawJob = yield $beanstalkClient->reserve()) {
                    $job = new QueuedJob($rawJob[0], unserialize($rawJob[1], ['allowed_classes' => false]));

                    $logContext = [
                        'worker' => \get_class($worker),
                        'instance_id' => $instanceId,
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
            });
        }
    }

    private function bootFlowProducer(FlowInterface $flow): \Generator
    {
        $beanstalkClient = $this->beanstalkClientFactory->create();
        $producer = $flow->getProducer();
        yield $producer->init();
        yield $beanstalkClient->use($flow->getTube());
        $this->logger->info(
            'A Producer has been successfully initialized',
            ['producer' => \get_class($producer)]
        );
        if ($producer instanceof RepeatProducerInterface) {
            Loop::repeat(
                $producer->getInterval(),
                function ($watcherId) use ($producer, $beanstalkClient) {
                    Loop::disable($watcherId);
                    yield JobsQueuer::queueJobs($beanstalkClient, $this->logger, $producer);
                    Loop::enable($watcherId);
                }
            );
        } elseif ($producer instanceof  HttpRequestProducerInterface) {
            if (!$this->httpProducersServer->isStarted()) {
                yield $this->httpProducersServer->start();
            }
            $this->httpProducersServer->addProducer($producer, $beanstalkClient);
        } elseif ($producer instanceof  CrontabProducerInterface) {
            yield from $this->cronTick($producer, $beanstalkClient);
            Loop::repeat(self::CRON_TICK_SECONDS * 1000, function () use ($producer, $beanstalkClient) {
                yield from $this->cronTick($producer, $beanstalkClient);
            });
        } else {
            throw new \RuntimeException(sprintf('Unknown producer type "%s".', \get_class($producer)));
        }
    }

    private function cronTick(CrontabProducerInterface $producer, BeanstalkClient $beanstalkClient): \Generator
    {
        $cronExpression = CronExpression::factory($producer->getCrontab());
        /** @var DateTimeBuilderInterface $dateTimeBuilder */
        $now = $this->dateTimeBuilder->build();
        if ($cronExpression->isDue($now)) {
            $this->logger->info(
                'Matched cron expression for a crontab producer.',
                [
                    'producer' => \get_class($producer),
                    'now_date' => $now->format('c'),
                    'cron_expression' => $producer->getCrontab()
                ]
            );
            yield JobsQueuer::queueJobs($beanstalkClient, $this->logger, $producer);
        }
    }
}
