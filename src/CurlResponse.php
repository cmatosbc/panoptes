<?php

namespace Panoptes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class CurlResponse implements ResponseInterface
{
    private $statusCode;
    private $headers;
    private StreamInterface $body;
    private $protocolVersion;

    public function __construct($body, $statusCode = 200, $headers = [], $protocolVersion = '1.1') {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->protocolVersion = $protocolVersion;
        
        if ($body instanceof StreamInterface) {
            $this->body = $body;
        } else {
            $this->body = new \Panoptes\Stream($body);
        }
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getReasonPhrase()
    {
        return '';
    }

    public function getProtocolVersion()
    {
        return $this->protocolVersion;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function hasHeader($name)
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader($name)
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine($name)
    {
        return implode(',', $this->getHeader($name));
    }

    public function withStatus($code, $reasonPhrase = '')
    {
        $new = clone $this;
        $new->statusCode = $code;
        return $new;
    }

    public function withProtocolVersion($version)
    {
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    public function withHeader($name, $value)
    {
        $new = clone $this;
        $new->headers[strtolower($name)] = (array)$value;
        return $new;
    }

    public function withAddedHeader($name, $value)
    {
        $new = clone $this;
        $name = strtolower($name);
        if (!isset($new->headers[$name])) {
            $new->headers[$name] = [];
        }
        $new->headers[$name][] = $value;
        return $new;
    }

    public function withoutHeader($name)
    {
        $new = clone $this;
        unset($new->headers[strtolower($name)]);
        return $new;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body)
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }
}