<?php

namespace App\Services\AiAgent;

use Symfony\Component\Process\Process;

class TestRunnerGuard
{
    private const SANDBOX_TENANT = 'tenant_agent_sandbox';

    public function run(?string $filter = null): string
    {
        // ВАЖНО: вызываем vendor/bin/phpunit НАПРЯМУЮ, а не через
        // "php artisan test". Обёртка artisan test сама подставляет
        // свой --configuration под капотом, и повторное указание флага
        // конфликтует с ней ("Option --configuration cannot be used
        // more than once") — обходим это, не используя обёртку вовсе.
        $command = [
            base_path('vendor/bin/phpunit'),
            '--configuration=' . base_path('phpunit.tenant.xml'),
        ];

        if ($filter) {
            $command[] = '--filter=' . $filter;
        }

        $process = new Process($command, base_path(), [
            'AI_AGENT_SANDBOX_TENANT' => self::SANDBOX_TENANT,
        ]);
        $process->setTimeout(300);
        $process->run();

        return $process->getOutput() . $process->getErrorOutput();
    }
}