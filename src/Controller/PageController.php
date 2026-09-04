<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

final class PageController extends AbstractController
{
    public function backups(): string
    {
        return $this->placeholder('Backups', 'Backup jobs will be created and scheduled here.');
    }


    public function snapshots(): string
    {
        return $this->placeholder('Snapshots', 'Snapshots and restore actions will be available here.');
    }

    public function settings(): string
    {
        return $this->placeholder('Settings', 'Application, security and maintenance settings will live here.');
    }

    private function placeholder(string $title, string $description): string
    {
        return $this->render('pages/placeholder.twig', [
            'title' => $title,
            'description' => $description,
        ]);
    }
}
