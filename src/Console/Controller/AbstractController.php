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
     * @var BeanstalkClient
     */
    private $beanstalkClient;
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
        BeanstalkClient $beanstalkClient,
        FlowManager $flowManager,
        Client $elasticsearchClient
    ) {
        $this->twig = $twig;
        $this->beanstalkClient = $beanstalkClient;
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
     * @return BeanstalkClient
     */
    public function getBeanstalkClient(): BeanstalkClient
    {
        return $this->beanstalkClient;
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
