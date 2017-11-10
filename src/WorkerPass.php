<?php

namespace Webgriffe\Esb;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class WorkerPass implements CompilerPassInterface
{
    /**
     * You can modify the container here before it is dumped to PHP code.
     *
     * @param ContainerBuilder $container
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->has('worker_manager')) {
            return;
        }

        $definition = $container->findDefinition('worker_manager');

        // find all service IDs with the app.mail_transport tag
        $taggedServices = $container->findTaggedServiceIds(Kernel::WORKER_TAG);

        foreach ($taggedServices as $id => $tags) {
            // add the transport service to the ChainTransport service
            $definition->addMethodCall('addWorker', array(new Reference($id)));
        }
    }
}
