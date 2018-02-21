<?php

namespace Webgriffe\Esb;

use Amp\PHPUnit\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use org\bovigo\vfs\vfsStream;
use Pheanstalk\Pheanstalk;
use Symfony\Component\Yaml\Yaml;

class KernelTestCase extends BeanstalkTestCase
{
    /**
     * @var Kernel
     */
    protected static $kernel;

    protected function tearDown()
    {
        parent::tearDown();
        self::$kernel = null;
        gc_collect_cycles();
    }

    /**
     * @param $additionalConfig
     */
    protected static function createKernel(array $additionalConfig)
    {
        $basicConfig = [
            'parameters' => [
                'beanstalkd' => self::getBeanstalkdConnectionUri(),
                'http_server_port' => self::getHttpServerPort(),
                'critical_events_to' => 'toemail@address.com',
                'critical_events_from' => 'From Name <fromemail@address.com>',
            ],
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

    protected static function getHttpServerPort()
    {
        return getenv('HTTP_SERVER_PORT') ?: 34981;
    }

    /**
     * @return TestHandler
     * @throws \Exception
     */
    protected function logHandler()
    {
        return self::$kernel->getContainer()->get(TestHandler::class);
    }

    protected function dumpLog()
    {
        $records = $this->logHandler()->getRecords();
        echo implode('', array_map(function ($entry) {return $entry['formatted'];}, $records));
    }
}
