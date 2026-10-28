<?php

namespace Webgriffe\Esb;

use Amp\PHPUnit\AsyncTestCase;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Response;
use Webmozart\Assert\Assert;

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

        return new Pheanstalk($parsedUri['host'], (int)$parsedUri['port']); // @phpstan-ignore-line
    }

    protected function purgeBeanstalk(): void
    {
        foreach ($this->pheanstalk->listTubes() as $tube) {
            try {
                while ($job = $this->pheanstalk->peekReady($tube)) {    // @phpstan-ignore while.alwaysTrue
                    $this->pheanstalk->delete($job);
                }
            } catch (\Throwable $e) {
                // Continue
            }
            try {
                while ($job = $this->pheanstalk->peekDelayed($tube)) {  // @phpstan-ignore while.alwaysTrue
                    $this->pheanstalk->delete($job);
                }
            } catch (\Throwable $e) {
                // Continue
            }
            try {
                while ($job = $this->pheanstalk->peekBuried($tube)) {   // @phpstan-ignore while.alwaysTrue
                    $this->pheanstalk->delete($job);
                }
            } catch (\Throwable $e) {
                // Continue
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

    protected function getStats(string $tube): Response
    {
        $response = $this->pheanstalk->statsTube($tube);
        Assert::isInstanceOf($response, Response::class);

        return $response;
    }

    protected function getTotalJobs(Response $stats): int
    {
        return $stats['total-jobs'];    // @phpstan-ignore-line
    }

    protected function getCurrentJobsReady(Response $stats): int
    {
        return $stats['current-jobs-ready'];    // @phpstan-ignore-line
    }

    protected function getCurrentJobsBuried(Response $stats): int
    {
        return $stats['current-jobs-buried'];    // @phpstan-ignore-line
    }

    protected function getCurrentJobsUrgent(Response $stats): int
    {
        return $stats['current-jobs-urgent'];    // @phpstan-ignore-line
    }

    protected function getCurrentJobsReserved(Response $stats): int
    {
        return $stats['current-jobs-reserved'];    // @phpstan-ignore-line
    }

    protected function getCurrentJobsDelayed(Response $stats): int
    {
        return $stats['current-jobs-delayed'];    // @phpstan-ignore-line
    }
}
