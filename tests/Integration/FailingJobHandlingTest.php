<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Integration;

use Amp\Loop;
use Webgriffe\Esb\AlwaysFailingWorker;
use Webgriffe\Esb\DummyRepeatProducer;
use Webgriffe\Esb\KernelTestCase;
use Webgriffe\Esb\Model\Job;

class FailingJobHandlingTest extends KernelTestCase
{
    const TUBE = 'failing_jobs_flow';

    public function testFailingJobIsReleasedWithProperDelayAndThenBuriedAftetProperMaxRetries()
    {
        self::createKernel([
            'services' => [
                DummyRepeatProducer::class => ['arguments' => []],
                AlwaysFailingWorker::class => ['arguments' => []],
            ],
            'flows' => [
                self::TUBE => [
                    'description' => 'Repeat Flow',
                    'producer' => ['service' => DummyRepeatProducer::class],
                    'worker' => [
                        'service' => AlwaysFailingWorker::class,
                        'release_delay' => 1,
                        'max_retry' => 2
                    ],
                ]
            ]
        ]);

        DummyRepeatProducer::$jobs = [new Job(['test'])];
        Loop::delay(2010, function () {
            Loop::stop();
        });
        self::$kernel->boot();

        $this->assertTrue(
            $this->logHandler()->hasCriticalThatPasses(
                function (array $record) {
                    return $record['message'] === 'A Job reached maximum work retry limit and has been buried' &&
                        $record['context']['max_retry'] === 2;
                }
            )
        );
        $this->assertTrue(
            $this->logHandler()->hasInfoThatPasses(
                function (array $record) {
                    return $record['message'] === 'Worker released a Job' && $record['context']['release_delay'] === 1;
                }
            )
        );
    }
}
