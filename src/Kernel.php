<?php

namespace Webgriffe\Esb;

use Amp\Loop;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Webgriffe\Esb\Service\Producer\ProducerInterface;
use Webgriffe\Esb\Service\ProducerManager;
use Webgriffe\Esb\Service\Worker\WorkerInterface;
use Webgriffe\Esb\Service\WorkerManager;

class Kernel
{
    const WORKER_TAG = 'esb.worker';
    const PRODUCER_TAG = 'esb.producer';

    private $container;

    public function __construct()
    {
        $this->container = new ContainerBuilder();
        $this->container->registerForAutoconfiguration(WorkerInterface::class)->addTag(self::WORKER_TAG);
        $this->container->registerForAutoconfiguration(ProducerInterface::class)->addTag(self::PRODUCER_TAG);
        $this->container->addCompilerPass(new WorkerPass());
        $this->container->addCompilerPass(new ProducerPass());
        $loader = new YamlFileLoader($this->container, new FileLocator(dirname(__DIR__)));
        $loader->load('config.yml');
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
        Loop::run();
    }

    /**
     * @return ContainerBuilder
     */
    public function getContainer()
    {
        return $this->container;
    }
}
