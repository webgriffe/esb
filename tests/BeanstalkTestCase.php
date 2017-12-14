<?php

namespace Webgriffe\Esb;

use Pheanstalk\Pheanstalk;
use PHPUnit\Framework\TestCase;

class BeanstalkTestCase extends TestCase
{
    /**
     * @var Pheanstalk
     */
    protected $pheanstalk;

    public function setUp()
    {
        $this->pheanstalk = $this->getPheanstalk();
        $this->purgeBeanstalk();
    }

    /**
     * @return string
     */
    protected function getBeanstalkdConnectionUri(): string
    {
        return getenv('BEANSTALKD_CONNECTION_URI') ?: 'tcp://127.0.0.1:11300';
    }

    /**
     * @return Pheanstalk
     */
    protected function getPheanstalk(): Pheanstalk
    {
        $uri = $this->getBeanstalkdConnectionUri();
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
            } catch (\Exception $e) {
                // Continue
            }
            try {
                while ($job = $this->pheanstalk->peekDelayed($tube)) {
                    $this->pheanstalk->delete($job);
                }
            } catch (\Exception $e) {
                // Continue
            }
            try {
                while ($job = $this->pheanstalk->peekBuried($tube)) {
                    $this->pheanstalk->delete($job);
                }
            } catch (\Exception $e) {
                // Continue
            }
        }
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
