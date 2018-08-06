<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Psr\Http\Message\ServerRequestInterface;

trait ControllerTrait
{
    /**
     * @var ServerRequestInterface
     */
    private $request;
    /**
     * @var \Twig_Environment
     */
    private $twig;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;

    public function __construct(
        ServerRequestInterface $request,
        \Twig_Environment $twig,
        BeanstalkClient $beanstalkClient
    ) {
        $this->request = $request;
        $this->twig = $twig;
        $this->beanstalkClient = $beanstalkClient;
    }
}
