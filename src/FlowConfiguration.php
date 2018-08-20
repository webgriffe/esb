<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class FlowConfiguration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder.
     *
     * @return TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('flows');
        $rootNode
            ->arrayPrototype()
                ->children()
                    ->scalarNode('name')->end()
                    ->scalarNode('tube')->end()
                    ->scalarNode('producer')->end()
                    ->scalarNode('worker')->end()
                    ->scalarNode('workerInstances')->end()
                ->end()
            ->end()
        ;
        return $treeBuilder;
    }
}
