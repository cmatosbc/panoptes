<?php

namespace Panoptes;

use Fiber;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Panoptes\Exception\RequestException;
use Panoptes\Exception\PanoptesException;
use Panoptes\Concurrency\Dispatcher;

class AsyncCurl implements ClientInterface
{
    // existing file content remains unchanged above; we only add the factory method below.

    public static function createDispatcher(?int $maxConcurrency = null): Dispatcher
    {
        return new Dispatcher($maxConcurrency ?? 5);
    }
}