<?php

namespace Webgriffe\Esb;

use Amp\PHPUnit\AsyncTestCase;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\TubeName;
use Pheanstalk\Values\TubeStats;

class BeanstalkTestCase extends AsyncTestCase
{
    protected Pheanstalk $pheanstalk;

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

    protected function getPheanstalk(): Pheanstalk
    {
        $uri = self::getBeanstalkdConnectionUri();
        $parsedUri = parse_url($uri);

        return Pheanstalk::create($parsedUri['host'], (int)$parsedUri['port']); // @phpstan-ignore-line
    }

    protected function purgeBeanstalk(): void
    {
        foreach ($this->pheanstalk->listTubes() as $tube) {
            $this->pheanstalk->useTube($tube);
            while ($job = $this->pheanstalk->peekReady()) {
                $this->pheanstalk->delete($job);
            }
            while ($job = $this->pheanstalk->peekDelayed()) {
                $this->pheanstalk->delete($job);
            }
            while ($job = $this->pheanstalk->peekBuried()) {
                $this->pheanstalk->delete($job);
            }
        }
    }

    protected function assertReadyJobsCountInTube(int $expectedReadyJobsCount, string $tube): void
    {
        $this->assertEquals($expectedReadyJobsCount, $this->getCurrentJobsReady($this->getStats($tube)));
    }

    protected function assertBuriedJobsCountInTube(int $expectedBuriedJobsCount, string $tube): void
    {
        $this->assertEquals($expectedBuriedJobsCount, $this->getCurrentJobsBuried($this->getStats($tube)));
    }

    protected function assertUrgentJobsCountInTube(int $expectedUrgentJobsCount, string $tube): void
    {
        $this->assertEquals($expectedUrgentJobsCount, $this->getCurrentJobsUrgent($this->getStats($tube)));
    }

    protected function assertReservedJobsCountInTube(int $expectedReservedJobsCount, string $tube): void
    {
        $this->assertEquals($expectedReservedJobsCount, $this->getCurrentJobsReserved($this->getStats($tube)));
    }

    protected function assertDelayedJobsCountInTube(int $expectedDelayedJobsCount, string $tube): void
    {
        $this->assertEquals($expectedDelayedJobsCount, $this->getCurrentJobsDelayed($this->getStats($tube)));
    }

    protected function assertDeletedJobsCountInTube(int $expectedDeletedJobsCount, string $tube): void
    {
        $stats = $this->getStats($tube);
        $deletedJobs = $this->getTotalJobs($stats) -
            $this->getCurrentJobsBuried($stats) +
            $this->getCurrentJobsUrgent($stats) +
            $this->getCurrentJobsReserved($stats) +
            $this->getCurrentJobsDelayed($stats);

        $this->assertEquals($expectedDeletedJobsCount, $deletedJobs);
    }

    protected function getStats(string $tube): TubeStats
    {
        return $this->pheanstalk->statsTube(new TubeName($tube));
    }

    protected function getTotalJobs(TubeStats $stats): int
    {
        return $stats->totalJobs;
    }

    protected function getCurrentJobsReady(TubeStats $stats): int
    {
        return $stats->currentJobsReady;
    }

    protected function getCurrentJobsBuried(TubeStats $stats): int
    {
        return $stats->currentJobsBuried;
    }

    protected function getCurrentJobsUrgent(TubeStats $stats): int
    {
        return $stats->currentJobsUrgent;
    }

    protected function getCurrentJobsReserved(TubeStats $stats): int
    {
        return $stats->currentJobsReserved;
    }

    protected function getCurrentJobsDelayed(TubeStats $stats): int
    {
        return $stats->currentJobsDelayed;
    }
}
