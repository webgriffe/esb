<?php

namespace Webgriffe\Esb\Callback;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\CallableMaker;
use Amp\Loop;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\JobsQueuer;
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
        yield call(new ProducerInitializer($this->producer, $this->beanstalkClient, $this->logger));
        Loop::repeat($this->producer->getInterval(), $this->callableFromInstanceMethod('repeatWatcher'));
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @throws Loop\InvalidWatcherError
     */
    private function repeatWatcher($watcherId)
    {
        $producer = $this->producer;
        $beanstalkClient = $this->beanstalkClient;
        Loop::disable($watcherId);
        JobsQueuer::queueJobs($beanstalkClient, $this->logger, $producer);
        Loop::enable($watcherId);
    }
}
