<?php

namespace Webgriffe\Esb\Service;

use Symfony\Component\Process\PhpProcess;
use Symfony\Component\Process\Process;
use Webgriffe\Esb\WorkerInterface;

class WorkerManager
{
    /**
     * @var string
     */
    private $autoloaderPath;

    /**
     * @var WorkerInterface[]
     */
    private $workers = [];

    /**
     * WorkerManager constructor.
     * @param string $autoloaderPath
     */
    public function __construct($autoloaderPath)
    {
        $this->autoloaderPath = $autoloaderPath;
    }

    public function startAllWorkers()
    {
        if (!count($this->workers)) {
            printf('No workers to start.' . PHP_EOL);
            return;
        }

        printf('Starting "%s" workers...' . PHP_EOL, count($this->workers));
        $processes = [];
        foreach ($this->workers as $worker) {
            $processes[$worker->getCode()] = $this->startWorkerProcess($worker);
        }

        while (true) {
            foreach ($processes as $process) {
                $this->keepWorkerProcessAlive($process);
            }
        }
    }

    public function addWorker(WorkerInterface $worker)
    {
        $this->workers[$worker->getCode()] = $worker;
    }

    public function getWorkerByCode($code): WorkerInterface
    {
        return $this->workers[$code];
    }

    private function getChildWorkerProcessCode(): string
    {
        return <<<'PHP'
<?php

use Webgriffe\Esb\Kernel;
use Webgriffe\Esb\WorkerInterface;
use Webgriffe\Esb\Service\WorkerManager;

require_once getenv('AUTOLOADER');

$workerCode = getenv('WORKER_CODE');

$kernel = new Kernel();
/** @var WorkerManager $workerManager */
$workerManager = $kernel->getContainer()->get(WorkerManager::class);

$worker = $workerManager->getWorkerByCode($workerCode);
$worker->work();
PHP;
    }

    /**
     * @param WorkerInterface $worker
     * @return PhpProcess
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     * @throws \Symfony\Component\Process\Exception\LogicException
     */
    private function startWorkerProcess(WorkerInterface $worker): PhpProcess
    {
        $process = new PhpProcess(
            $this->getChildWorkerProcessCode(),
            null,
            ['WORKER_CODE' => $worker->getCode(), 'AUTOLOADER' => $this->autoloaderPath],
            null
        );
        $process->start();
        return $process;
    }

    private function keepWorkerProcessAlive(Process $process)
    {
        // Here we keep the worker process alive forever without any check. Maybe there should be an improved logic
        // which logs when a specific worker keeps crashing.
        // TODO Improve keep alive worker process checks
        if ($process->isRunning()) {
            return;
        }
        $process->restart();
    }
}
