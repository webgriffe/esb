<?php

namespace Webgriffe\Esb;

use Amp\Loop;
use Monolog\Logger;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Webgriffe\Esb\Service\ProducerManager;
use Webgriffe\Esb\Service\WorkerManager;

class Kernel
{
    const WORKER_TAG = 'esb.worker';
    const PRODUCER_TAG = 'esb.producer';

    private $container;

    /**
     * Kernel constructor.
     * @param string $localConfig Local configuration absolute file path
     */
    public function __construct(string $localConfig)
    {
        $this->container = new ContainerBuilder();
        $this->container->registerForAutoconfiguration(WorkerInterface::class)->addTag(self::WORKER_TAG);
        $this->container->registerForAutoconfiguration(ProducerInterface::class)->addTag(self::PRODUCER_TAG);
        $this->container->addCompilerPass(new WorkerPass());
        $this->container->addCompilerPass(new ProducerPass());
        $loader = new YamlFileLoader($this->container, new FileLocator(dirname(__DIR__)));
        $loader->load('services.yml');
        $loader->load($localConfig);
        $this->container->compile();
    }

    public function boot()
    {
        /** @var WorkerManager $workerManager */
        $workerManager = $this->getContainer()->get(WorkerManager::class);
        $workerManager->bootWorkers();
        /** @var ProducerManager $producerManager */
        $producerManager = $this->getContainer()->get(ProducerManager::class);
        $producerManager->bootProducers();
        Loop::setErrorHandler([$this, 'errorHandler']);
        Loop::run();
    }

    /**
     * @return ContainerBuilder
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * @param \Throwable $exception
     * @throws \Throwable
     */
    public function errorHandler(\Throwable $exception)
    {
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);
        $logger->critical(
            'An uncaught exception occurred, ESB will be stopped now!',
            [
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace()
            ]
        );
        throw $exception;
    }
}
