<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

return static function (ECSConfig $ecsConfig): void {
    $ecsConfig->paths(['src']);

    $ecsConfig->sets([SetList::PSR_12, SetList::CLEAN_CODE]);

    $ecsConfig->skip(
        [
            'Class Server contains unused private method requestHandler().',
            'Class HttpProducersServer contains unused private method requestHandler().'
        ]
    );
};
