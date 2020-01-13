<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Loop;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Webgriffe\Esb\Console\ContainerExtension as ConsoleContainerExtension;
use Webgriffe\Esb\Console\Server;

/**
 * @internal
 */
class Kernel
{
    /**
     * @var ContainerBuilder
     */
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
        AnnotationRegistry::registerUniqueLoader('class_exists');
        $this->localConfigFilePath = $localConfigFilePath;
        $this->environment = $environment;
        $this->container = new ContainerBuilder();
        $loader = new YamlFileLoader($this->container, new FileLocator(dirname(__DIR__)));
        $this->loadSystemConfiguration($loader);
        $this->container->registerExtension(new ConsoleContainerExtension());
        $this->container->registerExtension(new FlowExtension());
        $this->loadLocalConfiguration($loader);
        $this->container->compile(true);
    }

    /**
     * @throws \Exception
     */
    public function boot()
    {
        Loop::setErrorHandler([$this, 'errorHandler']);
        /** @var FlowManager $flowManager */
        $flowManager = $this->getContainer()->get(FlowManager::class);
        $flowManager->bootFlows();
        /** @var Server $consoleServer */
        $consoleServer = $this->getContainer()->get('console.server');
        $consoleServer->boot();
        Loop::onSignal(SIGINT, [$this, 'sigintHandler']);
        Loop::run();
    }

    /**
     * @return ContainerBuilder
     */
    public function getContainer(): ContainerBuilder
    {
        return $this->container;
    }

    /**
     * @param \Throwable $exception
     * @throws \Throwable
     */
    public function errorHandler(\Throwable $exception)
    {
        /** @var LoggerInterface $logger */
        $logger = $this->getContainer()->get(LoggerInterface::class);
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
     * @throws \Exception
     */
    public function sigintHandler()
    {
        /** @var LoggerInterface $logger */
        $logger = $this->getContainer()->get(LoggerInterface::class);
        $logger->info('Caught "SIGINT" signal: ESB shutting down now!');
        $this->shutdown();
    }

    public function shutdown()
    {
        Loop::stop(); // TODO it should be a more gracefully shutdown...
    }

    /**
     * @param YamlFileLoader $loader
     * @throws \Exception
     */
    private function loadSystemConfiguration(YamlFileLoader $loader)
    {
        if (!$this->environment) {
            $loader->load('services.yml');
            return;
        }

        try {
            $loader->load(sprintf('services_%s.yml', $this->environment));
        } catch (FileLocatorFileNotFoundException $exception) {
            $loader->load('services.yml');
        }
    }

    /**
     * @param YamlFileLoader $loader
     * @throws \Exception
     */
    private function loadLocalConfiguration(YamlFileLoader $loader)
    {
        if (!$this->environment) {
            $loader->load($this->localConfigFilePath);
            return;
        }

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
    }
}
