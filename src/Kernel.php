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

    public function __construct(string $localConfig)
    {
        $this->container = new ContainerBuilder();
        $this->container->registerForAutoconfiguration(WorkerInterface::class)->addTag(self::WORKER_TAG);
        $this->container->registerForAutoconfiguration(ProducerInterface::class)->addTag(self::PRODUCER_TAG);
        $this->container->addCompilerPass(new WorkerPass());
        $this->container->addCompilerPass(new ProducerPass());
        $loader = new YamlFileLoader($this->container, new FileLocator(dirname(__DIR__)));
        $loader->load(rtrim(getcwd(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $localConfig);
        $loader->load('services.yml');
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

    public function errorHandler(\Throwable $exception)
    {
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);
        $logger->error(
            'An error occurred...',
            [
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace()
            ]
        );
    }
}
