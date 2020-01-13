<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Http\Server\Request;
use Twig\Environment;

abstract class AbstractController
{
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Environment
     */
    private $twig;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;

    public function __construct(Request $request, Environment $twig, BeanstalkClient $beanstalkClient)
    {
        $this->request = $request;
        $this->twig = $twig;
        $this->beanstalkClient = $beanstalkClient;
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
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
}
