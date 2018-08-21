<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Promise;
use Monolog\Logger;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\QueuedJob;
use function Amp\call;

class WorkerInstance
{
    /**
     * @var FlowConfig
     */
    private $flowConfig;
    /**
     * @var int
     */
    private $instanceId;
    /**
     * @var WorkerInterface
     */
    private $worker;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var array
     */
    private static $workCounts = [];

    public function __construct(
        FlowConfig $flowConfig,
        int $instanceId,
        WorkerInterface $worker,
        BeanstalkClient $beanstalkClient,
        Logger $logger
    ) {
        $this->flowConfig = $flowConfig;
        $this->instanceId = $instanceId;
        $this->worker = $worker;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
    }

    public function boot(): Promise
    {
        return call(function () {
            yield $this->worker->init();
            yield $this->beanstalkClient->watch($this->flowConfig->getTube());
            yield $this->beanstalkClient->ignore('default');

            $this->logger->info(
                'A Worker instance has been successfully initialized',
                [
                    'flow' => $this->flowConfig->getDescription(),
                    'worker' => \get_class($this->worker),
                    'instance_id' => $this->instanceId
                ]
            );

            while ($rawJob = yield $this->beanstalkClient->reserve()) {
                $job = new QueuedJob($rawJob[0], unserialize($rawJob[1], ['allowed_classes' => false]));

                $logContext = [
                    'flow' => $this->flowConfig->getDescription(),
                    'worker' => \get_class($this->worker),
                    'instance_id' => $this->instanceId,
                    'job_id' => $job->getId(),
                    'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData())
                ];
                $this->logger->info('Worker reserved a Job', $logContext);

                try {
                    if (!array_key_exists($job->getId(), self::$workCounts)) {
                        self::$workCounts[$job->getId()] = 0;
                    }
                    ++self::$workCounts[$job->getId()];

                    yield $this->worker->work($job);
                    $this->logger->info('Successfully worked a Job', $logContext);

                    yield $this->beanstalkClient->delete($job->getId());
                    unset(self::$workCounts[$job->getId()]);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'An error occurred while working a Job.',
                        array_merge($logContext, ['error' => $e->getMessage()])
                    );

                    if (self::$workCounts[$job->getId()] >= $this->flowConfig->getWorkerMaxRetry()) {
                        yield $this->beanstalkClient->bury($job->getId());
                        $this->logger->critical(
                            'A Job reached maximum work retry limit and has been buried',
                            array_merge($logContext, ['last_error' => $e->getMessage()])
                        );
                        unset(self::$workCounts[$job->getId()]);
                        continue;
                    }

                    yield $this->beanstalkClient->release($job->getId(), $this->flowConfig->getWorkerReleaseDelay());
                    $this->logger->info('Worker released a Job', $logContext);
                }
            }
        });
    }
}
