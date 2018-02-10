<?php

namespace Webgriffe\Esb;

use PHPUnit\Framework\TestCase;
use Pheanstalk\Pheanstalk;
use Symfony\Component\Process\Process;

class BeanstalkTestCase extends TestCase
{
    /**
     * @var Process
     */
    protected static $process;

    /**
     * @var Pheanstalk
     */
    protected $pheanstalk;

    /**
     * @throws \Symfony\Component\Process\Exception\LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function setUp()
    {
        if (!self::$process) {
            self::$process = new Process(self::getBeanstalkdCommand());
        }
        if (self::$process->isRunning()) {
            self::$process->stop();
            while (!self::$process->isTerminated()) {
                // wait
            }
        }
        self::$process->start();
        while (!self::$process->isStarted()) {
            // wait
        }
        $this->pheanstalk = $this->getPheanstalk();
    }

    public function tearDown()
    {
        if (self::$process->isRunning()) {
            self::$process->stop();
            while (!self::$process->isTerminated()) {
                // wait
            }
        }
    }

    /**
     * @return string
     */
    protected static function getBeanstalkdConnectionUri(): string
    {
        return getenv('BEANSTALKD_CONNECTION_URI') ?: 'tcp://127.0.0.1:11300';
    }

    /**
     * @return string
     */
    protected static function getBeanstalkdCommand(): string
    {
        return getenv('BEANSTALKD_COMMAND') ?: 'beanstalkd -V';
    }

    /**
     * @return Pheanstalk
     */
    protected function getPheanstalk(): Pheanstalk
    {
        $uri = self::getBeanstalkdConnectionUri();
        $parsedUri = parse_url($uri);
        return new Pheanstalk($parsedUri['host'], (int)$parsedUri['port']);
    }

    /**
     * @param $expectedReadyJobsCount
     * @param $tube
     */
    protected function assertReadyJobsCountInTube($expectedReadyJobsCount, $tube)
    {
        $stats = $this->pheanstalk->statsTube($tube);
        $this->assertEquals($expectedReadyJobsCount, $stats['current-jobs-ready']);
    }
}
