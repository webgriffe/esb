<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Twig\Environment;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\Esb\FlowManager;

abstract class AbstractController
{
    /**
     * @var Environment
     */
    private $twig;
    /**
     * @var FlowManager
     */
    private $flowManager;
    /**
     * @var Client
     */
    private $elasticsearchClient;

    public function __construct(
        Environment $twig,
        FlowManager $flowManager,
        Client $elasticsearchClient
    ) {
        $this->twig = $twig;
        $this->flowManager = $flowManager;
        $this->elasticsearchClient = $elasticsearchClient;
    }

    /**
     * @return Environment
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @return FlowManager
     */
    public function getFlowManager(): FlowManager
    {
        return $this->flowManager;
    }

    /**
     * @return Client
     */
    public function getElasticsearchClient(): Client
    {
        return $this->elasticsearchClient;
    }
}
