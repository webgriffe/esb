<?php

namespace Webgriffe\Esb\Service;

use Amp\Loop;
use Monolog\Logger;
use Prophecy\Argument;
use Webgriffe\Esb\BeanstalkTestCase;
use Webgriffe\Esb\DummyRepeatProducer;
use Webgriffe\Esb\Model\Job;

class ProducerManagerTest extends BeanstalkTestCase
{
    private $beanstalkClientFactory;
    private $logger;
    private $producerManager;

    public function setUp()
    {
        parent::setUp();
        $this->beanstalkClientFactory = $this->prophesize(BeanstalkClientFactory::class);
        $this->logger = $this->prophesize(Logger::class);
        $this->producerManager = new ProducerManager($this->getBeanstalkClientFactory(), $this->logger->reveal());
    }

    public function testBootProducersWithNoProducersShouldDoNothing()
    {
        $this->logger->notice('No producer to start.')->shouldBeCalled();
        $this->producerManager->bootProducers();
        Loop::run();
    }

    public function testBootProducersWithOneRepeatProducer()
    {
        $job1 = new Job(['job1 data']);
        $job2 = new Job(['job2 data']);
        $producer = new DummyRepeatProducer([$job1, $job2], 'test_tube', 1);
        $this->producerManager->addProducer($producer);
        $this->producerManager->bootProducers();
        Loop::delay(50, function () {Loop::stop();});
        Loop::run();

        $this->logger
            ->info(
                'A Producer has been successfully initialized',
                ['producer' => \get_class($producer)]
            )
            ->shouldHaveBeenCalledTimes(1)
        ;
        $this->loggerShouldHaveLoggedSuccessProducedJob($producer, $job1);
        $this->loggerShouldHaveLoggedSuccessProducedJob($producer, $job2);
        $this->assertReadyJobsCountInTube(2, $producer->getTube());
    }

    public function testBootProducersWithMultipleProducers()
    {
        $job1 = new Job(['job1 data']);
        $job2 = new Job(['job2 data']);
        $producer1 = new DummyRepeatProducer([$job1], 'tube1', 1);
        $producer2 = new DummyRepeatProducer([$job2], 'tube2', 1);
        $this->producerManager->addProducer($producer1);
        $this->producerManager->addProducer($producer2);
        $this->producerManager->bootProducers();
        Loop::delay(50, function () {Loop::stop();});
        Loop::run();

        $this->logger
            ->info(
                'A Producer has been successfully initialized',
                ['producer' => \get_class($producer1)]
            )
            ->shouldHaveBeenCalledTimes(2)
        ;
        $this->loggerShouldHaveLoggedSuccessProducedJob($producer1, $job1);
        $this->loggerShouldHaveLoggedSuccessProducedJob($producer2, $job2);
        $this->assertReadyJobsCountInTube(1, $producer1->getTube());
        $this->assertReadyJobsCountInTube(1, $producer2->getTube());
    }

    /**
     * @param $producer
     * @param $job
     */
    private function loggerShouldHaveLoggedSuccessProducedJob($producer, $job)
    {
        $this->logger
            ->info(
                'Successfully produced a new Job',
                Argument::allOf(
                    Argument::containing(\get_class($producer)),
                    Argument::containing($job->getPayloadData())
                )
            )
            ->shouldHaveBeenCalled();
    }

    /**
     * @return BeanstalkClientFactory
     */
    private function getBeanstalkClientFactory(): BeanstalkClientFactory
    {
        return new BeanstalkClientFactory($this->getBeanstalkdConnectionUri());
    }
}
