<?php

namespace App\AiAgents;

use App\Exceptions\AgentSecurityViolation;
use App\Services\AiAgent\ArchitectEscalationService;
use App\Services\AiAgent\GitGuard;
use App\Services\AiAgent\ProjectFsGuard;
use App\Services\AiAgent\TestRunnerGuard;
use LarAgent\Agent;
use LarAgent\Attributes\Tool;

class CrmDevAgent extends Agent
{
    // Совпадает с 'model' в config/laragent.php -> providers.local_default,
    // но явно продублировано здесь для читаемости и на случай override.
    protected $model = 'qwen3:8b';

    // Имя провайдера из config/laragent.php (раздел 16.3 системной инструкции)
    protected $provider = 'local_default';

    // Персистентная история диалога в файле — удобно дебажить шаги агента
    protected $history = 'json';

    // Явно пусто: инструменты этого агента заведены через #[Tool] на методах
    // ниже (attribute-based tools), а не через отдельные Tool-классы.
    protected $tools = [];

    public function instructions()
    {
        return <<<PROMPT
Ты — агент-исполнитель в мультитенантной SaaS CRM (database-per-tenant, Laravel + Vue + Inertia).
ТЫ НЕ АРХИТЕКТОР. Ты реализуешь ТОЛЬКО то, что явно описано в переданной тебе спецификации задачи.

ЖЁСТКИЕ ПРАВИЛА:
1. Никогда не пиши и не изменяй код, работающий с центральной (landlord) БД,
   если это explicitно не указано в задаче.
2. Любая модель/запрос к бизнес-данным ОБЯЗАНА уважать tenant-контекст и, где применимо,
   BranchScope. Если не уверен, как именно — не изобретай, а верни вопрос через Tool ask_architect.
3. Никогда не удаляй и не переименовывай существующие колонки системных таблиц.
4. Денежные суммы — integer в минимальных единицах валюты, никогда float.
5. Ты работаешь ТОЛЬКО через Tools ниже. Прямой вывод "просто примени этот код" без
   вызова write_diff не считается выполнением задачи.
6. Результат — всегда diff/PR через create_pull_request, никогда прямой push в main/production.
7. При написании тестов используй атрибут #[Test] из PHPUnit\Framework\Attributes\Test
   или именование метода с префиксом test — doc-comment @test больше не поддерживается
   в PHPUnit 12, который используется в этом проекте.
PROMPT;
    }

    public function prompt($message)
    {
        return $message;
    }

    #[Tool('Прочитать содержимое файла в пределах репозитория проекта')]
    public function readFile(string $relativePath): string
    {
        try {
            return app(ProjectFsGuard::class)->read($relativePath);
        } catch (AgentSecurityViolation $e) {
            return 'ОШИБКА ДОСТУПА: ' . $e->getMessage();
        }
    }

    #[Tool('Создать git-ветку feature/agent-* от актуального main для новой задачи')]
    public function createBranch(string $taskSlug): string
    {
        return app(GitGuard::class)->createFeatureBranch($taskSlug);
    }

    #[Tool('Записать предложенное изменение как staged-patch, не применяя его напрямую к рабочей копии main')]
    public function writeDiff(string $relativePath, string $newContent, string $reasoning): string
    {
        try {
            $result = app(ProjectFsGuard::class)->proposeDiff($relativePath, $newContent, $reasoning);
            return 'Изменение сохранено как patch: ' . $result['patch_id'];
        } catch (AgentSecurityViolation $e) {
            return 'ОШИБКА ДОСТУПА: ' . $e->getMessage();
        }
    }

    #[Tool('Запустить тесты проекта на sandbox-тенанте (никогда не на проде)')]
    public function runTests(?string $filter = null): string
    {
        return app(TestRunnerGuard::class)->run($filter);
    }

    #[Tool('Открыть Pull Request с накопленными diff текущей задачи для ревью человеком')]
    public function createPullRequest(string $title, string $description): string
    {
        return app(GitGuard::class)->openPullRequest($title, $description);
    }

    #[Tool('Задать архитектору уточняющий вопрос вместо предположения молча')]
    public function askArchitect(string $question): string
    {
        return app(ArchitectEscalationService::class)->log($question);
    }
}
