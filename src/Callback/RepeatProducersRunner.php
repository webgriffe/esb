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
use Webgriffe\Esb\Service\BeanstalkClientFactory;

class RepeatProducersRunner
{
    use CallableMaker;

    /**
     * @var RepeatProducerInterface[]
     */
    private $producers;
    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var BeanstalkClient[]
     */
    private $beanstalkClients = [];

    public function __construct(
        array $producers,
        BeanstalkClientFactory $beanstalkClientFactory,
        LoggerInterface $logger
    ) {
        $this->producers = $producers;
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
    }

    public function __invoke()
    {
        foreach ($this->producers as $producer) {
            $beanstalkClient = $this->beanstalkClientFactory->create();
            $this->beanstalkClients[\get_class($producer)] = $beanstalkClient;
            yield call(new ProducerInitializer($producer, $beanstalkClient, $this->logger));
            Loop::repeat($producer->getInterval(), $this->callableFromInstanceMethod('repeatWatcher'), $producer);
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @throws Loop\InvalidWatcherError
     */
    private function repeatWatcher($watcherId, RepeatProducerInterface $producer)
    {
        $beanstalkClient = $this->beanstalkClients[\get_class($producer)];
        Loop::disable($watcherId);
        yield JobsQueuer::queueJobs($beanstalkClient, $this->logger, $producer);
        Loop::enable($watcherId);
    }
}
