<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Loop;
use function Amp\Promise\all;
use function Amp\Promise\wait;
use Amp\ReactAdapter\ReactAdapter;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;
use React\Promise\Promise;
use Webgriffe\Esb\Callback\HttpRequestProducerRunner;
use Webgriffe\Esb\Callback\HttpServerRunner;
use Webgriffe\Esb\Callback\RepeatProducerRunner;
use Webgriffe\Esb\HttpRequestProducerInterface;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\RepeatProducerInterface;

class ProducerManager
{
    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ProducerInterface[]
     */
    private $producers;
    /**
     * @var int
     */
    private $httpServerPort;

    /**
     * ProducerManager constructor.
     * @param BeanstalkClientFactory $beanstalkClientFactory
     * @param Logger $logger
     */
    public function __construct(BeanstalkClientFactory $beanstalkClientFactory, Logger $logger, int $httpServerPort)
    {
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
        $this->httpServerPort = $httpServerPort;
    }

    public function bootProducers()
    {
        if (!\count($this->producers)) {
            $this->logger->notice('No producer to start.');
            return;
        }

        $httpRequestProcucers = [];
        foreach ($this->producers as $producer) {
            if ($producer instanceof RepeatProducerInterface) {
                Loop::defer(
                    new RepeatProducerRunner($producer, $this->beanstalkClientFactory->create(), $this->logger)
                );
            } else if ($producer instanceof  HttpRequestProducerInterface) {
                $httpRequestProcucers[] = $producer;
            } else {
                throw new \RuntimeException(sprintf('Unknown producer type "%s".', get_class($producer)));
            }
        }

        if (\count($httpRequestProcucers)) {
            Loop::defer(
                new HttpServerRunner(
                    $httpRequestProcucers,
                    $this->httpServerPort,
                    $this->beanstalkClientFactory,
                    $this->logger
                )
            );
        }
    }

    public function addProducer(ProducerInterface $producer)
    {
        $this->producers[] = $producer;
    }
}
