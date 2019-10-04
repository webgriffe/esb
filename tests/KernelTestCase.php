<?php

namespace Webgriffe\Esb;

use Amp\File\BlockingDriver;
use Amp\Loop;
use Monolog\Handler\TestHandler;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Yaml\Yaml;
use function Amp\call;
use function Amp\File\exists;
use function Amp\File\filesystem;

class KernelTestCase extends BeanstalkTestCase
{
    /**
     * @var Kernel|null
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
     * @param array $localConfig
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
        /** @noinspection OneTimeUseVariablesInspection */
        /** @var TestHandler $logHandler */
        $logHandler = self::$kernel->getContainer()->get(TestHandler::class);
        return $logHandler;
    }

    protected function dumpLog()
    {
        $records = $this->logHandler()->getRecords();
        return implode('', array_map(function ($entry) {
            return $entry['formatted'];
        }, $records));
    }

    protected function stopWhen(callable $stopCondition, int $timeoutInSeconds = 10)
    {
        $start = Loop::now();
        Loop::repeat(1, function () use ($start, $stopCondition, $timeoutInSeconds) {
            if (yield call($stopCondition)) {
                Loop::stop();
            }
            if ((Loop::now() - $start) >= $timeoutInSeconds * 1000) {
                throw new \RuntimeException(
                    sprintf('Stop condition not reached within %s seconds timeout!', $timeoutInSeconds)
                );
            }
        });
    }
}
