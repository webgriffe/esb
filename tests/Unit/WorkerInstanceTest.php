<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\Success;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Webgriffe\Esb\FakeQueueBackend;
use Webgriffe\Esb\Model\ErroredJobEvent;
use Webgriffe\Esb\Model\FlowConfig;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\JobInterface;
use Webgriffe\Esb\WorkerInstance;
use Webgriffe\Esb\WorkerInterface;
use Webmozart\Assert\Assert;

class WorkerInstanceTest extends AsyncTestCase
{
    private ?TestHandler $logHandler = null;

    public function testAWorkedJobIsNotRequeuedWhenItCannotBeDequeued(): \Generator
    {
        $firstJob = new Job(['name' => 'first']);
        $secondJob = new Job(['name' => 'second']);
        $queueBackend = new FakeQueueBackend([$firstJob, $secondJob]);
        $queueBackend->failDequeue = true;
        $worker = $this->createWorker();

        Promise\rethrow($this->createWorkerInstance($worker, $queueBackend)->boot());
        yield $queueBackend->drained();

        $workedJobs = $this->getWorkedJobs($worker);
        $this->assertSame([$firstJob, $secondJob], $workedJobs);
        $this->assertSame([], $queueBackend->requeuedJobs);
        foreach ($workedJobs as $job) {
            foreach ($job->getEvents() as $event) {
                $this->assertNotInstanceOf(ErroredJobEvent::class, $event);
            }
        }
        $this->assertTrue($this->logHandler->hasCriticalThatContains('Could not remove a successfully worked Job'));
    }

    public function testTheWorkerKeepsWorkingWhenJobsCannotBeUpdated(): \Generator
    {
        $firstJob = new Job(['name' => 'first']);
        $secondJob = new Job(['name' => 'second']);
        $queueBackend = new FakeQueueBackend([$firstJob, $secondJob]);
        $queueBackend->failUpdates = true;
        $worker = $this->createWorker();

        Promise\rethrow($this->createWorkerInstance($worker, $queueBackend)->boot());
        yield $queueBackend->drained();

        $this->assertSame([$firstJob, $secondJob], $this->getWorkedJobs($worker));
        $this->assertSame([$firstJob, $secondJob], $queueBackend->dequeuedJobs);
        $this->assertTrue($this->logHandler->hasErrorThatContains('Could not update a Job'));
    }

    private function createWorkerInstance(WorkerInterface $worker, FakeQueueBackend $queueBackend): WorkerInstance
    {
        $this->logHandler = new TestHandler();
        $flowConfig = new FlowConfig(
            'worker_instance_test',
            [
                'description' => 'Worker instance test flow',
                'worker' => ['error_retry_delay' => 0, 'max_retry' => 5],
                'dependencies' => ['flows' => []],
            ]
        );

        return new WorkerInstance(
            $flowConfig,
            1,
            $worker,
            new Logger('test', [$this->logHandler]),
            $queueBackend
        );
    }

    private function createWorker(): WorkerInterface
    {
        return new class() implements WorkerInterface {
            /** @var JobInterface[] */
            public array $workedJobs = [];

            public function init(): Promise
            {
                return new Success();
            }

            public function work(JobInterface $job): Promise
            {
                $this->workedJobs[] = $job;

                return new Success();
            }
        };
    }

    /**
     * @return JobInterface[]
     */
    private function getWorkedJobs(WorkerInterface $worker): array
    {
        Assert::propertyExists($worker, 'workedJobs');
        $workedJobs = $worker->workedJobs;  // @phpstan-ignore-line
        Assert::isArray($workedJobs);
        Assert::allIsInstanceOf($workedJobs, JobInterface::class);

        return $workedJobs;
    }
}
