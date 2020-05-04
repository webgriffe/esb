<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ClassTwigExtension extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction('class', [$this, 'getClass'])
        ];
    }

    public function getClass($object): string
    {
        return get_class($object);
    }
}
