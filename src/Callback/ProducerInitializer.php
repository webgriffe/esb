<?php

namespace Webgriffe\Esb\Callback;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\ProducerInterface;

class ProducerInitializer
{
    /**
     * @var ProducerInterface
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
        ProducerInterface $producer,
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger
    ) {

        $this->producer = $producer;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
    }

    public function __invoke()
    {
        yield $this->producer->init();
        yield $this->beanstalkClient->use($this->producer->getTube());
        $this->logger->info(
            'A Producer has been successfully initialized',
            ['producer' => \get_class($this->producer)]
        );
    }


}
