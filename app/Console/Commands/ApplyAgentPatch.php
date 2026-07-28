<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Человек-ревьюер применяет staged-patch, предложенный CrmDevAgent, ПОСЛЕ
 * того как глазами проверил diff. Это НЕ Tool агента — сознательно, раздел
 * 16.5/16.6 системной инструкции: между "предложить изменение" и "закоммитить
 * его" должен стоять человек, а не автоматика. Именно этот шаг поймал бы
 * (и ловит) случай, когда модель попыталась заменить весь routes/tenant.php
 * четырьмя строками вместо добавления одного маршрута.
 *
 * Использование:
 *   php artisan agent:apply-patch {patch_id}       — показать diff и применить после подтверждения
 *   php artisan agent:apply-patch {patch_id} --show — только показать, не применять
 */
class ApplyAgentPatch extends Command
{
    protected $signature = 'agent:apply-patch {patch_id} {--show : Только показать diff, не применять}';
    protected $description = 'Просмотреть и применить staged-patch, предложенный CrmDevAgent (writeDiff)';

    public function handle(): int
    {
        $patchId = $this->argument('patch_id');
        $patchPath = storage_path("ai-agent/pending/{$patchId}.json");

        if (! file_exists($patchPath)) {
            $this->error("Patch {$patchId} не найден в storage/ai-agent/pending/");
            return self::FAILURE;
        }

        $patch = json_decode(file_get_contents($patchPath), true);
        $relativePath = $patch['relative_path'];
        $newContent = $patch['new_content'];
        $reasoning = $patch['reasoning'];

        $fullPath = base_path($relativePath);
        $oldContent = file_exists($fullPath) ? file_get_contents($fullPath) : '';

        $this->info("Patch: {$patchId}");
        $this->info("Файл: {$relativePath}");
        $this->info("Обоснование агента: {$reasoning}");
        $this->newLine();

        // Явное сравнение размеров — простая, но полезная защита: если новый
        // файл СИЛЬНО меньше старого, это красный флаг возможной случайной
        // перезаписи (тот самый инцидент с routes/tenant.php).
        $oldLines = substr_count($oldContent, "\n");
        $newLines = substr_count($newContent, "\n");

        if ($oldLines > 0 && $newLines < (int) ($oldLines * 0.5)) {
            $this->warn("⚠️  ВНИМАНИЕ: новый файл заметно короче старого ({$oldLines} → {$newLines} строк).");
            $this->warn('Это может быть случайная замена всего файла вместо добавления кода. Проверь diff внимательно ниже.');
            $this->newLine();
        }

        $this->line('--- ТЕКУЩЕЕ СОДЕРЖИМОЕ ---');
        $this->line($oldContent ?: '(файл не существует)');
        $this->newLine();
        $this->line('--- ПРЕДЛАГАЕМОЕ СОДЕРЖИМОЕ ---');
        $this->line($newContent);
        $this->newLine();

        if ($this->option('show')) {
            return self::SUCCESS;
        }

        if (! $this->confirm('Применить это изменение и закоммитить в текущую ветку?', false)) {
            $this->info('Отменено.');
            return self::SUCCESS;
        }

        file_put_contents($fullPath, $newContent);

        $this->runGit(['git', 'add', $relativePath]);
        $this->runGit(['git', 'commit', '-m', "agent: {$reasoning}"]);

        // Patch применён — можно убрать staged-файл, чтобы не путаться в будущем.
        unlink($patchPath);

        $this->info('Изменение применено и закоммичено. Теперь можно вызвать createPullRequest у агента.');
        return self::SUCCESS;
    }

    private function runGit(array $command): void
    {
        $process = new Process($command, base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Git command failed: ' . $process->getErrorOutput());
        }
    }
}