<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use JsonException;
use RuntimeException;

/**
 * Small Docker Engine API client used only by the isolated worker.
 *
 * Read operations power discovery. Mutating operations are deliberately
 * limited to the install workflow: pull images, create/remove networks,
 * create/start/stop/remove containers.
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
        $data = $this->requestJson('GET', '/containers/json?all=' . ($all ? '1' : '0'));
        return is_array($data) ? array_values($data) : [];
    }

    /** @return array<string, mixed> */
    public function inspectContainer(string $containerId): array
    {
        $data = $this->requestJson('GET', '/containers/' . rawurlencode($containerId) . '/json');
        if (!is_array($data)) {
            throw new RuntimeException('Docker returned an invalid container inspection response.');
        }
        return $data;
    }

    /** @return array<string, mixed> */
    public function version(): array
    {
        $data = $this->requestJson('GET', '/version', null, false);
        if (!is_array($data)) {
            throw new RuntimeException('Docker returned an invalid version response.');
        }
        return $data;
    }

    public function imageExists(string $image): bool
    {
        try {
            $this->requestJson('GET', '/images/' . rawurlencode($image) . '/json');
            return true;
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 404) {
                return false;
            }
            throw $exception;
        }
    }

    public function pullImage(string $image): void
    {
        $response = $this->requestRaw(
            'POST',
            '/images/create?fromImage=' . rawurlencode($image),
            '',
            true,
            900
        );

        foreach (preg_split('/\r?\n/', trim($response)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (is_array($message) && isset($message['error'])) {
                throw new RuntimeException('Docker image pull failed: ' . (string) $message['error']);
            }
        }
    }

    public function networkExists(string $name): bool
    {
        try {
            $this->requestJson('GET', '/networks/' . rawurlencode($name));
            return true;
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 404) {
                return false;
            }
            throw $exception;
        }
    }

    public function createNetwork(string $name, array $labels = []): string
    {
        $data = $this->requestJson('POST', '/networks/create', [
            'Name' => $name,
            'Driver' => 'bridge',
            'CheckDuplicate' => true,
            'Labels' => $labels,
        ]);

        if (!is_array($data) || trim((string) ($data['Id'] ?? '')) === '') {
            throw new RuntimeException(sprintf('Docker did not return an id for network %s.', $name));
        }
        return (string) $data['Id'];
    }

    public function removeNetwork(string $networkId): void
    {
        try {
            $this->requestRaw('DELETE', '/networks/' . rawurlencode($networkId));
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        }
    }

    /** @param array<string, mixed> $configuration */
    public function createContainer(string $name, array $configuration): string
    {
        $data = $this->requestJson(
            'POST',
            '/containers/create?name=' . rawurlencode($name),
            $configuration
        );
        if (!is_array($data) || trim((string) ($data['Id'] ?? '')) === '') {
            throw new RuntimeException(sprintf('Docker did not return an id for container %s.', $name));
        }
        return (string) $data['Id'];
    }

    public function startContainer(string $containerId): void
    {
        $this->requestRaw('POST', '/containers/' . rawurlencode($containerId) . '/start', '', true, 60, [204, 304]);
    }

    public function stopContainer(string $containerId, int $timeoutSeconds = 10): void
    {
        try {
            $this->requestRaw(
                'POST',
                '/containers/' . rawurlencode($containerId) . '/stop?t=' . max(0, $timeoutSeconds),
                '',
                true,
                max(30, $timeoutSeconds + 10),
                [204, 304]
            );
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        }
    }

    public function removeContainer(string $containerId, bool $force = true): void
    {
        try {
            $this->requestRaw(
                'DELETE',
                '/containers/' . rawurlencode($containerId) . '?force=' . ($force ? '1' : '0') . '&v=0',
                '',
                true,
                60,
                [204]
            );
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        }
    }

    private function requestJson(string $method, string $path, ?array $payload = null, bool $versioned = true): mixed
    {
        $body = $payload === null
            ? ''
            : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $raw = $this->requestRaw($method, $path, $body, $versioned);
        if (trim($raw) === '') {
            return null;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Docker Engine returned invalid JSON.', 0, $exception);
        }
    }

    /**
     * @param list<int>|null $acceptedStatuses
     */
    private function requestRaw(
        string $method,
        string $path,
        string $body = '',
        bool $versioned = true,
        int $timeout = 30,
        ?array $acceptedStatuses = null,
    ): string {
        if (!$this->isAvailable()) {
            throw new RuntimeException(sprintf('Docker socket is not available: %s', $this->socketPath));
        }

        if ($versioned && $this->apiVersion === null) {
            $version = $this->version();
            $this->apiVersion = isset($version['ApiVersion']) ? (string) $version['ApiVersion'] : '';
        }

        $prefix = $versioned && $this->apiVersion !== '' ? '/v' . $this->apiVersion : '';
        $requestPath = $prefix . $path;

        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $error,
            5,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Unable to connect to Docker Engine: %s', $error ?: 'unknown socket error'));
        }

        stream_set_timeout($stream, $timeout);
        $headers = [
            sprintf('%s %s HTTP/1.1', strtoupper($method), $requestPath),
            'Host: docker',
            'Accept: application/json',
            'Connection: close',
            'Content-Length: ' . strlen($body),
        ];
        if ($body !== '') {
            $headers[] = 'Content-Type: application/json';
        }
        $request = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        try {
            if (fwrite($stream, $request) === false) {
                throw new RuntimeException('Unable to write the Docker Engine request.');
            }
            $response = stream_get_contents($stream);
            $meta = stream_get_meta_data($stream);
            if (($meta['timed_out'] ?? false) === true) {
                throw new RuntimeException('Docker Engine request timed out.');
            }
        } finally {
            fclose($stream);
        }

        if ($response === false || $response === '') {
            throw new RuntimeException('Docker Engine returned an empty response.');
        }

        [$headerBlock, $responseBody] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $headerLines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($headerLines) ?: '';
        if (!preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', $statusLine, $matches)) {
            throw new RuntimeException('Docker Engine returned a malformed HTTP response.');
        }
        $status = (int) $matches[1];

        $responseHeaders = [];
        foreach ($headerLines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
        if (strtolower($responseHeaders['transfer-encoding'] ?? '') === 'chunked') {
            $responseBody = $this->decodeChunkedBody($responseBody);
        }

        $okStatuses = $acceptedStatuses ?? range(200, 299);
        if (!in_array($status, $okStatuses, true)) {
            $message = sprintf('Docker Engine request failed with HTTP %d.', $status);
            if (trim($responseBody) !== '') {
                try {
                    $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded) && isset($decoded['message'])) {
                        $message = (string) $decoded['message'];
                    }
                } catch (JsonException) {
                    // Keep generic HTTP error when the body is not JSON.
                }
            }
            throw new RuntimeException($message, $status);
        }

        return $responseBody;
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
