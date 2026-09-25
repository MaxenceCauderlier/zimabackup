<?php

declare(strict_types=1);

namespace ZimaBackup\Core;

use AltoRouter;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\ApplicationDiscoveryService;
use ZimaBackup\Service\ApplicationInstallService;
use ZimaBackup\Service\ApplicationRestoreService;
use ZimaBackup\Service\ComposePreviewService;
use ZimaBackup\Service\BackupService;
use ZimaBackup\Service\DockerEngineClient;
use ZimaBackup\Service\PathService;
use ZimaBackup\Service\RepositoryService;
use ZimaBackup\Service\RetentionService;
use ZimaBackup\Service\RestoreService;
use ZimaBackup\Service\ResticService;
use ZimaBackup\Service\SchedulerService;
use ZimaBackup\Service\SettingsService;
use ZimaBackup\Service\SnapshotService;
use ZimaBackup\Service\SnapshotApplicationService;
use ZimaBackup\Service\TaskQueueService;

final class Application
{
    private AltoRouter $router;
    private Environment $twig;
    private Database $database;
    private array $config;
    private array $services = [];

    private function __construct(private readonly string $rootPath)
    {
        $this->config = require $this->rootPath . '/config/app.php';
        date_default_timezone_set($this->config['timezone']);

        $this->database = new Database($this->rootPath . '/storage/database.sqlite');
        $this->database->migrate($this->rootPath . '/database/migrations');

        $this->router = new AltoRouter();

        $loader = new FilesystemLoader($this->rootPath . '/templates');
        $cache = $this->config['env'] === 'prod'
            ? $this->rootPath . '/storage/cache/twig'
            : false;

        $this->twig = new Environment($loader, [
            'cache' => $cache,
            'debug' => $this->config['debug'],
            'auto_reload' => $this->config['debug'],
        ]);

        $this->twig->addGlobal('APP', $this->config);
        $this->twig->addFilter(new TwigFilter('bytes', static function (mixed $bytes): string {
            if ($bytes === null || $bytes === '') {
                return '—';
            }
            $value = max(0, (float) $bytes);
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $index = 0;
            while ($value >= 1024 && $index < count($units) - 1) {
                $value /= 1024;
                $index++;
            }
            $precision = $index === 0 ? 0 : ($value >= 100 ? 0 : 1);
            return number_format($value, $precision) . ' ' . $units[$index];
        }));
        $this->twig->addGlobal('ROUTER', $this->router);
        $this->twig->addGlobal(
            'CURRENT_PATH',
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'
        );

        $pathConfig = require $this->rootPath . '/config/paths.php';
        $pathService = new PathService($pathConfig['container_roots']);
        $resticService = new ResticService('restic');
        $session = new Session();
        $csrf = new Csrf($session);
        $queue = new TaskQueueService($this->database);
        $settings = new SettingsService($this->database, $pathService);
        $scheduler = new SchedulerService($this->database);
        $docker = new DockerEngineClient(getenv('DOCKER_SOCKET') ?: '/var/run/docker.sock');
        $composePreview = new ComposePreviewService();
        $appDiscovery = new ApplicationDiscoveryService(
            $this->database,
            $docker,
            $queue,
            $this->rootPath . '/storage/manifests',
            getenv('ZIMABACKUP_COMPOSE_PROJECT') ?: 'zimabackup'
        );

        $this->services = [
            Database::class => $this->database,
            PathService::class => $pathService,
            ResticService::class => $resticService,
            Session::class => $session,
            Csrf::class => $csrf,
            TaskQueueService::class => $queue,
            SettingsService::class => $settings,
            SchedulerService::class => $scheduler,
            DockerEngineClient::class => $docker,
            ApplicationDiscoveryService::class => $appDiscovery,
            ComposePreviewService::class => $composePreview,
        ];

        $this->services[BackupService::class] = new BackupService(
            $this->database,
            $pathService,
            $resticService,
            $queue,
            $appDiscovery,
            $scheduler
        );

        $this->services[RepositoryService::class] = new RepositoryService(
            $this->database,
            $pathService,
            $resticService,
            $queue,
            $this->rootPath . '/storage/secrets/repositories'
        );
        $this->services[RestoreService::class] = new RestoreService(
            $this->database,
            $pathService,
            $resticService,
            $queue,
            $settings
        );
        $this->services[SnapshotApplicationService::class] = new SnapshotApplicationService(
            $this->database,
            $pathService,
            $resticService,
            $queue,
            $composePreview
        );
        $this->services[ApplicationRestoreService::class] = new ApplicationRestoreService(
            $this->database,
            $pathService,
            $resticService,
            $queue,
            $composePreview,
            $docker,
            $settings
        );
        $this->services[ApplicationInstallService::class] = new ApplicationInstallService(
            $this->database,
            $pathService,
            $docker,
            $queue
        );
        $this->services[SnapshotService::class] = new SnapshotService(
            $this->database,
            $pathService,
            $resticService,
            $queue
        );
        $this->services[RetentionService::class] = new RetentionService(
            $this->database,
            $pathService,
            $resticService,
            $queue
        );
    }

    public static function create(string $rootPath): self
    {
        return new self(rtrim($rootPath, '/'));
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function router(): AltoRouter
    {
        return $this->router;
    }

    public function twig(): Environment
    {
        return $this->twig;
    }

    public function service(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new RuntimeException(sprintf('Service not registered: %s', $id));
        }

        return $this->services[$id];
    }

    public function run(): void
    {
        $match = $this->router->match();

        if ($match === false) {
            http_response_code(404);
            echo $this->twig->render('errors/404.twig');
            return;
        }

        $target = $match['target'];
        $params = $match['params'] ?? [];

        if (is_callable($target)) {
            $response = $target(...array_values($params));
        } elseif (is_array($target) && count($target) === 2 && is_string($target[0])) {
            [$controllerClass, $method] = $target;
            $controller = new $controllerClass($this);
            $response = $controller->{$method}(...$this->coerceControllerRouteParams($controllerClass, $method, $params));
        } else {
            throw new RuntimeException('Invalid route target.');
        }

        if (is_string($response)) {
            echo $response;
        }
    }
    /**
     * AltoRouter validates route placeholders such as [i:id], but captured
     * values are still returned as strings. With strict_types enabled, passing
     * those strings directly to controller methods typed as int triggers a
     * TypeError. Coerce builtin controller parameter types centrally so route
     * declarations and PHP method signatures can stay explicit and safe.
     */
    private function coerceControllerRouteParams(string $controllerClass, string $method, array $params): array
    {
        $values = array_values($params);
        $reflection = new \ReflectionMethod($controllerClass, $method);

        foreach ($reflection->getParameters() as $index => $parameter) {
            if (!array_key_exists($index, $values)) {
                continue;
            }

            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
                continue;
            }

            if ($values[$index] === null && $type->allowsNull()) {
                continue;
            }

            $values[$index] = match ($type->getName()) {
                'int' => (int) $values[$index],
                'float' => (float) $values[$index],
                'bool' => filter_var($values[$index], FILTER_VALIDATE_BOOL),
                'string' => (string) $values[$index],
                default => $values[$index],
            };
        }

        return $values;
    }

}
