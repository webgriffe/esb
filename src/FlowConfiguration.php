<?php
/** @noinspection NullPointerExceptionInspection */
declare(strict_types=1);

namespace Webgriffe\Esb;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class FlowConfiguration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder.
     *
     * @return TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('flows');
        $rootNode
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('description')->isRequired()->cannotBeEmpty()->end()
                    ->arrayNode('producer')
                        ->children()
                            ->scalarNode('service')->isRequired()->end()
                        ->end()
                    ->end()
                    ->arrayNode('worker')
                        ->children()
                            ->scalarNode('service')->isRequired()->end()
                            ->integerNode('instances')->min(1)->defaultValue(1)->end()
                            ->integerNode('release_delay')->min(0)->defaultValue(0)->end()
                            ->integerNode('max_retry')->min(1)->defaultValue(5)->end()
                        ->end()
                    ->end()
                    ->arrayNode('depends_on')->scalarPrototype()->end()
                ->end()
            ->end()
        ;
        return $treeBuilder;
    }
}
