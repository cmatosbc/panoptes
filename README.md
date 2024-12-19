# Panoptes
True asynchronous cURL requests for PHP 8.2 or later.

In Greek mythology, Argus was tasked with the important job of guarding Io, ever-vigilant with his hundred eyes. Similarly, the PHP cURL library keeps watch over numerous web requests, processing them concurrently and asynchronously. Each "eye" or thread in the library can independently initiate a cURL request, wait for the server's response, and process the received data, all without blocking the execution of other tasks. This concurrent handling mimics the way Argus could keep an eye on multiple things at once, never missing a detail.

## Features

- **True Asynchronous Processing**: Leverages PHP 8.2 Fibers for non-blocking concurrent requests
- **Request Prioritization**: Control the order of request processing with priority levels
- **Progress Tracking**: Real-time monitoring of request status and download progress
- **Stream Support**: Memory-efficient handling of large responses through streaming
- **Automatic Retries**: Configurable retry mechanism with delay control
- **PSR-18 Compliant**: Implements PHP-FIG HTTP Client interface standards
- **Robust Error Handling**: Detailed error context and custom exception hierarchy
- **Highly Configurable**: Fine-grained control over cURL options and behavior

## Requirements

- PHP 8.2 or later
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
$request = new CurlRequest('https://api.example.com/critical');
$request->setPriority(3); // Higher number = higher priority

// Medium priority request
$request2 = new CurlRequest('https://api.example.com/normal');
$request2->setPriority(2);

// Low priority request
$request3 = new CurlRequest('https://api.example.com/background');
$request3->setPriority(1);

$client->addRequests([$request, $request2, $request3]);
```

### Progress Tracking

```php
$request = new CurlRequest('https://api.example.com/large-file');

// Track download progress
$request->onProgress(function($dlTotal, $dlNow, $ulTotal, $ulNow) {
    $progress = ($dlTotal > 0) ? ($dlNow / $dlTotal) * 100 : 0;
    echo "Download progress: {$progress}%\n";
});

// Track request lifecycle
$request->onStart(function() {
    echo "Request started\n";
});

$request->onFinish(function() {
    echo "Request completed\n";
});

$client->addRequest($request);
$responses = $client->promiseAll();
```

### Retry Mechanism

```php
$request = new CurlRequest('https://api.example.com/flaky');
$request->setMaxAttempts(3);      // Maximum number of attempts
$request->setRetryDelay(1000);    // Delay in milliseconds between retries

$client->addRequest($request);
```

### Stream Response

```php
// Create client with streaming enabled
$client = new AsyncCurl(maxConcurrency: 1, streamResponses: true);

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

### Custom Headers and POST Data

```php
$request = new CurlRequest(
    'https://api.example.com/users',
    method: 'POST',
    headers: [
        'Content-Type: application/json',
        'Authorization: Bearer token123'
    ],
    postFields: json_encode([
        'name' => 'John Doe',
        'email' => 'john@example.com'
    ])
);

$client->addRequest($request);
```

### Error Handling

```php
use Panoptes\Exception\RequestException;
use Panoptes\Exception\PanoptesException;

try {
    $responses = $client->promiseAll();
} catch (RequestException $e) {
    echo "Request failed: " . $e->getMessage() . "\n";
    echo "HTTP Code: " . $e->getCode() . "\n";
} catch (PanoptesException $e) {
    echo "Library error: " . $e->getMessage() . "\n";
}
```

## Testing

Run the test suite:

```bash
composer test
```

Generate code coverage report:

```bash
composer test-coverage
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
