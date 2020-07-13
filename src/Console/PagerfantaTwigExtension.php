<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Pagerfanta\Pagerfanta;
use Pagerfanta\View\ViewInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PagerfantaTwigExtension extends AbstractExtension
{
    /**
     * @var ViewInterface
     */
    private $view;

    public function __construct(ViewInterface $view)
    {
        $this->view = $view;
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('pagerfanta', [$this, 'renderPagerfanta'], ['is_safe' => ['html']])
        ];
    }

    public function renderPagerfanta(
        Pagerfanta $pagerfanta,
        string $route,
        array $options = []
    ): string {
        $routeGenerator = function ($page) use ($route): string {
            return strtr($route, ['{page}' => $page]);
        };

        return $this->view->render($pagerfanta, $routeGenerator, $options);
    }
}
