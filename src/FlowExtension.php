<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Service\QueueManager;

final class FlowExtension implements ExtensionInterface, CompilerPassInterface
{
    /**
     * @var array<string, array>
     */
    private $flowsConfig = [];

    /**
     * Loads a specific configuration.
     *
     * @param array<int, array> $configs
     * @param ContainerBuilder $container
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new FlowConfiguration();
        $processor = new Processor();
        $this->flowsConfig = $processor->processConfiguration($configuration, $configs);
    }

    /**
     * @return string|false
     */
    public function getNamespace()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getXsdValidationBasePath()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getAlias(): string
    {
        return 'flows';
    }

    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container): void
    {
        //These clasees are defined manually. Remove the default definitions otherwise the container generates errors
        //trying to autowire them
        $container->removeDefinition(QueueManager::class);
        $container->removeDefinition(FlowConfig::class);
        $container->removeDefinition(ProducerInstance::class);
        $container->removeDefinition(WorkerInstance::class);

        $definition = $container->findDefinition(FlowManager::class);
        foreach ($this->flowsConfig as $flowTube => $flowConfigData) {
            $flowConfig = new FlowConfig($flowTube, $flowConfigData);

            $flowDefinition = new Definition(Flow::class);
            $flowDefinition->setAutowired(true);
            $flowDefinition->setArgument('$flowConfig', $flowConfig);
            try {
                $producerDefinition = $container->findDefinition($flowConfig->getProducerServiceId());
                $producerDefinition->setShared(false);

                $queueManagerDefinition = new Definition();
                $queueManagerDefinition
                    ->setShared(false)
                    ->setAutowired(true)
                    ->setClass(QueueManager::class)
                    ->setArgument('$flowConfig', $flowConfig)
                ;

                $producerInstanceDefinition = new Definition();
                $producerInstanceDefinition
                    ->setAutowired(true)
                    ->setClass(ProducerInstance::class)
                    ->setArgument('$producer', $producerDefinition)
                    ->setArgument('$flowConfig', $flowConfig)
                    ->setArgument('$queueManager', $queueManagerDefinition)
                ;

                $flowDefinition->setArgument('$producerInstance', $producerInstanceDefinition);
            } catch (ServiceNotFoundException $e) {
                throw new InvalidConfigurationException(
                    sprintf(
                        'Invalid producer for flow "%s", there is no service defined with ID "%s".',
                        $flowConfig->getDescription(),
                        $flowConfig->getProducerServiceId()
                    )
                );
            }
            try {
                $workerDefinition = $container->findDefinition($flowConfig->getWorkerServiceId());
                $workerDefinition->setShared(false);

                $workerInstancesDefinitions = [];
                for ($instanceId = 1; $instanceId <= $flowConfig->getWorkerInstancesCount(); $instanceId++) {
                    //A separate queueManager instance for each worker instance
                    $queueManagerDefinition = new Definition();
                    $queueManagerDefinition
                        ->setShared(false)
                        ->setAutowired(true)
                        ->setClass(QueueManager::class)
                        ->setArgument('$flowConfig', $flowConfig)
                    ;

                    $workerInstanceDefinition = new Definition();
                    $workerInstanceDefinition
                        ->setAutowired(true)
                        ->setClass(WorkerInstance::class)
                        ->setArgument('$flowConfig', $flowConfig)
                        ->setArgument('$instanceId', $instanceId)
                        ->setArgument('$worker', new Reference($flowConfig->getWorkerServiceId()))
                        ->setArgument('$queueManager', $queueManagerDefinition)
                    ;
                    $workerInstancesDefinitions[] = $workerInstanceDefinition;
                }
                $flowDefinition->setArgument('$workerInstances', $workerInstancesDefinitions);
            } catch (ServiceNotFoundException $e) {
                throw new InvalidConfigurationException(
                    sprintf(
                        'Invalid workder for flow "%s", there is no service defined with ID "%s".',
                        $flowConfig->getDescription(),
                        $flowConfig->getWorkerServiceId()
                    )
                );
            }
            $container->setDefinition($flowTube, $flowDefinition);
            $definition->addMethodCall('addFlow', [new Reference($flowTube)]);
        }
    }
}
