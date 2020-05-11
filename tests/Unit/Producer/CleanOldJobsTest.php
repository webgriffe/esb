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
    /**
     * @var CleanOldJobs
     */
    private $producer;

    protected function setUp()
    {
        $this->producer = new CleanOldJobs(self::CRONTAB_EXPRESSION);
    }

    /**
     * @test
     */
    public function it_is_a_crontab_producer()
    {
        $this->assertInstanceOf(CrontabProducerInterface::class, $this->producer);
    }

    /**
     * @test
     */
    public function it_has_a_crontab_expression()
    {
        $this->assertEquals(self::CRONTAB_EXPRESSION, $this->producer->getCrontab());
    }
    /**
     * @test
     */
    public function it_produces_just_one_job_with_empty_payload()
    {
        $jobs = wait($this->runIterator($this->producer->produce()));
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertEmpty($job->getPayloadData());
    }

    /**
     * @param Iterator $iterator
     * @return Promise
     */
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
