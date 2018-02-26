<?php

namespace Webgriffe\Esb\Service;

use Amp\Loop;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Webgriffe\Esb\Callback\CrontabProducersRunner;
use Webgriffe\Esb\Callback\HttpServerRunner;
use Webgriffe\Esb\Callback\RepeatProducersRunner;
use Webgriffe\Esb\CrontabProducerInterface;
use Webgriffe\Esb\DateTimeBuilderInterface;
use Webgriffe\Esb\HttpRequestProducerInterface;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\RepeatProducerInterface;

class ProducerManager implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ProducerInterface[]
     */
    private $producers;

    /**
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \RuntimeException
     */
    public function bootProducers()
    {
        /** @var BeanstalkClientFactory $beanstalkClientFactory */
        $beanstalkClientFactory = $this->container->get(BeanstalkClientFactory::class);
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);

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
            } else if ($producer instanceof  HttpRequestProducerInterface) {
                $httpRequestProducers[] = $producer;
            } else if ($producer instanceof  CrontabProducerInterface) {
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
            $httpPort = $this->container->getParameter('http_server_port');
            Loop::defer(
                new HttpServerRunner($httpRequestProducers, $httpPort, $beanstalkClientFactory, $logger)
            );
        }

        if (\count($crontabProducers)) {
            /** @var DateTimeBuilderInterface $dateTimeBuilder */
            $dateTimeBuilder = $this->container->get(DateTimeBuilderInterface::class);
            Loop::defer(
                new CrontabProducersRunner(
                    $crontabProducers,
                    $beanstalkClientFactory,
                    $dateTimeBuilder,
                    $logger
                )
            );
        }
    }

    public function addProducer(ProducerInterface $producer)
    {
        $this->producers[] = $producer;
    }
}
