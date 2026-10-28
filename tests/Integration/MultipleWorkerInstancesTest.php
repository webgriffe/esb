<?php

namespace Webgriffe\Esb\Integration;

use org\bovigo\vfs\vfsStream;
use Webgriffe\Esb\DummyFilesystemRepeatProducer;
use Webgriffe\Esb\DummyFilesystemWorker;
use Webgriffe\Esb\KernelTestCase;

class MultipleWorkerInstancesTest extends KernelTestCase
{
    private const FLOW_CODE = 'multiple_worker_instances_flow';

    public function testAllWorkerInstancesAreBooted(): void
    {
        $producerDir = vfsStream::url('root/producer_dir');
        self::createKernel([
            'services' => [
                DummyFilesystemRepeatProducer::class => ['arguments' => [$producerDir]],
                DummyFilesystemWorker::class => ['arguments' => [vfsStream::url('root/worker.data')]],
            ],
            'flows' => [
                self::FLOW_CODE => [
                    'description' => 'Multiple Worker Instances Test Flow',
                    'producer' => ['service' => DummyFilesystemRepeatProducer::class],
                    'worker' => ['service' => DummyFilesystemWorker::class, 'instances' => 3],
                ]
            ]
        ]);
        mkdir($producerDir);
        $this->stopWhen(function () {
            return count($this->getBootedWorkerInstanceIds()) === 3;
        });

        self::$kernel->boot();

        $this->assertEqualsCanonicalizing([1, 2, 3], $this->getBootedWorkerInstanceIds());
    }

    /**
     * @return int[]
     */
    private function getBootedWorkerInstanceIds(): array
    {
        $instanceIds = [];
        foreach ($this->logHandler()->getRecords() as $record) {
            if ($record['message'] === 'A Worker instance has been successfully initialized') {
                $instanceIds[] = $record['context']['instance_id'];
            }
        }

        return $instanceIds;
    }
}
