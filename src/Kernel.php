<?php

namespace Webgriffe\Esb;

use Amp\Loop;
use Monolog\Logger;
use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
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
     * @var string
     */
    private $environment;
    /**
     * @var string
     */
    private $localConfigFilePath;

    /**
     * Kernel constructor.
     * @param string $localConfigFilePath Local configuration absolute file path
     * @param string $environment
     * @throws \Exception
     */
    public function __construct(string $localConfigFilePath, string $environment = null)
    {
        $this->localConfigFilePath = $localConfigFilePath;
        $this->environment = $environment;
        $this->container = new ContainerBuilder();
        $this->container->registerForAutoconfiguration(WorkerInterface::class)->addTag(self::WORKER_TAG);
        $this->container->registerForAutoconfiguration(ProducerInterface::class)->addTag(self::PRODUCER_TAG);
        $this->container->addCompilerPass(new WorkerPass());
        $this->container->addCompilerPass(new ProducerPass());
        $loader = new YamlFileLoader($this->container, new FileLocator(dirname(__DIR__)));
        $this->loadSystemConfiguration($loader);
        $this->loadLocalConfiguration($loader);
        $this->container->compile(true);
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

    /**
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * @param YamlFileLoader $loader
     * @throws \Exception
     */
    private function loadSystemConfiguration(YamlFileLoader $loader)
    {
        if ($this->environment) {
            try {
                $loader->load(sprintf('services_%s.yml', $this->environment));
            } catch (FileLocatorFileNotFoundException $exception) {
                $loader->load('services.yml');
            }
        } else {
            $loader->load('services.yml');
        }
    }

    /**
     * @param $loader
     */
    private function loadLocalConfiguration($loader)
    {
        if ($this->environment) {
            $localConfigPathinfo = pathinfo($this->localConfigFilePath);
            $environmentLocalConfigFile = sprintf(
                '%s/%s_%s.yml',
                $localConfigPathinfo['dirname'],
                $localConfigPathinfo['filename'],
                $this->environment
            );
            try {
                $loader->load($environmentLocalConfigFile);
            } catch (FileLocatorFileNotFoundException $exception) {
                $loader->load($this->localConfigFilePath);
            }
        } else {
            $loader->load($this->localConfigFilePath);
        }
    }
}
