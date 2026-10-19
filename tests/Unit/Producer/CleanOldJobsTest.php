<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Unit\Producer;

use Amp\Iterator;
use Amp\Promise;
use PHPUnit\Framework\TestCase;
use Webgriffe\Esb\CrontabProducerInterface;
use Webgriffe\Esb\Producer\CleanOldJobs;
use function Amp\call;
use function Amp\Promise\wait;

class CleanOldJobsTest extends TestCase
{
    const CRONTAB_EXPRESSION = '0 15 10 ? * *';

    private ?CleanOldJobs $producer = null;

    protected function setUp(): void
    {
        $this->producer = new CleanOldJobs(self::CRONTAB_EXPRESSION);
    }

    /**
     * @test
     */
    public function it_is_a_crontab_producer(): void
    {
        $this->assertInstanceOf(CrontabProducerInterface::class, $this->producer);
    }

    /**
     * @test
     */
    public function it_has_a_crontab_expression(): void
    {
        $this->assertEquals(self::CRONTAB_EXPRESSION, $this->producer->getCrontab());
    }

    /**
     * @test
     */
    public function it_produces_just_one_job_with_empty_payload(): void
    {
        $jobs = wait($this->runIterator($this->producer->produce()));
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertEmpty($job->getPayloadData());
    }

    private function runIterator(Iterator $iterator): Promise
    {
        return call(function () use ($iterator) {
            $items = [];
            while (yield $iterator->advance()) {
                $items[] = $iterator->getCurrent();
            }
            return $items;
        });
    }
}
