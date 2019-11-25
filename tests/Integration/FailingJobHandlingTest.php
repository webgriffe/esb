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
    private const FLOW_CODE = 'failing_jobs_flow';

    public function testFailingJobIsReleasedWithProperDelayAndThenBuriedAftetProperMaxRetries()
    {
        self::createKernel([
            'services' => [
                DummyRepeatProducer::class => ['arguments' => []],
                AlwaysFailingWorker::class => ['arguments' => []],
            ],
            'flows' => [
                self::FLOW_CODE => [
                    'description' => 'Failing Jobs Handling Test Repeat Flow',
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
        $this->stopWhen(function () {
            return $this->logHandler()->hasErrorThatPasses(
                function (array $record) {
                    return $record['message'] === 'A Job reached maximum work retry limit and has been buried' &&
                        $record['context']['max_retry'] === 2;
                }
            );
        });
        self::$kernel->boot();

        $this->assertTrue(
            $this->logHandler()->hasInfoThatPasses(
                function (array $record) {
                    return $record['message'] === 'Worker released a Job' && $record['context']['release_delay'] === 1;
                }
            )
        );
    }
}
