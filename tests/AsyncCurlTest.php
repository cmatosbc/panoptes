<?php

namespace Panoptes\Tests;

use PHPUnit\Framework\TestCase;
use Panoptes\AsyncCurl;
use Panoptes\CurlRequest;
use Panoptes\CurlResponse;
use Panoptes\Exception\RequestException;

class AsyncCurlTest extends TestCase
{
    private $mockServer;
    private $mockServerPort = 8765; // Fixed port instead of random
    private AsyncCurl $client;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Start mock server
        $this->startMockServer();
        
        $this->client = new AsyncCurl(maxConcurrency: 3);
    }

    protected function tearDown(): void
    {
        // Cleanup mock server
        if ($this->mockServer) {
            proc_terminate($this->mockServer);
            proc_close($this->mockServer);
        }
        
        parent::tearDown();
    }

    private function startMockServer(): void
    {
        $router = <<<'PHP'
        <?php
        error_log("Mock server started on port " . $_SERVER['SERVER_PORT']);
        
        $sleepTimes = [
            '/fast' => 0.1, // Small delay to ensure consistent behavior
            '/medium' => 0.5,
            '/slow' => 1,
            '/error' => 0,
            '/retry' => 0
        ];

        // Use a file to persist retry count
        $retryFile = sys_get_temp_dir() . '/retry_count.json';
        if (!file_exists($retryFile)) {
            file_put_contents($retryFile, json_encode([]));
        }
        $retryCount = json_decode(file_get_contents($retryFile), true);

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        error_log("Received request for path: $path at " . microtime(true));
        
        $sleepTime = $sleepTimes[$path] ?? 0;
        
        if ($sleepTime > 0) {
            error_log("Sleeping for {$sleepTime} seconds");
            usleep($sleepTime * 1000000);
        }
        
        header('Content-Type: application/json');
        
        if ($path === '/error') {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error']);
            exit;
        }
        
        if ($path === '/retry') {
            $retryCount[$path] = ($retryCount[$path] ?? 0) + 1;
            file_put_contents($retryFile, json_encode($retryCount));
            
            if ($retryCount[$path] < 3) {
                http_response_code(503);
                echo json_encode(['error' => 'Service Unavailable']);
                exit;
            }
        }

        http_response_code(200);
        echo json_encode([
            'path' => $path,
            'sleep' => $sleepTime,
            'time' => microtime(true)
        ]);
        PHP;

        $routerFile = tempnam(sys_get_temp_dir(), 'router');
        file_put_contents($routerFile, $router);

        // Try to start the server
        $command = sprintf(
            'php -S localhost:%d %s 2>&1',
            $this->mockServerPort,
            $routerFile
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $this->mockServer = proc_open($command, $descriptors, $pipes);
        if ($this->mockServer === false) {
            throw new \RuntimeException('Failed to start mock server');
        }

        // Wait for server to start with increased timeout
        $startTime = microtime(true);
        $serverReady = false;
        $maxWaitTime = 5; // Maximum wait time in seconds
        
        while (microtime(true) - $startTime < $maxWaitTime) {
            $ch = curl_init("http://localhost:{$this->mockServerPort}/");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode > 0) {
                $serverReady = true;
                break;
            }
            usleep(100000); // 100ms
        }

        if (!$serverReady) {
            throw new \RuntimeException(
                "Mock server failed to start on port {$this->mockServerPort} after {$maxWaitTime} seconds"
            );
        }

        // Additional wait to ensure server is fully ready
        usleep(500000); // 500ms
    }

    private function getMockUrl(string $path): string
    {
        return "http://localhost:{$this->mockServerPort}{$path}";
    }

    private function createRequest(string $path, int $priority = 0): CurlRequest
    {
        $request = new CurlRequest($this->getMockUrl($path));
        if ($priority > 0) {
            $request->setPriority($priority);
        }
        return $request;
    }

    public function testAsyncRequestsExecuteConcurrently(): void
    {
        $startTime = microtime(true);

        $requests = [
            $this->createRequest('/slow'),    // 1 second
            $this->createRequest('/medium'),  // 0.5 seconds
            $this->createRequest('/fast')     // 0.1 seconds
        ];

        $this->client->addRequests($requests);
        $responses = $this->client->promiseAll();

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // If requests were truly async, total time should be around 1 second (longest request)
        // Add some buffer for overhead
        $this->assertLessThan(2.0, $totalTime, "Requests took too long: {$totalTime} seconds");
        $this->assertGreaterThan(0.8, $totalTime, "Requests completed too quickly: {$totalTime} seconds");
        
        // Verify all responses were received
        $this->assertCount(3, $responses);
        
        // Verify response content and timing
        $responseTimes = [];
        foreach ($responses as $response) {
            $data = json_decode($response->getBody()->getContents(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('path', $data);
            $this->assertArrayHasKey('sleep', $data);
            $this->assertArrayHasKey('time', $data);
            $responseTimes[] = $data['time'];
        }

        // Verify that responses were received within a reasonable timeframe of each other
        // Increased threshold to account for server processing overhead
        $timeSpread = max($responseTimes) - min($responseTimes);
        $this->assertLessThan(1.0, $timeSpread, "Responses were too spread out: {$timeSpread} seconds");
    }

    public function testPrioritization(): void
    {
        $requests = [
            $this->createRequest('/slow', 1),   // Low priority
            $this->createRequest('/medium', 2), // Medium priority
            $this->createRequest('/fast', 3)    // High priority
        ];

        $this->client->addRequests($requests);
        $responses = $this->client->promiseAll();

        // Verify execution order matches priority
        $executionOrder = array_map(function($response) {
            return json_decode($response->getBody()->getContents(), true)['path'];
        }, $responses);

        $this->assertEquals(['/fast', '/medium', '/slow'], $executionOrder);
    }

    public function testRetryMechanism(): void
    {
        $request = $this->createRequest('/retry');
        $request->setMaxAttempts(3);
        $request->setRetryDelay(100); // 100ms delay

        $this->client->addRequest($request);
        $responses = $this->client->promiseAll();

        $this->assertCount(1, $responses);
        $response = $responses[0];
        
        // Should succeed after retries
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testErrorHandling(): void
    {
        $request = $this->createRequest('/error');
        $this->client->addRequest($request);

        $this->expectException(RequestException::class);
        $this->client->promiseAll();
    }

    public function testProgressTracking(): void
    {
        $request = $this->createRequest('/slow'); // Use slow request to ensure progress updates
        $progress = 0;
        
        $request->onProgress(function($dlTotal, $dlNow, $ulTotal, $ulNow) use (&$progress) {
            $progress = $dlNow;
        });

        $this->client->addRequest($request);
        $responses = $this->client->promiseAll();

        $this->assertGreaterThan(0, $progress, "Progress tracking should show some progress");
    }

    public function testConcurrencyLimit(): void
    {
        $maxConcurrency = 2;
        $client = new AsyncCurl(maxConcurrency: $maxConcurrency);
        $activeRequests = 0;
        $maxActiveRequests = 0;

        $requests = array_map(function($i) use (&$activeRequests, &$maxActiveRequests) {
            $request = $this->createRequest('/fast');
            $request->onStart(function() use (&$activeRequests, &$maxActiveRequests) {
                $activeRequests++;
                $maxActiveRequests = max($maxActiveRequests, $activeRequests);
            });
            $request->onFinish(function() use (&$activeRequests) {
                $activeRequests--;
            });
            return $request;
        }, range(1, 5));

        $client->addRequests($requests);
        $responses = $client->promiseAll();

        $this->assertCount(5, $responses);
        $this->assertEquals($maxConcurrency, $maxActiveRequests, "Concurrency limit was not respected");
    }

    public function testStreamResponse(): void
    {
        $client = new AsyncCurl(maxConcurrency: 1, streamResponses: true);
        $chunks = [];

        $request = $this->createRequest('/fast');
        $request->setCallback(function($response) use (&$chunks) {
            $stream = $response->getBody();
            while (!$stream->eof()) {
                $chunks[] = $stream->read(8192);
            }
            return $response;
        });

        $client->addRequest($request);
        $responses = $client->promiseAll();

        $this->assertCount(1, $responses);
        $this->assertNotEmpty($chunks);
        
        // Verify the chunks can be combined into valid JSON
        $data = json_decode(implode('', $chunks), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('path', $data);
        $this->assertEquals('/fast', $data['path']);
    }
}
