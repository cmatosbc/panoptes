<?php

namespace Panoptes;

use Fiber;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Panoptes\Exception\RequestException;
use Panoptes\Exception\PanoptesException;

class AsyncCurl implements ClientInterface
{
    private array $requests = [];
    private array $fibers = [];
    private array $progress = [];
    private array $streamHandles = [];
    private array $requestStates = [];
    private int $currentConcurrency = 0;

    public function __construct(
        private readonly int $maxConcurrency = 5,
        private readonly bool $streamResponses = false
    ) {
        if ($maxConcurrency < 1) {
            throw new PanoptesException('Max concurrency must be at least 1');
        }
    }

    public function addRequest(CurlRequest $request): void
    {
        $index = count($this->requests);
        $this->requests[] = $request;
        $this->requestStates[$index] = [
            'started' => false,
            'completed' => false,
            'active' => false
        ];
    }

    public function addRequests(array $requests): void
    {
        foreach ($requests as $request) {
            if (!$request instanceof CurlRequest) {
                throw new PanoptesException('All requests must be instances of CurlRequest');
            }
            $this->addRequest($request);
        }
    }

    public function getProgress(): array
    {
        $completed = count(array_filter($this->progress, fn($p) => $p['status'] === 'completed'));
        $failed = count(array_filter($this->progress, fn($p) => isset($p['error'])));
        
        return [
            'total' => count($this->requests),
            'completed' => $completed + $failed,
            'failed' => $failed,
            'details' => $this->progress
        ];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $curlRequest = new CurlRequest(
            $request->getUri()->__toString(),
            $request->getMethod(),
            $this->formatPsrHeaders($request->getHeaders()),
            $request->getBody()->__toString()
        );
        
        $this->addRequest($curlRequest);
        $results = $this->promiseAll();
        
        return $results[0] ?? throw new RequestException('Request failed');
    }

    private function formatPsrHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $values) {
            $formatted[] = $name . ': ' . implode(', ', $values);
        }
        return $formatted;
    }

    private function validateCurlOptions(array $options): array
    {
        $validOptions = [];
        foreach ($options as $option => $value) {
            if (!is_int($option)) {
                error_log("Invalid cURL option (not an integer): $option");
                continue;
            }
            // Check if the option is a valid cURL option constant
            $validOptions[$option] = $value;
        }
        return $validOptions;
    }

    public function promiseAll(): array
    {
        if (empty($this->requests)) {
            return [];
        }

        $mainFiber = new Fiber(function() {
            $results = [];
            $errors = [];
            $this->progress = [
                'total' => count($this->requests),
                'completed' => 0,
                'failed' => 0
            ];

            // Sort requests by priority (high to low)
            $requestOrder = array_keys($this->requests);
            $priorities = array_map(fn($req) => $req->getPriority(), $this->requests);
            array_multisort($priorities, SORT_DESC, SORT_NUMERIC, $requestOrder);
            $orderedRequests = [];
            foreach ($requestOrder as $index) {
                $orderedRequests[$index] = $this->requests[$index];
            }

            // Initialize curl multi handle
            $mh = curl_multi_init();
            $handles = [];
            $activeHandles = [];
            $retryQueue = [];
            $priorityMap = array_flip($requestOrder);

            // Process requests in batches
            while (!empty($orderedRequests) || !empty($activeHandles) || !empty($retryQueue)) {
                // Start new requests up to max concurrency
                while (count($activeHandles) < $this->maxConcurrency && (!empty($orderedRequests) || !empty($retryQueue))) {
                    $request = null;
                    $index = null;

                    // Prioritize retry queue
                    if (!empty($retryQueue)) {
                        $index = array_key_first($retryQueue);
                        $request = $retryQueue[$index];
                        unset($retryQueue[$index]);
                    } elseif (!empty($orderedRequests)) {
                        $index = array_key_first($orderedRequests);
                        $request = $orderedRequests[$index];
                        unset($orderedRequests[$index]);
                    }

                    if ($request === null) continue;

                    $this->progress[$index] = [
                        'status' => 'running',
                        'attempts' => $request->getAttempts() + 1
                    ];

                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $request->getUrl(),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HEADER => false,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 3,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_NOSIGNAL => true,
                        CURLOPT_FRESH_CONNECT => true,
                        CURLOPT_FORBID_REUSE => true,
                        CURLOPT_NOPROGRESS => false,
                        CURLOPT_PROGRESSFUNCTION => function($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($request) {
                            $request->triggerProgress($dlTotal, $dlNow, $ulTotal, $ulNow);
                            return 0;
                        }
                    ]);

                    $request->triggerStart();
                    curl_multi_add_handle($mh, $ch);
                    $handles[$index] = [
                        'handle' => $ch,
                        'request' => $request
                    ];
                    $activeHandles[$index] = true;
                    $this->currentConcurrency = count($activeHandles);
                }

                // Process active handles
                do {
                    $status = curl_multi_exec($mh, $active);
                    
                    // Allow other fibers to run and update progress
                    Fiber::suspend();
                    
                    while ($info = curl_multi_info_read($mh)) {
                        $ch = $info['handle'];
                        foreach ($handles as $index => $data) {
                            if ($data['handle'] === $ch) {
                                $request = $data['request'];
                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                $response = curl_multi_getcontent($ch);
                                
                                if ($response === false || $httpCode >= 500) {
                                    $request->incrementAttempts();
                                    if ($request->getAttempts() < $request->getMaxAttempts()) {
                                        // Add to retry queue
                                        $retryQueue[$index] = $request;
                                        usleep($request->getRetryDelay() * 1000);
                                    } else {
                                        // Request failed all attempts
                                        $this->progress[$index]['status'] = 'failed';
                                        $this->progress[$index]['error'] = curl_error($ch);
                                        $errors[$index] = new RequestException(
                                            'Request failed after ' . $request->getAttempts() . ' attempts: ' . curl_error($ch)
                                        );
                                    }
                                } else {
                                    // Request succeeded
                                    $this->progress[$index]['status'] = 'completed';
                                    $priority = $priorityMap[$index];
                                    $response = new CurlResponse($response, $httpCode);

                                    // Handle stream response if enabled
                                    if ($this->streamResponses && $request->getCallback()) {
                                        $callback = $request->getCallback();
                                        $response = $callback($response);
                                    }

                                    $results[$priority] = $response;
                                }

                                if ($this->streamResponses && $request->getStreamResponse()) {
                                    $stream = $request->getStreamResponse();
                                    $stream->write($response);
                                }

                                $request->triggerFinish();
                                curl_multi_remove_handle($mh, $ch);
                                curl_close($ch);
                                unset($handles[$index]);
                                unset($activeHandles[$index]);
                                $this->currentConcurrency = count($activeHandles);
                                break;
                            }
                        }
                    }

                    if ($active) {
                        curl_multi_select($mh, 0.01); // 10ms timeout
                    }
                } while ($active > 0);
            }

            curl_multi_close($mh);

            if (!empty($errors)) {
                throw $errors[array_key_first($errors)];
            }

            // Return results in priority order (high to low)
            ksort($results);
            return array_values($results);
        });

        $mainFiber->start();
        while (!$mainFiber->isTerminated()) {
            if ($mainFiber->isSuspended()) {
                $mainFiber->resume();
            }
            usleep(10000); // 10ms delay
        }

        return $mainFiber->getReturn();
    }
}
