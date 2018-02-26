<?php

namespace Webgriffe\Esb;

use Amp\File\BlockingDriver;
use Monolog\Handler\TestHandler;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Yaml\Yaml;
use function Amp\File\filesystem;

class KernelTestCase extends BeanstalkTestCase
{
    /**
     * @var Kernel
     */
    protected static $kernel;

    /**
     * @throws \Error
     */
    public function setUp()
    {
        parent::setUp();
        filesystem(new BlockingDriver());
    }

    protected function tearDown()
    {
        parent::tearDown();
        self::$kernel = null;
        gc_collect_cycles();
    }

    /**
     * @param $localConfig
     * @throws \Exception
     */
    protected static function createKernel(array $localConfig)
    {
        $config = array_merge_recursive(
            ['services' => ['_defaults' => ['autowire' => true, 'autoconfigure' => true, 'public' => true]]],
            $localConfig
        );
        vfsStream::setup('root', null, ['config.yml' => Yaml::dump($config)]);
        self::$kernel = new Kernel(vfsStream::url('root/config.yml'), 'test');
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
