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
        // Явная проверка ДО вызова gh — раньше падало прямо внутри gh с
        // невнятной для модели ошибкой "must be on a branch named
        // differently than main", если createBranch не был вызван перед
        // этим (агент может пропустить шаг в многошаговой цепочке).
        $currentBranch = trim($this->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']));

        if ($currentBranch === 'main' || $currentBranch === 'master') {
            throw new \RuntimeException(
                "Нельзя открыть PR находясь на ветке '{$currentBranch}'. " .
                'Сначала вызови Tool createBranch, чтобы создать отдельную feature-ветку, ' .
                'и только после этого — createPullRequest.'
            );
        }

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
