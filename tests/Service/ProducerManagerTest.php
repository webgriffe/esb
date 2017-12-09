<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Loop;
use Amp\Success;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Webgriffe\Esb\DummyRepeatProducer;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\ProducerInterface;

class ProducerManagerTest extends TestCase
{
    private $beanstalkClientFactory;
    private $logger;
    private $producerManager;

    public function setUp()
    {
        $this->beanstalkClientFactory = $this->prophesize(BeanstalkClientFactory::class);
        $this->logger = $this->prophesize(Logger::class);
        $this->producerManager = new ProducerManager($this->beanstalkClientFactory->reveal(), $this->logger->reveal());
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
        $job1Id = 1;
        $job2Id = 2;
        $producer = new DummyRepeatProducer([$job1, $job2]);
        $beanstalkClient = $this->getBeanstalkClientMockForProducer($producer);
        $beanstalkClient->put(serialize($job1->getPayloadData()))->shouldBeCalled()->willReturn(new Success($job1Id));
        $beanstalkClient->put(serialize($job2->getPayloadData()))->shouldBeCalled()->willReturn(new Success($job2Id));
        $this->beanstalkClientFactory->create()->shouldBeCalled()->willReturn($beanstalkClient->reveal());
        $this->producerManager->addProducer($producer);
        $this->producerManager->bootProducers();
        Loop::run();

        $this->logger
            ->info(
                'A Producer has been successfully initialized',
                ['producer' => \get_class($producer)]
            )
            ->shouldHaveBeenCalledTimes(1)
        ;
        $this->logger
            ->info(
                'Successfully produced a new Job',
                [
                    'producer' => \get_class($producer),
                    'job_id' => $job1Id,
                    'payload_data' => $job1->getPayloadData()
                ]
            )
            ->shouldHaveBeenCalled()
        ;
        $this->logger
            ->info(
                'Successfully produced a new Job',
                [
                    'producer' => \get_class($producer),
                    'job_id' => $job2Id,
                    'payload_data' => $job2->getPayloadData()
                ]
            )
            ->shouldHaveBeenCalled()
        ;
    }


    /**
     * @param $producer
     * @return BeanstalkClient|\Prophecy\Prophecy\ObjectProphecy
     */
    private function getBeanstalkClientMockForProducer(ProducerInterface $producer)
    {
        $beanstalkClient = $this->prophesize(BeanstalkClient::class);
        $beanstalkClient->use($producer->getTube())->shouldBeCalled()->willReturn(new Success(null));
        return $beanstalkClient;
    }
}
