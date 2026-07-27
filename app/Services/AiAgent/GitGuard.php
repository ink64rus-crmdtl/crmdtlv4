<?php

namespace App\Services\AiAgent;

use Symfony\Component\Process\Process;

class GitGuard
{
    private string $repoRoot;

    public function __construct()
    {
        $this->repoRoot = base_path();
    }

    public function createFeatureBranch(string $taskSlug): string
    {
        $branch = 'feature/agent-' . \Illuminate\Support\Str::slug($taskSlug);

        $this->run(['git', 'fetch', 'origin', 'main']);
        $this->run(['git', 'checkout', '-b', $branch, 'origin/main']);

        return $branch;
    }

    public function openPullRequest(string $title, string $description): string
    {
        // Требует настроенного GitHub CLI (шаг 4.3) с токеном ограниченных прав
        $result = $this->run([
            'gh', 'pr', 'create',
            '--title', $title,
            '--body', $description,
            '--base', 'main',
        ]);

        return trim($result);
    }

    private function run(array $command): string
    {
        $process = new Process($command, $this->repoRoot);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Git command failed: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
    }
}