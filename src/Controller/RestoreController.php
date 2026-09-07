<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\RestoreService;

final class RestoreController extends AbstractController
{
    public function index(): string
    {
        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);

        return $this->render('restores/index.twig', [
            'restores' => $restores->all(),
        ]);
    }

    public function create(string $runId): string
    {
        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        $snapshot = $restores->snapshotByBackupRunId((int) $runId);

        if ($snapshot === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        return $this->render('restores/create.twig', [
            'snapshot' => $snapshot,
            'values' => [
                'target_path' => $restores->defaultTarget($snapshot),
            ],
            'errors' => [],
        ]);
    }

    public function store(string $runId): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        $snapshot = $restores->snapshotByBackupRunId((int) $runId);
        if ($snapshot === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        $values = [
            'target_path' => trim((string) ($_POST['target_path'] ?? '')),
        ];

        try {
            $restore = $restores->enqueue((int) $runId, $values['target_path']);
            return $this->redirect('restores.show', ['uuid' => $restore['uuid']]);
        } catch (InvalidArgumentException $exception) {
            return $this->render('restores/create.twig', [
                'snapshot' => $snapshot,
                'values' => $values,
                'errors' => [$exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            return $this->render('restores/create.twig', [
                'snapshot' => $snapshot,
                'values' => $values,
                'errors' => ['Unable to queue restore: ' . $exception->getMessage()],
            ]);
        }
    }

    public function show(string $uuid): string
    {
        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        $restore = $restores->findByUuid($uuid);

        if ($restore === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        return $this->render('restores/show.twig', [
            'restore' => $restore,
        ]);
    }
}
