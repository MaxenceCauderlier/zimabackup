<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use JsonException;
use RuntimeException;

/**
 * Minimal client for the local ZimaOS APIs used during native application restore.
 *
 * Authentication still uses the current v1 users login endpoint because the public
 * v2 user API does not expose a login route. Application lifecycle operations use
 * App Management API v2 exclusively.
 */
final class ZimaOsApiClient
{
    public function __construct(private readonly string $baseUrl = '')
    {
    }

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '';
    }

    public function baseUrl(): string
    {
        return rtrim(trim($this->baseUrl), '/');
    }

    /**
     * Lightweight worker-side connectivity probe. No credentials are sent and
     * no authenticated API route is called. It only verifies DNS/host mapping
     * and that a TCP connection can be opened to the configured ZimaOS API.
     *
     * @return array{configured:bool,reachable:bool,base_url:string,host:?string,port:?int,resolved_ip:?string,error:?string}
     */
    public function probe(): array
    {
        $baseUrl = $this->baseUrl();
        if ($baseUrl === '') {
            return [
                'configured' => false,
                'reachable' => false,
                'base_url' => '',
                'host' => null,
                'port' => null,
                'resolved_ip' => null,
                'error' => 'Native ZimaOS restore is not configured on this installation.',
            ];
        }

        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? 'http')) : 'http';
        $port = is_array($parts) && isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if ($host === '') {
            return [
                'configured' => true,
                'reachable' => false,
                'base_url' => $baseUrl,
                'host' => null,
                'port' => $port,
                'resolved_ip' => null,
                'error' => 'The configured ZimaOS API URL has no valid host.',
            ];
        }

        $resolvedIp = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
        if ($resolvedIp === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return [
                'configured' => true,
                'reachable' => false,
                'base_url' => $baseUrl,
                'host' => $host,
                'port' => $port,
                'resolved_ip' => null,
                'error' => 'The local ZimaOS API host cannot be resolved from the worker.',
            ];
        }

        $errno = 0;
        $error = '';
        $socket = @fsockopen($host, $port, $errno, $error, 2.0);
        if (is_resource($socket)) {
            fclose($socket);
            return [
                'configured' => true,
                'reachable' => true,
                'base_url' => $baseUrl,
                'host' => $host,
                'port' => $port,
                'resolved_ip' => $resolvedIp,
                'error' => null,
            ];
        }

        return [
            'configured' => true,
            'reachable' => false,
            'base_url' => $baseUrl,
            'host' => $host,
            'port' => $port,
            'resolved_ip' => $resolvedIp,
            'error' => $error !== ''
                ? sprintf('The local ZimaOS API is not reachable from the worker: %s', $error)
                : 'The local ZimaOS API is not reachable from the worker.',
        ];
    }

    public function login(string $username, string $password): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Native ZimaOS restore is not configured on this installation.');
        }
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new RuntimeException('ZimaOS username and password are required.');
        }

        $body = json_encode(
            ['username' => $username, 'password' => $password],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        [$status, $raw] = $this->request('POST', '/v1/users/login', [
            'Content-Type: application/json',
            'Accept: application/json',
        ], $body, 20);

        $data = $this->decodeJson($raw);
        $success = (int) ($data['success'] ?? 0);
        $token = trim((string) ($data['data']['token']['access_token'] ?? ''));
        if ($status < 200 || $status >= 300 || $success !== 200 || $token === '') {
            throw new RuntimeException('ZimaOS authentication failed. Check the username and password.');
        }
        return $token;
    }

    public function validateCompose(string $token, string $composeYaml): void
    {
        [$status, $raw] = $this->request(
            'POST',
            '/v2/app_management/compose?dry_run=true&check_port_conflict=false&uncontrolled=false',
            $this->authHeaders($token, true),
            $composeYaml,
            60
        );
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('ZimaOS rejected the restored application definition during validation: ' . $this->safeApiMessage($raw, $status));
        }
    }

    public function installCompose(string $token, string $composeYaml): void
    {
        [$status, $raw] = $this->request(
            'POST',
            '/v2/app_management/compose?check_port_conflict=true&uncontrolled=false',
            $this->authHeaders($token, true),
            $composeYaml,
            900
        );
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('ZimaOS could not install the restored application: ' . $this->safeApiMessage($raw, $status));
        }
    }

    /** @return array<string,mixed> */
    public function getComposeApp(string $token, string $projectName): array
    {
        [$status, $raw] = $this->request(
            'GET',
            '/v2/app_management/compose/' . rawurlencode($projectName),
            $this->authHeaders($token, false),
            '',
            30
        );
        if ($status === 404) {
            throw new RuntimeException(sprintf('ZimaOS did not register the restored application project %s.', $projectName), 404);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Unable to verify the restored application in ZimaOS: ' . $this->safeApiMessage($raw, $status));
        }
        return $this->decodeJson($raw);
    }

    /** @return array<string,mixed> */
    public function waitForComposeApp(string $token, string $projectName, int $timeoutSeconds = 60): array
    {
        $deadline = time() + max(1, $timeoutSeconds);
        do {
            try {
                return $this->getComposeApp($token, $projectName);
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }
            }
            usleep(2_000_000);
        } while (time() < $deadline);

        throw new RuntimeException(sprintf('ZimaOS did not register the restored application project %s before the verification timeout.', $projectName));
    }

    public function appExists(string $token, string $projectName): bool
    {
        [$status] = $this->request(
            'GET',
            '/v2/app_management/compose/' . rawurlencode($projectName),
            $this->authHeaders($token, false),
            '',
            20
        );
        if ($status === 404) {
            return false;
        }
        if ($status >= 200 && $status < 300) {
            return true;
        }
        throw new RuntimeException(sprintf('Unable to check whether %s is already registered in ZimaOS (HTTP %d).', $projectName, $status));
    }

    /** @return list<string> */
    private function authHeaders(string $token, bool $yaml): array
    {
        $headers = [
            'Authorization: ' . trim($token),
            'Accept: application/json',
        ];
        if ($yaml) {
            $headers[] = 'Content-Type: application/yaml';
        }
        return $headers;
    }

    /** @return array{0:int,1:string} */
    private function request(string $method, string $path, array $headers, string $body = '', int $timeout = 30): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Native ZimaOS restore is not configured on this installation.');
        }
        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        $headerLines = array_merge($headers, [
            'Connection: close',
            'Content-Length: ' . strlen($body),
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        if ($raw === false && $responseHeaders === []) {
            $error = error_get_last();
            $message = (string) ($error['message'] ?? '');
            if (str_contains($message, 'getaddrinfo') || str_contains($message, 'php_network_getaddresses')) {
                throw new RuntimeException('The local ZimaOS API host cannot be resolved from the worker. Recreate the ZimaBackup containers so host.docker.internal is mapped to Docker host-gateway.');
            }
            if (stripos($message, 'Connection refused') !== false) {
                throw new RuntimeException('The local ZimaOS API refused the connection. Check that ZimaOS services are running and that ZIMAOS_API_BASE_URL points to the host API.');
            }
            if (stripos($message, 'timed out') !== false) {
                throw new RuntimeException('The local ZimaOS API connection timed out. Check the Docker host-gateway mapping and ZimaOS services.');
            }
            throw new RuntimeException('Unable to reach the local ZimaOS API from the worker. Check the ZimaOS API address and Docker host-gateway mapping.');
        }
        $status = 0;
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $match) === 1) {
            $status = (int) $match[1];
        }
        if ($status === 0) {
            throw new RuntimeException('The local ZimaOS API returned an invalid HTTP response.');
        }
        return [$status, is_string($raw) ? $raw : ''];
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('ZimaOS returned invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('ZimaOS returned an unexpected response.');
        }
        return $decoded;
    }

    private function safeApiMessage(string $raw, int $status): string
    {
        if (trim($raw) !== '') {
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    $message = trim((string) ($data['message'] ?? ''));
                    if ($message !== '') {
                        return substr($message, 0, 1000);
                    }
                }
            } catch (JsonException) {
                // Do not expose arbitrary bodies, as Compose responses may contain secrets.
            }
        }
        return 'HTTP ' . $status;
    }
}
