# Fiber-based Concurrency (Dispatcher)

This document explains the new Fiber-first concurrency layer added under `src/Concurrency` on the `feature/fibers-dispatcher` branch. The implementation is non‑breaking and integrates with the existing project types (`CurlRequest`, `CurlResponse`, `AsyncCurl`).

## Overview

The new Dispatcher provides a small, ergonomic API to build workflows that use PHP Fibers and `curl_multi` while allowing branching (spawn multiple dependent requests from a response). The Dispatcher enforces curl concurrency limits while letting your code run in Fibers that suspend on `await()` and resume when responses arrive.

Key features:
- Fiber-based `await()` to suspend and resume coroutines on HTTP responses.
- `spawn()` to run functions as Fibers and `gather()` to collect results.
- `enqueue()` accepts `CurlRequest` or a callable factory for late request creation.
- `run()` is the event loop; `runBlocking()` is a convenience that spawns a root fiber and runs the loop until it completes.
- Non-breaking: existing `AsyncCurl::addRequest`, `addRequests`, and `promiseAll()` continue to work.

All new classes live in the `Panoptes\Concurrency` namespace and are autoloaded by the existing `Panoptes\` PSR-4 mapping.

---

## Quick start (runBlocking)

This example uses the convenience `AsyncCurl::createDispatcher()` factory and `runBlocking()` to run a small workflow that fetches a list and then branches into detail requests.

```php
use Panoptes\AsyncCurl;
use Panoptes\CurlRequest;

$dispatcher = AsyncCurl::createDispatcher(10); // maxConcurrency = 10

$result = $dispatcher->runBlocking(function() use ($dispatcher) {
    // Enqueue a list request
    $listReq = new CurlRequest('https://httpbin.org/json');
    $listHandle = $dispatcher->enqueue($listReq);

    // Suspend this fiber until the list response is available
    $listResp = $dispatcher->await($listHandle);

    // Inspect the response and spawn children to fetch details in parallel
    $items = json_decode($listResp->getBody()->__toString(), true)['items'] ?? [];

    $children = [];
    foreach ($items as $item) {
        $children[] = $dispatcher->spawn(function() use ($dispatcher, $item) {
            $r = new CurlRequest('https://httpbin.org/get?id=' . $item['id']);
            $h = $dispatcher->enqueue($r);
            $resp = $dispatcher->await($h);
            return $resp; // Fiber returns CurlResponse
        });
    }

    // Wait for all children and collect their return values
    $results = $dispatcher->gather($children);
    return $results; // runBlocking returns this value
});

print_r($result);
```

Notes:
- `await()` may only be called inside a Fiber created by this Dispatcher (e.g. within `spawn()` or within the callable passed to `runBlocking()`).
- Use `gather()` to wait for many FiberHandle values in the same or defined order.

---

## spawn() and await() pattern

`spawn(callable)` starts a new Fiber and returns a `Panoptes\Concurrency\FiberHandle`. The callable can call `enqueue()` and `await()` to compose dependent requests in sequential style. Example:

```php
$dh = AsyncCurl::createDispatcher();
$root = $dh->spawn(function() use ($dh) {
    $h1 = $dh->enqueue(new CurlRequest('https://httpbin.org/get?a=1'));
    $r1 = $dh->await($h1);

    // create a second request based on the first response
    $h2 = $dh->enqueue(new CurlRequest('https://httpbin.org/get?b=' . urlencode($r1->getBody())));
    $r2 = $dh->await($h2);

    return [$r1, $r2];
});

$dh->run();
$values = $root->await();
```

---

## Branching and concurrent children

Inside a fiber you can spawn multiple child fibers that run concurrently. The dispatcher enforces only curl concurrency; spawning many fibers is allowed but you should coordinate concurrency at the application level when necessary.

Example: spawn N concurrent child requests and gather results in the same order:

```php
$children = [];
foreach ($ids as $id) {
    $children[] = $dispatcher->spawn(function() use ($dispatcher, $id) {
        $req = new CurlRequest("https://api.example.com/item/{$id}");
        $h = $dispatcher->enqueue($req);
        return $dispatcher->await($h); // returns CurlResponse
    });
}
$results = $dispatcher->gather($children);
```

`gather()` returns an array with the fibers' return values in the same order passed.

---

## Enqueue with factory (late request creation)

`enqueue()` accepts a callable factory if you prefer to delay request construction until dispatch time:

```php
$handle = $dispatcher->enqueue(function() use ($id) {
    // This factory runs inside the dispatcher when the request is about to be started
    return new CurlRequest("https://api.example.com/item/{$id}");
});

$response = $dispatcher->await($handle);
```

This is useful when creating request bodies or streams that should be initialized just before the transfer.

---

## Timeouts, retries and RequestOptions

The Dispatcher reuses the existing `CurlRequest` properties (timeout, attempts, retry delay) when building the cURL handle. The retry/backoff behavior mirrors the existing `promiseAll()` semantics: transient failures increment attempts and will be retried up to `getMaxAttempts()` with `getRetryDelay()` between attempts.

If you need custom retry logic you can catch exceptions inside the Fiber and re-enqueue alternate requests.

---

## Error handling and cancellation

- If a request fails, `await()` will receive a `\Throwable` (the dispatcher resumes the fiber with an exception) — you can `try { $r = $dh->await($h); } catch (\Throwable $e) { /* handle */ }` inside the fiber.
- `RequestHandle::cancel()` is available as a public API and the Dispatcher honors cancellation for queued or in-flight requests, resuming awaiting fibers with a `CancelledException`.

---

## Migration and compatibility notes

- This change is non‑breaking: existing users who call `AsyncCurl::addRequest()` / `addRequests()` / `promiseAll()` are unaffected.
- The Dispatcher is opt‑in. Use `AsyncCurl::createDispatcher()` to get a `Panoptes\Concurrency\Dispatcher` instance wired to the same request/response types.
- New files are under `src/Concurrency/` and are loaded via the existing `Panoptes\` PSR-4 autoloader mapping.

---

## API reference (summary)

- Dispatcher::__construct(int $maxConcurrency = 5)
- Dispatcher::enqueue(CurlRequest|callable): Panoptes\Concurrency\RequestHandle
- Dispatcher::spawn(callable): Panoptes\Concurrency\FiberHandle
- Dispatcher::await(RequestHandle): CurlResponse
- Dispatcher::gather(array<FiberHandle|RequestHandle>): array
- Dispatcher::run(): void
- Dispatcher::runBlocking(callable): mixed

Helpers:
- AsyncCurl::createDispatcher(?int $maxConcurrency = null): Dispatcher

---

## Example file

A runnable example was added at `examples/fibers.php` on the branch. See it for a full working sample that demonstrates `enqueue`, `await`, `spawn`, `gather`, and `runBlocking`.