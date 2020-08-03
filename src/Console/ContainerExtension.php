<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class ContainerExtension implements ExtensionInterface, PrependExtensionInterface, CompilerPassInterface
{
    private const ALIAS = 'console';

    /**
     * @inheritDoc
     * @throws \Exception
     */
    public function prepend(ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::getConfigDir()));
        $loader->load('services.yml');
        $loader->load('controllers.yml');
    }

    /**
     * @param array<int, array> $configs
     * @param ContainerBuilder $container
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    /**
     * @return false|string
     */
    public function getNamespace()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getXsdValidationBasePath()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getAlias()
    {
        return self::ALIAS;
    }

    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container): void
    {
        $container->setParameter('console.root_dir', self::getRootDir());
        $container->setParameter('console.public_dir', self::getPublicDir());
        $container->setParameter('console.views_dir', self::getViewsDir());
    }

    private static function getRootDir(): string
    {
        return __DIR__;
    }

    private static function getConfigDir(): string
    {
        $slash = DIRECTORY_SEPARATOR;
        return rtrim(self::getRootDir(), $slash) . $slash . 'Resources' . $slash . 'config';
    }

    private static function getPublicDir(): string
    {
        $slash = DIRECTORY_SEPARATOR;
        return rtrim(self::getRootDir(), $slash) . $slash . 'Resources' . $slash . 'public';
    }

    private static function getViewsDir(): string
    {
        $slash = DIRECTORY_SEPARATOR;
        return rtrim(self::getRootDir(), $slash) . $slash . 'Resources' . $slash . 'views';
    }
}
