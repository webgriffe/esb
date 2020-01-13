<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ConsoleCompilerPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container)
    {
        $container->setParameter('console.root_dir', __DIR__);
    }
}
