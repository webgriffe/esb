<?php

namespace Webgriffe\Esb\Callback;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\CallableMaker;
use Amp\Loop;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\RepeatProducerInterface;

class RepeatProducerRunner
{
    use CallableMaker;

    /**
     * @var RepeatProducerInterface
     */
    private $producer;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        RepeatProducerInterface $producer,
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger
    ) {
        $this->producer = $producer;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
    }

    public function __invoke()
    {
        yield call([$this->producer, 'init']);
        $this->logger->info(
            'A Producer has been successfully initialized',
            ['producer' => \get_class($this->producer)]
        );
        yield $this->beanstalkClient->use($this->producer->getTube());
        Loop::repeat($this->producer->getInterval(), $this->callableFromInstanceMethod('repeatWatcher'));
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function repeatWatcher($watcherId)
    {
        $producer = $this->producer;
        $beanstalkClient = $this->beanstalkClient;
        Loop::disable($watcherId);
        $jobs = $producer->produce();
        /** @var Job $job */
        foreach($jobs as $job) {
            try {
                $payload = serialize($job->getPayloadData());
                $jobId = yield $beanstalkClient->put($payload);
                $this->logger->info(
                    'Successfully produced a new Job',
                    [
                        'producer' => \get_class($producer),
                        'job_id' => $jobId,
                        'payload_data' => $job->getPayloadData()
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    'An error occurred producing a job.',
                    [
                        'producer' => \get_class($producer),
                        'payload_data' => $job->getPayloadData(),
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
        Loop::enable($watcherId);
    }
}
