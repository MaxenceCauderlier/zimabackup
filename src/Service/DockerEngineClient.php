<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use JsonException;
use RuntimeException;

/**
 * Minimal read-only Docker Engine API client.
 *
 * ZimaBackup intentionally implements only GET requests. The Docker socket still
 * grants powerful daemon access at the operating-system level, so it is mounted
 * only in the isolated worker container and never in the web container.
 */
final class DockerEngineClient
{
    private ?string $apiVersion = null;

    public function __construct(private readonly string $socketPath = '/var/run/docker.sock')
    {
    }

    public function isAvailable(): bool
    {
        return file_exists($this->socketPath);
    }

    /** @return list<array<string, mixed>> */
    public function containers(bool $all = true): array
    {
        $data = $this->get('/containers/json?all=' . ($all ? '1' : '0'));
        return is_array($data) ? array_values($data) : [];
    }

    /** @return array<string, mixed> */
    public function inspectContainer(string $containerId): array
    {
        $data = $this->get('/containers/' . rawurlencode($containerId) . '/json');
        if (!is_array($data)) {
            throw new RuntimeException('Docker returned an invalid container inspection response.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function version(): array
    {
        $data = $this->rawGet('/version');
        if (!is_array($data)) {
            throw new RuntimeException('Docker returned an invalid version response.');
        }

        return $data;
    }

    private function get(string $path): mixed
    {
        if ($this->apiVersion === null) {
            $version = $this->version();
            $this->apiVersion = isset($version['ApiVersion']) ? (string) $version['ApiVersion'] : '';
        }

        $prefix = $this->apiVersion !== '' ? '/v' . $this->apiVersion : '';
        return $this->rawGet($prefix . $path);
    }

    private function rawGet(string $path): mixed
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException(sprintf('Docker socket is not available: %s', $this->socketPath));
        }

        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $error,
            4,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Unable to connect to Docker Engine: %s', $error ?: 'unknown socket error'));
        }

        stream_set_timeout($stream, 10);
        $request = "GET {$path} HTTP/1.1\r\nHost: docker\r\nAccept: application/json\r\nConnection: close\r\n\r\n";

        try {
            if (fwrite($stream, $request) === false) {
                throw new RuntimeException('Unable to write the Docker Engine request.');
            }

            $response = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if ($response === false || $response === '') {
            throw new RuntimeException('Docker Engine returned an empty response.');
        }

        [$headerBlock, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $headerLines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($headerLines) ?: '';

        if (!preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', $statusLine, $matches)) {
            throw new RuntimeException('Docker Engine returned a malformed HTTP response.');
        }

        $status = (int) $matches[1];
        $headers = [];
        foreach ($headerLines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        if (strtolower($headers['transfer-encoding'] ?? '') === 'chunked') {
            $body = $this->decodeChunkedBody($body);
        }

        $decoded = null;
        if (trim($body) !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Docker Engine returned invalid JSON.', 0, $exception);
            }
        }

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : sprintf('Docker Engine request failed with HTTP %d.', $status);
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function decodeChunkedBody(string $body): string
    {
        $decoded = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $lineEnd = strpos($body, "\r\n", $offset);
            if ($lineEnd === false) {
                break;
            }

            $sizeLine = trim(substr($body, $offset, $lineEnd - $offset));
            $semicolon = strpos($sizeLine, ';');
            if ($semicolon !== false) {
                $sizeLine = substr($sizeLine, 0, $semicolon);
            }

            if ($sizeLine === '' || !ctype_xdigit($sizeLine)) {
                throw new RuntimeException('Docker Engine returned malformed chunked data.');
            }

            $size = hexdec($sizeLine);
            $offset = $lineEnd + 2;
            if ($size === 0) {
                break;
            }

            if ($offset + $size > $length) {
                throw new RuntimeException('Docker Engine returned truncated chunked data.');
            }

            $decoded .= substr($body, $offset, $size);
            $offset += $size + 2;
        }

        return $decoded;
    }
}
