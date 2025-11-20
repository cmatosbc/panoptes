<?php

namespace Panoptes\Concurrency;

use Fiber;
use Panoptes\CurlRequest;
use Panoptes\CurlResponse;
use Panoptes\AsyncCurl;
use Panoptes\Exception\RequestException;

class Dispatcher
{
    private int $maxConcurrency;
    private int $nextRequestId = 1;

    /** @var array<int, array{request: CurlRequest, handle: RequestHandle, attempts:int}> */
    private array $readyQueue = [];

    /** @var array<int, array{handle: resource, index:int, node:array}> */
    private array $inFlight = [];

    /** @var array<int, FiberHandle> */
    private array $waitingFibers = [];

    /** @var array<FiberHandle> */
    private array $spawnedFibers = [];

    private $multiHandle;

    public function __construct(int $maxConcurrency = 5)
    {
        if ($maxConcurrency < 1) {
            throw new \InvalidArgumentException('maxConcurrency must be >= 1');
        }
        $this->maxConcurrency = $maxConcurrency;
        $this->multiHandle = curl_multi_init();
    }

    public function __destruct()
    {
        if (is_resource($this->multiHandle)) {
            curl_multi_close($this->multiHandle);
        }
    }

    public function enqueue(CurlRequest|callable $request): RequestHandle
    {
        $id = $this->nextRequestId++;
        $req = $request instanceof CurlRequest ? $request : null;
        $handle = new RequestHandle($id);

        $node = [
            'id' => $id,
            'request' => $req,
            'factory' => $request instanceof CurlRequest ? null : $request,
            'handle' => $handle,
            'attempts' => 0,
        ];

        $this->readyQueue[$id] = $node;

        return $handle;
    }

    public function spawn(callable $callable): FiberHandle
    {
        $fiber = new Fiber(function () use ($callable) {
            return $callable();
        });

        $fHandle = new FiberHandle($fiber);

        // Start the fiber immediately; it will run until it suspends (await) or finishes
        $fHandle->markStarted();
        $suspendValue = $fiber->start();

        // If the fiber suspended to await a request, the suspendValue will be an array signal
        if (is_array($suspendValue) && isset($suspendValue[0]) && $suspendValue[0] === 'await') {
            $reqId = $suspendValue[1];
            $this->waitingFibers[$reqId] = $fHandle;
        }

        // Keep track of spawned fibers to drive them in the run loop
        $this->spawnedFibers[] = $fHandle;

        return $fHandle;
    }

    /**
     * Must be called from inside a Fiber created by this Dispatcher. Suspends the fiber
     * until the request completes and returns a CurlResponse (or throws).
     *
     * @param RequestHandle $handle
     * @return CurlResponse
     * @throws RequestException
     */
    public function await(RequestHandle $handle): CurlResponse
    {
        $id = $handle->id();

        // If request already completed and was removed from queues, we cannot easily return it here.
        // For simplicity, suspend and let dispatcher resume with the response when available.
        $signal = ['await', $id];
        $value = Fiber::suspend($signal);

        if ($value instanceof \Throwable) {
            throw $value;
        }

        if ($value instanceof CurlResponse) {
            $handle->markDone();
            return $value;
        }

        throw new RequestException('Unexpected response from dispatcher');
    }

    public function gather(array $handles): array
    {
        $results = [];
        foreach ($handles as $i => $h) {
            if ($h instanceof FiberHandle) {
                $results[$i] = $h->await();
            } elseif ($h instanceof RequestHandle) {
                // Simple polling for RequestHandle completion
                while (!$h->isDone()) {
                    usleep(10000);
                }
                // No stored response here — users should await inside fibers. Return null as placeholder.
                $results[$i] = null;
            } else {
                $results[$i] = null;
            }
        }
        return $results;
    }

