<?php

namespace Webgriffe\Esb\Callback;

use Amp\Beanstalk\BeanstalkClient;
use Amp\CallableMaker;
use Amp\Loop;
use Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Webgriffe\Esb\JobsQueuer;
use Webgriffe\Esb\DateTimeBuilderInterface;
use Webgriffe\Esb\Service\BeanstalkClientFactory;
use function Amp\call;

class CrontabProducersRunner
{
    const CRON_TICK_SECONDS = 60;
    use CallableMaker;

    /**
     * @var array
     */
    private $producers;
    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;
    /**
     * @var DateTimeBuilderInterface
     */
    private $dateTimeBuilder;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var BeanstalkClient[]
     */
    private $beanstalkClients = [];

    public function __construct(
        array $producers,
        BeanstalkClientFactory $beanstalkClientFactory,
        DateTimeBuilderInterface $dateTimeBuilder,
        LoggerInterface $logger
    ) {

        $this->producers = $producers;
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->dateTimeBuilder = $dateTimeBuilder;
        $this->logger = $logger;
    }

    public function __invoke()
    {
        foreach ($this->producers as $producer) {
            $beanstalkClient = $this->beanstalkClientFactory->create();
            $this->beanstalkClients[\get_class($producer)] = $beanstalkClient;
            yield call(new ProducerInitializer($producer, $beanstalkClient, $this->logger));
        }
        Loop::defer($this->callableFromInstanceMethod('cronTick'));
        Loop::repeat(self::CRON_TICK_SECONDS * 1000, $this->callableFromInstanceMethod('cronTick'));
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function cronTick()
    {
        foreach ($this->producers as $producer) {
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
                $beanstalkClient = $this->beanstalkClients[\get_class($producer)];
                yield JobsQueuer::queueJobs($beanstalkClient, $this->logger, $producer);
            }
        }
    }
}
