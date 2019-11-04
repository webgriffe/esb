<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Unit\Model;

use PHPUnit\Framework\TestCase;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\JobEventInterface;

class JobTest extends TestCase
{
    /**
     * @test
     */
    public function it_allows_to_add_events()
    {
        $job = new Job([]);
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:40:00')));
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:41:00')));
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:42:00')));

        $this->assertCount(3, $job->getEvents());
    }

    /**
     * @test
     */
    public function it_returns_last_event()
    {
        $job = new Job([]);
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:40:00')));
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:41:00')));
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:42:00')));

        $this->assertInstanceOf(JobEventInterface::class, $job->getLastEvent());
        $this->assertEquals(new \DateTime('2019-10-29 19:42:00'), $job->getLastEvent()->getTime());
    }

    /**
     * @test
     */
    public function it_returns_no_last_event_if_there_is_no_event_at_all()
    {
        $job = new Job([]);

        $this->assertNull($job->getLastEvent());
    }

    /**
     * @test
     */
    public function it_should_not_allow_to_add_event_happened_before_the_last_one()
    {
        $job = new Job([]);
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:40:00')));
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:41:00')));

        $this->expectExceptionObject(
            new \InvalidArgumentException(
                'Cannot add event happened before the last one. Last event happened at "2019-10-29T19:41:00+00:00", ' .
                'an event happened at "2019-10-29T19:39:00+00:00" has been given.'
            )
        );
        $job->addEvent(new DummyJobEvent(new \DateTime('2019-10-29 19:39:00')));
    }
}