    public function run(): void
    {
        // Main event loop: keep processing until no ready requests, no in-flight, and no running fibers
        while (!$this->isIdle()) {
            // 1) Fill curl_multi up to concurrency
            $this->fillCurlMulti();

            // 2) Execute curl_multi
            $running = null;
            do {
                $status = curl_multi_exec($this->multiHandle, $running);

                // Process completed transfers
                while ($info = curl_multi_info_read($this->multiHandle)) {
                    $ch = $info['handle'];
                    $key = (int)$ch;
                    if (!isset($this->inFlight[$key])) {
                        // unknown handle
                        curl_multi_remove_handle($this->multiHandle, $ch);
                        curl_close($ch);
                        continue;
                    }

                    $node = $this->inFlight[$key]['node'];
                    $reqId = $node['id'];
                    $request = $node['request'];

                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $content = curl_multi_getcontent($ch);

                    curl_multi_remove_handle($this->multiHandle, $ch);
                    curl_close($ch);
                    unset($this->inFlight[$key]);

                    if ($info['result'] !== CURLM_OK && $content === false) {
                        $ex = new RequestException(curl_error($ch));
                        $this->resumeFiberWithException($reqId, $ex);
                        continue;
                    }

                    $response = new CurlResponse($content, $httpCode);

                    // Resume any fiber waiting on this request
                    if (isset($this->waitingFibers[$reqId])) {
                        $fHandle = $this->waitingFibers[$reqId];
                        unset($this->waitingFibers[$reqId]);

                        $suspendValue = $fHandle->getInternalFiber()->resume($response);

                        // If the resumed fiber suspended again to await another request, register it
                        if (is_array($suspendValue) && $suspendValue[0] === 'await') {
                            $waitingId = $suspendValue[1];
                            $this->waitingFibers[$waitingId] = $fHandle;
                        }
                    }
                }

                if ($running > 0) {
                    // Wait for activity
                    curl_multi_select($this->multiHandle, 0.01);
                }

            } while ($running > 0);

            // Allow a small pause to let spawned fibers run and potentially enqueue new requests
            $this->driveSpawnedFibers();

            // Prevent busy loop
            usleep(1000);
        }
    }

    public function runBlocking(callable $work)
    {
        $root = $this->spawn($work);
        $this->run();
        return $root->await();
    }

    private function isIdle(): bool
    {
        if (!empty($this->readyQueue)) return false;
        if (!empty($this->inFlight)) return false;
        // check spawned fibers: consider them active until terminated
        foreach ($this->spawnedFibers as $fh) {
            if (!$fh->getInternalFiber()->isTerminated()) return false;
        }
        return true;
    }

    private function fillCurlMulti(): void
    {
        while (count($this->inFlight) < $this->maxConcurrency && !empty($this->readyQueue)) {
            $node = array_shift($this->readyQueue);
            $req = $node['request'];
            if ($req === null && is_callable($node['factory'])) {
                // create the request lazily
                $req = ($node['factory'])();
                $node['request'] = $req;
            }

            if (!$req instanceof CurlRequest) {
                // cannot start non-CurlRequest; skip
                continue;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $req->getUrl(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $req->getTimeout() ?? 30,
                CURLOPT_NOSIGNAL => true,
            ] + $req->getCurlOptions());

            $req->triggerStart();
            curl_multi_add_handle($this->multiHandle, $ch);

            $this->inFlight[(int)$ch] = ['handle' => $ch, 'node' => $node, 'index' => $node['id']];
        }
    }

    private function driveSpawnedFibers(): void
    {
        // Resume any spawned fibers that may be suspended for other reasons (not waiting on request)
        foreach ($this->spawnedFibers as $key => $fHandle) {
            $fiber = $fHandle->getInternalFiber();
            if ($fiber->isTerminated()) continue;

            // If the fiber is suspended but not waiting for a request, resume it with null
            // The fiber's suspend values that indicate await() are handled elsewhere
            $res = $fiber->resume();
            if (is_array($res) && isset($res[0]) && $res[0] === 'await') {
                $this->waitingFibers[$res[1]] = $fHandle;
            }
        }
    }

    private function resumeFiberWithException(int $reqId, \Throwable $ex): void
    {
        if (isset($this->waitingFibers[$reqId])) {
            $fHandle = $this->waitingFibers[$reqId];
            unset($this->waitingFibers[$reqId]);
            try {
                $fHandle->getInternalFiber()->resume($ex);
            } catch (\Throwable $ignore) {
                // no-op
            }
        }
    }
}