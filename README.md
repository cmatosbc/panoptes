# Panoptes
True asynchronous cURL requests for PHP 8.1 or later.

In Greek mythology, Argus was tasked with the important job of guarding Io, ever-vigilant with his hundred eyes. Similarly, the PHP cURL library keeps watch over numerous web requests, processing them concurrently and asynchronously. Each "eye" or thread in the library can independently initiate a cURL request, wait for the server's response, and process the received data, all without blocking the execution of other tasks. This concurrent handling mimics the way Argus could keep an eye on multiple things at once, never missing a detail.

## Features

- **True Asynchronous Processing**: Leverages PHP 8.1 Fibers for non-blocking concurrent requests
- **Request Prioritization**: Control the order of request processing with priority levels
- **Progress Tracking**: Real-time monitoring of request status, attempts, and timing
- **Stream Support**: Memory-efficient handling of large responses through streaming
- **Automatic Retries**: Configurable retry mechanism with delay control
- **PSR-18 Compliant**: Implements PHP-FIG HTTP Client interface standards
- **Robust Error Handling**: Detailed error context and custom exception hierarchy
- **Highly Configurable**: Fine-grained control over cURL options and behavior

## Requirements

- PHP 8.1 or later
- PHP cURL extension
- Composer

## Installation

```bash
composer require codeium/panoptes
```

## Quick Start

```php
use Panoptes\AsyncCurl;
use Panoptes\CurlRequest;

// Create client with max 5 concurrent requests
$client = new AsyncCurl(maxConcurrency: 5);

// Add multiple requests
$client->addRequests([
    new CurlRequest('https://api.example.com/users'),
    new CurlRequest('https://api.example.com/posts'),
    new CurlRequest('https://api.example.com/comments')
]);

// Execute all requests concurrently
$responses = $client->promiseAll();
```

## Advanced Usage

### Request Prioritization

```php
// High priority request (processed first)
$highPriority = new CurlRequest(
    'https://api.example.com/critical',
    priority: 10
);

// Normal priority request
$normalPriority = new CurlRequest(
    'https://api.example.com/normal'
);

$client->addRequests([$highPriority, $normalPriority]);
```

### Streaming Response

```php
// Create client with streaming enabled
$client = new AsyncCurl(streamResponses: true);

// Create request with stream handling
$request = new CurlRequest('https://api.example.com/large-file');
$request->setCallback(function($response) {
    $stream = $response->getBody();
    while (!$stream->eof()) {
        $chunk = $stream->read(8192);
        // Process chunk
    }
    return $response;
});

$client->addRequest($request);
```

### Retry Mechanism

```php
// Configure retries with delay
$request = new CurlRequest('https://api.example.com/flaky');
$request->setRetries(
    retries: 3,        // Number of retry attempts
    delay: 1000        // Delay in milliseconds between retries
);
```

### Progress Tracking

```php
$client->addRequests([/* ... */]);

// Start requests
$fiber = new Fiber(function() use ($client) {
    return $client->promiseAll();
});
$fiber->start();

// Check progress while requests are running
while ($fiber->isSuspended()) {
    $progress = $client->getProgress();
    echo "Completed: {$progress['completed']} / {$progress['total']}\n";
    
    // Show details of each request
    foreach ($progress['details'] as $detail) {
        echo "Status: {$detail['status']}\n";
        if (isset($detail['http_code'])) {
            echo "HTTP Code: {$detail['http_code']}\n";
        }
        if (isset($detail['total_time'])) {
            echo "Time: {$detail['total_time']}s\n";
        }
    }
    
    sleep(1);
}
```

### Custom Headers and Options

```php
$request = new CurlRequest('https://api.example.com/data');

// Add custom headers
$request->setHeaders([
    'Authorization' => 'Bearer ' . $token,
    'Accept' => 'application/json',
    'X-Custom-Header' => 'value'
]);

// Set cURL options using type-safe enum
$request->setOption(RequestOptions::TIMEOUT, 30);
$request->setOption(RequestOptions::FOLLOW_LOCATION, true);
$request->setOption(RequestOptions::SSL_VERIFY_PEER, true);
```

### POST Requests with Data

```php
// JSON POST request
$request = new CurlRequest(
    'https://api.example.com/users',
    method: 'POST',
    headers: ['Content-Type: application/json'],
    postFields: json_encode([
        'name' => 'John Doe',
        'email' => 'john@example.com'
    ])
);

// Form POST request
$request = new CurlRequest(
    'https://api.example.com/upload',
    method: 'POST',
    postFields: [
        'field1' => 'value1',
        'field2' => 'value2'
    ]
);
```

### Error Handling

```php
use Panoptes\Exception\RequestException;
use Panoptes\Exception\PanoptesException;

try {
    $responses = $client->promiseAll();
} catch (RequestException $e) {
    // Get detailed error context
    $context = $e->getContext();
    echo "Failed requests:\n";
    foreach ($context['errors'] as $error) {
        echo "URL: {$error['context']['url']}\n";
        echo "Method: {$error['context']['method']}\n";
        echo "Attempts: {$error['context']['attempts']}\n";
        echo "Error: {$error['message']}\n";
    }
} catch (PanoptesException $e) {
    // Handle other library errors
    echo "Library error: " . $e->getMessage();
}
```

## PSR-18 Integration

Panoptes implements PSR-18's `ClientInterface`, making it compatible with any PSR-18 HTTP client consumer:

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

class MyService {
    public function __construct(
        private ClientInterface $client
    ) {}
    
    public function fetchData(RequestInterface $request) {
        return $this->client->sendRequest($request);
    }
}

// Use Panoptes as PSR-18 client
$service = new MyService(new AsyncCurl());
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request. For major changes, please open an issue first to discuss what you would like to change.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
