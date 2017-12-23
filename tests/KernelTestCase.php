<?php

namespace Webgriffe\Esb;

use Amp\PHPUnit\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Yaml\Yaml;

class KernelTestCase extends TestCase
{
    /**
     * @var Kernel
     */
    protected static $kernel;

    /**
     * @param $additionalConfig
     */
    protected static function createKernel(array $additionalConfig)
    {
        $basicConfig = [
            'parameters' => ['beanstalkd' => 'tcp://127.0.0.1:11300'],
            'services' => [
                '_defaults' => [
                    'autowire' => true,
                    'autoconfigure' => true,
                    'public' => true,
                ],
                TestHandler::class => ['class' => TestHandler::class],
                Logger::class => ['class' => Logger::class, 'arguments' => ['esb', ['@' . TestHandler::class]]],
            ]
        ];
        $config = array_replace_recursive($basicConfig, $additionalConfig);
        vfsStream::setup('root', null, ['config.yml' => Yaml::dump($config)]);
        self::$kernel = new Kernel(vfsStream::url('root/config.yml'));
    }

    /**
     * @return object
     * @throws \Exception
     */
    protected function logHandler()
    {
        return self::$kernel->getContainer()->get(TestHandler::class);
    }
}
