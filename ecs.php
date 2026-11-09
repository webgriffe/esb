<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths(['src'])
    ->withPreparedSets(psr12: true, cleanCode: true)
    ->withSkip([
        'Class Server contains unused private method requestHandler().',
        'Class HttpProducersServer contains unused private method requestHandler().',
    ]);
