<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use ZimaBackup\Service\ActivityService;
use ZimaBackup\Service\DiagnosticsService;

final class ActivityController extends AbstractController
{
    public function index(): string
    {
        /** @var ActivityService $activity */
        $activity = $this->app->service(ActivityService::class);
        /** @var DiagnosticsService $diagnostics */
        $diagnostics = $this->app->service(DiagnosticsService::class);

        $severity = isset($_GET['severity']) && $_GET['severity'] !== '' ? (string) $_GET['severity'] : null;
        $category = isset($_GET['category']) && $_GET['category'] !== '' ? (string) $_GET['category'] : null;

        return $this->render('activity/index.twig', [
            'events' => $activity->list($severity, $category),
            'counts' => $activity->counts(),
            'categories' => $activity->categories(),
            'selected_severity' => $severity,
            'selected_category' => $category,
            'diagnostics' => $diagnostics->summary(),
        ]);
    }
}
