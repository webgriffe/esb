<?php

namespace Webgriffe\Esb;

use Symfony\Component\Process\PhpProcess;
use Symfony\Component\Process\Process;

class WorkerManager
{
    /**
     * @var WorkerInterface[]
     */
    private $workers = [];

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

    public function getWorkerByCode($code)
    {
        return $this->workers[$code];
    }

    private function getChildWorkerProcessCode()
    {
        return <<<'PHP'
<?php

use Webgriffe\Esb\Kernel;
use Webgriffe\Esb\WorkerInterface;
use Webgriffe\Esb\WorkerManager;

require_once getenv('AUTOLOADER');

$workerCode = getenv('WORKER_CODE');

$kernel = new Kernel();
/** @var WorkerManager $workerManager */
$workerManager = $kernel->getContainer()->get('worker_manager');

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
    private function startWorkerProcess(WorkerInterface $worker)
    {
        $process = new PhpProcess(
            $this->getChildWorkerProcessCode(),
            null,
            ['WORKER_CODE' => $worker->getCode(), 'AUTOLOADER' => __DIR__ . '/../vendor/autoload.php'],
            null
        );
        $process->start();
        return $process;
    }

    private function keepWorkerProcessAlive(Process $process)
    {
        if ($process->isRunning()) {
            return;
        }
        echo 'Worker crashed... Restart!';
        $process->restart();
    }
}
