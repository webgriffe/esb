<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Http\Server\Request;

trait ControllerTrait
{
    /**
     * @var Request
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
        Request $request,
        \Twig_Environment $twig,
        BeanstalkClient $beanstalkClient
    ) {
        $this->request = $request;
        $this->twig = $twig;
        $this->beanstalkClient = $beanstalkClient;
    }
}
