<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Loop;
use Psr\Log\LoggerInterface;

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

    public function bootFlows()
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
     * @param \Webgriffe\Esb\Flow $flow
     */
    public function addFlow(Flow $flow)
    {
        $this->flows[] = $flow;
    }
}
