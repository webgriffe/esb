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
use Webgriffe\Esb\Service\BatchManager;
use Webgriffe\Esb\Service\BatchManagerFactory;
use Webgriffe\Esb\Service\BeanstalkElasticsearchQueueBackend;

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
        //These classes are defined manually. Remove the default definitions otherwise the container generates errors
        //trying to autowire them
        $container->removeDefinition(BeanstalkElasticsearchQueueBackend::class);
        $container->removeDefinition(FlowConfig::class);
        $container->removeDefinition(ProducerInstance::class);
        $container->removeDefinition(WorkerInstance::class);
        $container->removeDefinition(BatchManager::class);
        $container->removeDefinition(BatchManagerFactory::class);

        $flowManagerDefinition = $container->findDefinition(FlowManager::class);
        foreach ($this->flowsConfig as $flowName => $flowConfigData) {
            $flowConfig = new FlowConfig($flowName, $flowConfigData);

            $flowDefinition = new Definition(Flow::class);
            $flowDefinition->setAutowired(true);
            $flowDefinition->setArgument('$flowConfig', $flowConfig);
            $queueBackendId = 'flow.queue_backend.' . $flowName;
            $batchManagerFactoryId = 'flow.batch_manager_factory.' . $flowName;
            try {
                $producerDefinition = $container->findDefinition($flowConfig->getProducerServiceId());
                $producerDefinition->setShared(false);

                $queueBackendDefinition = new Definition();
                $queueBackendDefinition
                    ->setShared(false)
                    ->setAutowired(true)
                    ->setClass(BeanstalkElasticsearchQueueBackend::class)
                    ->setArgument('$flowConfig', $flowConfig)
                ;
                $container->setDefinition($queueBackendId, $queueBackendDefinition);

                $batchManagerFactoryDefinition = new Definition();
                $batchManagerFactoryDefinition
                    ->setShared(false)
                    ->setAutowired(true)
                    ->setClass(BatchManagerFactory::class)
                    ->setArgument('$batchSize', $flowConfig->getProducerBatchSize())
                ;
                $container->setDefinition($batchManagerFactoryId, $batchManagerFactoryDefinition);

                $producerInstanceDefinition = new Definition();
                $producerInstanceDefinition
                    ->setAutowired(true)
                    ->setClass(ProducerInstance::class)
                    ->setArgument('$flowConfig', $flowConfig)
                    ->setArgument('$producer', new Reference($flowConfig->getProducerServiceId()))
                    ->setArgument('$queueBackend', new Reference($queueBackendId))
                    ->setArgument('$batchManagerFactory', new Reference($batchManagerFactoryId))
                ;
                $producerInstanceId = 'flow.producer_instance' . $flowName;
                $container->setDefinition($producerInstanceId, $producerInstanceDefinition);

                $flowDefinition->setArgument('$producerInstance', new Reference($producerInstanceId));
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
                        ->setArgument('$queueBackend', new Reference($queueBackendId))
                    ;
                    $workerInstanceId = sprintf('flow.worker_instance.%s.%s', $flowName, $instanceId);
                    $container->setDefinition($workerInstanceId, $workerInstanceDefinition);
                    $workerInstancesDefinitions[] = new Reference($workerInstanceId);
                }
                $flowDefinition->setArgument('$workerInstances', $workerInstancesDefinitions);
            } catch (ServiceNotFoundException $e) {
                throw new InvalidConfigurationException(
                    sprintf(
                        'Invalid worker for flow "%s", there is no service defined with ID "%s".',
                        $flowConfig->getDescription(),
                        $flowConfig->getWorkerServiceId()
                    )
                );
            }
            $flowId = 'flow.' . $flowName;
            $container->setDefinition($flowId, $flowDefinition);
            $flowManagerDefinition->addMethodCall('addFlow', [new Reference($flowId)]);
        }
    }
}
