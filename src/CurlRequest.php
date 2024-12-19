<?php

namespace Panoptes;

use Closure;
use Psr\Http\Message\StreamInterface;
use Panoptes\Exception\RequestException;

enum RequestOptions: string
{
    case CURLOPT_RETURNTRANSFER = 'CURLOPT_RETURNTRANSFER';
    case CURLOPT_HEADER = 'CURLOPT_HEADER';
    case CURLOPT_HTTPHEADER = 'CURLOPT_HTTPHEADER';
    case CURLOPT_POST = 'CURLOPT_POST';
    case CURLOPT_POSTFIELDS = 'CURLOPT_POSTFIELDS';
    case CURLOPT_URL = 'CURLOPT_URL';
    case CURLOPT_TIMEOUT = 'CURLOPT_TIMEOUT';
    case CURLOPT_CONNECTTIMEOUT = 'CURLOPT_CONNECTTIMEOUT';
    case CURLOPT_USERAGENT = 'CURLOPT_USERAGENT';
    case CURLOPT_REFERER = 'CURLOPT_REFERER';
    case CURLOPT_FOLLOWLOCATION = 'CURLOPT_FOLLOWLOCATION';
    case CURLOPT_MAXREDIRS = 'CURLOPT_MAXREDIRS';
    case CURLOPT_SSL_VERIFYPEER = 'CURLOPT_SSL_VERIFYPEER';
    case CURLOPT_SSL_VERIFYHOST = 'CURLOPT_SSL_VERIFYHOST';

    public static function getDefaultOptions(): array
    {
        return [
            self::CURLOPT_RETURNTRANSFER->value => true,
            self::CURLOPT_HEADER->value => false,
            self::CURLOPT_HTTPHEADER->value => [],
            self::CURLOPT_POST->value => false,
            self::CURLOPT_POSTFIELDS->value => null,
            self::CURLOPT_URL->value => '',
            self::CURLOPT_TIMEOUT->value => 30,
            self::CURLOPT_CONNECTTIMEOUT->value => 30,
            self::CURLOPT_USERAGENT->value => 'Panoptes',
            self::CURLOPT_REFERER->value => '',
            self::CURLOPT_FOLLOWLOCATION->value => true,
            self::CURLOPT_MAXREDIRS->value => 10,
            self::CURLOPT_SSL_VERIFYPEER->value => true,
            self::CURLOPT_SSL_VERIFYHOST->value => 2,
        ];
    }
}

class CurlRequest
{
    private array $options = [];
    private array $headers = [];
    private ?string $postFields = null;
    private int $priority = 0;
    private int $attempts = 0;
    private int $maxAttempts = 3;
    private int $retryDelay = 1000;
    private ?Closure $callback = null;
    private ?Closure $progressCallback = null;
    private ?Closure $startCallback = null;
    private ?Closure $finishCallback = null;
    private ?StreamInterface $streamResponse = null;

    public function __construct(
        private readonly string $url,
        private readonly string $method = 'GET',
        array $headers = [],
        ?string $postFields = null,
        array $options = []
    ) {
        $this->validateUrl($url);
        $this->validateMethod($method);
        $this->headers = $headers;
        $this->postFields = $postFields;
        $this->setOptions($options);
    }

    private function validateUrl(string $url): void
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RequestException('Invalid URL provided');
        }
    }

    private function validateMethod(string $method): void
    {
        $validMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
        if (!in_array(strtoupper($method), $validMethods)) {
            throw new RequestException('Invalid HTTP method');
        }
    }

    public function setOptions(array $options): void
    {
        foreach ($options as $option => $value) {
            if (!is_int($option)) {
                continue;
            }
            $this->options[$option] = $value;
        }
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getPostFields(): ?string
    {
        return $this->postFields;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        if ($maxAttempts < 1) {
            throw new RequestException('Max attempts must be at least 1');
        }
        $this->maxAttempts = $maxAttempts;
    }

    public function setRetryDelay(int $retryDelay): void
    {
        if ($retryDelay < 0) {
            throw new RequestException('Retry delay must be non-negative');
        }
        $this->retryDelay = $retryDelay;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function shouldRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function setCallback(callable $callback): void
    {
        $this->callback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
    }

    public function getCallback(): ?Closure
    {
        return $this->callback;
    }

    public function setStreamResponse(?StreamInterface $stream): void
    {
        $this->streamResponse = $stream;
    }

    public function getStreamResponse(): ?StreamInterface
    {
        return $this->streamResponse;
    }

    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
        return $this;
    }

    public function onStart(callable $callback): self
    {
        $this->startCallback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
        return $this;
    }

    public function onFinish(callable $callback): self
    {
        $this->finishCallback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
        return $this;
    }

    public function triggerProgress($dlTotal, $dlNow, $ulTotal, $ulNow): void
    {
        if ($this->progressCallback) {
            ($this->progressCallback)($dlTotal, $dlNow, $ulTotal, $ulNow);
        }
    }

    public function triggerStart(): void
    {
        if ($this->startCallback) {
            ($this->startCallback)();
        }
    }

    public function triggerFinish(): void
    {
        if ($this->finishCallback) {
            ($this->finishCallback)();
        }
    }
}
