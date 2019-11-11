<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Exception\ElasticSearch;

use Throwable;

class JobNotFoundException extends \RuntimeException
{
    public function __construct(string $jobUuid, $code = 0, Throwable $previous = null)
    {
        parent::__construct(sprintf('Job with UUID "%s" has not been found.', $jobUuid), $code, $previous);
    }
}
