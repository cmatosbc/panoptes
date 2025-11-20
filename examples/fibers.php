<?php

require __DIR__ . '/../vendor/autoload.php';

use Panoptes\AsyncCurl;
use Panoptes\CurlRequest;

$dispatcher = AsyncCurl::createDispatcher(10);

$result = $dispatcher->runBlocking(function() use ($dispatcher) {
    // enqueue a list request
    $listReq = new CurlRequest('https://httpbin.org/json');
    $listHandle = $dispatcher->enqueue($listReq);

    $listResp = $dispatcher->await($listHandle);
    echo "List response HTTP code: " . $listResp->getStatusCode() . "\n";

    // branch: spawn two detail requests
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $children[] = $dispatcher->spawn(function() use ($dispatcher, $i) {
            $r = new CurlRequest('https://httpbin.org/get?i=' . $i);
            $h = $dispatcher->enqueue($r);
            $resp = $dispatcher->await($h);
            return $resp->getBody();
        });
    }

    $results = $dispatcher->gather($children);
    print_r($results);
    return true;
});

echo "runBlocking returned: ", var_export($result, true), "\n";