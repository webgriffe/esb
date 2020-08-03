<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Loop;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
class FlowManager
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Flow[]
     */
    private $flows = [];

    /**
     * FlowManager constructor.
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function bootFlows(): void
    {
        Loop::defer(function () {
            if (!\count($this->flows)) {
                $this->logger->notice('No flow to start.');
                return;
            }

            foreach ($this->flows as $flow) {
                $flow->boot();
            }
        });
    }

    /**
     * @param Flow $flow
     */
    public function addFlow(Flow $flow): void
    {
        $this->flows[] = $flow;
    }

    /**
     * @return Flow[]
     */
    public function getFlows(): array
    {
        return $this->flows;
    }
}
