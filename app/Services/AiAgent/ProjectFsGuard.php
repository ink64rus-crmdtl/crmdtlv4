<?php

namespace App\Services\AiAgent;

use App\Exceptions\AgentSecurityViolation;

class ProjectFsGuard
{
    private string $root;

    private array $denylistPatterns = [
        '.env',
        '.env.*',
        'config/database.php',
        'storage/oauth-*.key',
        '*.sqlite',
        'database/migrations/*',
        'app/Models/Scopes/*',
        'app/Http/Middleware/*',
        'storage/app/tenant*/*',
    ];

    public function __construct()
    {
        $this->root = realpath(base_path());
    }

    public function read(string $relativePath): string
    {
        $full = $this->resolveAndAssertInsideRoot($relativePath);
        $this->assertNotDenylisted($relativePath);
        return file_get_contents($full);
    }

    public function proposeDiff(string $relativePath, string $newContent, string $reasoning): array
    {
        $this->resolveAndAssertInsideRoot($relativePath);
        $this->assertNotDenylisted($relativePath);

        $patchId = (string) \Illuminate\Support\Str::uuid();
        $patchPath = storage_path("ai-agent/pending/{$patchId}.json");

        file_put_contents($patchPath, json_encode([
            'relative_path' => $relativePath,
            'new_content' => $newContent,
            'reasoning' => $reasoning,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['patch_id' => $patchId, 'staged_path' => $patchPath];
    }

    private function resolveAndAssertInsideRoot(string $relativePath): string
    {
        $full = realpath($this->root . '/' . $relativePath);
        if ($full === false || !str_starts_with($full, $this->root)) {
            throw new AgentSecurityViolation("Path traversal or out-of-root access attempt: {$relativePath}");
        }
        return $full;
    }

    private function assertNotDenylisted(string $relativePath): void
    {
        foreach ($this->denylistPatterns as $pattern) {
            if (\Illuminate\Support\Str::is($pattern, $relativePath)) {
                throw new AgentSecurityViolation("Access denied by denylist: {$relativePath}");
            }
        }
    }
}