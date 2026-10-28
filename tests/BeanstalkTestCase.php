<?php

namespace Webgriffe\Esb;

use Amp\PHPUnit\AsyncTestCase;
use Pheanstalk\Pheanstalk;

class BeanstalkTestCase extends AsyncTestCase
{
    protected ?Pheanstalk $pheanstalk = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pheanstalk = $this->getPheanstalk();
        $this->purgeBeanstalk();
    }

    protected static function getBeanstalkdConnectionUri(): string
    {
        return getenv('ESB_BEANSTALKD_URL') ?: 'tcp://127.0.0.1:11300';
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

    protected function purgeBeanstalk(): void
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

    protected function assertReadyJobsCountInTube(int $expectedReadyJobsCount, string $tube): void
    {
        $stats = $this->pheanstalk->statsTube($tube);
        $this->assertEquals($expectedReadyJobsCount, $stats['current-jobs-ready']);
    }

    protected function assertBuriedJobsCountInTube(int $expectedBuriedJobsCount, string $tube): void
    {
        $stats = $this->pheanstalk->statsTube($tube);
        $this->assertEquals($expectedBuriedJobsCount, $stats['current-jobs-buried']);
    }

    protected function assertUrgentJobsCountInTube(int $expectedUrgentJobsCount, string $tube): void
    {
        $stats = $this->pheanstalk->statsTube($tube);
        $this->assertEquals($expectedUrgentJobsCount, $stats['current-jobs-urgent']);
    }

    protected function assertReservedJobsCountInTube(int $expectedReservedJobsCount, string $tube): void
    {
        $stats = $this->pheanstalk->statsTube($tube);
        $this->assertEquals($expectedReservedJobsCount, $stats['current-jobs-reserved']);
    }

    protected function assertDelayedJobsCountInTube(int $expectedDelayedJobsCount, string $tube): void
    {
        $stats = $this->pheanstalk->statsTube($tube);
        $this->assertEquals($expectedDelayedJobsCount, $stats['current-jobs-delayed']);
    }

    protected function assertDeletedJobsCountInTube(int $expectedDeletedJobsCount, string $tube): void
    {
        $stats = $this->pheanstalk->statsTube($tube);
        $deletedJobs = $stats['total-jobs'] -
            $stats['current-jobs-buried'] +
            $stats['current-jobs-urgent'] +
            $stats['current-jobs-reserved'] +
            $stats['current-jobs-delayed'];

        $this->assertEquals($expectedDeletedJobsCount, $deletedJobs);
    }
}
