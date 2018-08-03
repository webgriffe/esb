<?php

namespace Webgriffe\Esb;

use PHPUnit\Framework\TestCase;
use Pheanstalk\Pheanstalk;

class BeanstalkTestCase extends TestCase
{
    /**
     * @var Pheanstalk
     */
    protected $pheanstalk;

    public function setUp()
    {
        parent::setUp();
        $this->pheanstalk = $this->getPheanstalk();
        $this->purgeBeanstalk();
    }

    /**
     * @return string
     */
    protected static function getBeanstalkdConnectionUri(): string
    {
        return getenv('BEANSTALKD_CONNECTION_URI') ?: 'tcp://127.0.0.1:11300';
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

    protected function purgeBeanstalk()
    {
        foreach ($this->pheanstalk->listTubes() as $tube) {
            try {
                while ($job = $this->pheanstalk->peekReady($tube)) {
                    $this->pheanstalk->delete($job);
                }
            } catch (\Throwable $e) {
                // Continue
            }
            try {
                while ($job = $this->pheanstalk->peekDelayed($tube)) {
                    $this->pheanstalk->delete($job);
                }
            } catch (\Throwable $e) {
                // Continue
            }
            try {
                while ($job = $this->pheanstalk->peekBuried($tube)) {
                    $this->pheanstalk->delete($job);
                }
            } catch (\Throwable $e) {
                // Continue
            }
        }
    }

    /**
     * @param int $expectedReadyJobsCount
     * @param string $tube
     */
    protected function assertReadyJobsCountInTube(int $expectedReadyJobsCount, string $tube)
    {
        $stats = $this->pheanstalk->statsTube($tube);
        $this->assertEquals($expectedReadyJobsCount, $stats['current-jobs-ready']);
    }
}
