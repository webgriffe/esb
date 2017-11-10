<?php

namespace Webgriffe\Esb;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class Kernel
{
    const WORKER_TAG = 'esb.worker';

    private $container;

    public function __construct()
    {
        $this->container = new ContainerBuilder();
        $this->container->registerForAutoconfiguration(WorkerInterface::class)->addTag(self::WORKER_TAG);
        $this->configureContainer();
        $this->container->addCompilerPass(new WorkerPass());
        $this->container->compile();
    }

    /**
     * @return ContainerBuilder
     */
    public function getContainer()
    {
        return $this->container;
    }

    private function configureContainer()
    {
        $loader = new YamlFileLoader($this->container, new FileLocator(dirname(__DIR__)));
        $loader->load('config.yml');
    }
}
