<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Loop;
use Amp\Promise;
use Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\CrontabProducerInterface;
use Webgriffe\Esb\DateTimeBuilderInterface;
use Webgriffe\Esb\ProducerInstance;
use function Amp\call;

/**
 * @internal
 */
class CronProducersServer
{
    const CRON_TICK_SECONDS = 60;

    /**
     * @var ProducerInstance[]
     */
    private $producerInstances = [];
    /**
     * @var DateTimeBuilderInterface
     */
    private $dateTimeBuilder;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var string
     */
    private $cronTickWatcherId;

    public function __construct(DateTimeBuilderInterface $dateTimeBuilder, LoggerInterface $logger)
    {
        $this->dateTimeBuilder = $dateTimeBuilder;
        $this->logger = $logger;
    }

    public function addProducerInstance(ProducerInstance $producerInstance)
    {
        $this->producerInstances[] = $producerInstance;
    }

    public function start(): Promise
    {
        return call(function () {
            if ($this->cronTickWatcherId) {
                throw new \RuntimeException('Cannot start an already started cron producers server.');
            }

            yield from $this->cronTick();
            $this->cronTickWatcherId = Loop::repeat(self::CRON_TICK_SECONDS * 1000, function () {
                yield from $this->cronTick();
            });
        });
    }

    public function isStarted(): bool
    {
        return $this->cronTickWatcherId !== null;
    }

    private function cronTick(): \Generator
    {
        foreach ($this->producerInstances as $producerInstance) {
            $producer = $producerInstance->getProducer();
            if (!$producer instanceof CrontabProducerInterface) {
                // This should never happen but maybe we should add a warning here?
                continue;
            }
            $cronExpression = CronExpression::factory($producer->getCrontab());
            /** @var DateTimeBuilderInterface $dateTimeBuilder */
            $now = $this->dateTimeBuilder->build();
            if ($cronExpression->isDue($now)) {
                $this->logger->info(
                    'Matched cron expression for a crontab producer.',
                    [
                        'producer' => \get_class($producer),
                        'now_date' => $now->format('c'),
                        'cron_expression' => $producer->getCrontab()
                    ]
                );
                yield $producerInstance->produceAndQueueJobs();
            }
        }
    }
}
