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
        $definition = $container->findDefinition(FlowManager::class);
        foreach ($this->flowsConfig as $flowTube => $flowConfigData) {
            $flowConfig = new FlowConfig($flowTube, $flowConfigData);
            $flowDefinition = new Definition(Flow::class);
            $flowDefinition->setAutowired(true);
            $flowDefinition->setArgument('$flowConfig', $flowConfig);
            try {
                $producerDefinition = $container->findDefinition($flowConfig->getProducerServiceId());
                $producerDefinition->setShared(false);

                $producerInstanceDefinition = new Definition();
                $producerInstanceDefinition
                    ->setAutowired(true)
                    ->setClass(ProducerInstance::class)
                    ->setArgument('$flowConfig', $flowConfig)
                    ->setArgument('$producer', new Reference($flowConfig->getProducerServiceId()))
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
                    $workerInstanceDefinition = new Definition();
                    $workerInstanceDefinition
                        ->setAutowired(true)
                        ->setClass(WorkerInstance::class)
                        ->setArgument('$flowConfig', $flowConfig)
                        ->setArgument('$instanceId', $instanceId)
                        ->setArgument('$worker', new Reference($flowConfig->getWorkerServiceId()))
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
