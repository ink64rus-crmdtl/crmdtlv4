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
    protected $model = 'qwen3:8b';
    protected $provider = 'local_default';
    protected $history = 'json';
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
6. При написании тестов используй атрибут #[Test] из PHPUnit\Framework\Attributes\Test
   или именование метода с префиксом test — doc-comment @test больше не поддерживается
   в PHPUnit 12, который используется в этом проекте.
7. КРИТИЧЕСКИ ВАЖНО про writeDiff: newContent — это ПОЛНОЕ новое содержимое файла
   целиком, а не только добавляемый фрагмент. Перед вызовом writeDiff для
   СУЩЕСТВУЮЩЕГО файла ты ОБЯЗАН сначала вызвать readFile для этого же файла и
   включить ВСЁ его содержимое в newContent, добавив к нему только нужные изменения.
   Замена существующего файла на пустой/сокращённый контент — это потеря данных
   и КРИТИЧЕСКАЯ ОШИБКА, даже если задача касается только добавления одной строки.
8. ГРАНИЦА ТВОЕЙ АВТОНОМНОЙ РАБОТЫ — writeDiff. Для задач, изменяющих код,
   правильная и полная последовательность твоих действий:
       createBranch → readFile (если файл существующий) → writeDiff → runTests
   На этом твоя работа в рамках одного задания ЗАВЕРШЕНА. writeDiff только
   СОХРАНЯЕТ предложенное изменение как черновик (staged patch) — он НЕ применяет
   его к реальному файлу и НЕ создаёт git-коммит. Поэтому между веткой и main
   физически нет изменений, и createPullRequest в этот момент ВСЕГДА провалится
   с ошибкой "No commits between main and main" — это ожидаемо, не ошибка с твоей
   стороны.
9. НЕ вызывай createPullRequest сразу после writeDiff в рамках одной и той же
   задачи. Человек должен сначала просмотреть и вручную применить твой patch
   (через отдельную команду agent:apply-patch, которая не является твоим
   инструментом). Только когда человек явно сообщит тебе в диалоге, что patch
   применён и закоммичен (например: "патч применён, открой PR") — вызывай
   createPullRequest.
10. Когда writeDiff отработал успешно, следующим действием заверши свой ответ
    текстом для человека: что именно предложено, в каком patch_id, и что
    дальше требуется ручное применение через agent:apply-patch перед PR.
    Не пытайся сокращать эту цепочку и не изобретай способ закоммитить
    изменение самостоятельно — у тебя сознательно нет такого инструмента.
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

    #[Tool('Создать git-ветку feature/agent-* от актуального main для новой задачи. ВСЕГДА вызывай это ПЕРВЫМ шагом перед writeDiff')]
    public function createBranch(string $taskSlug): string
    {
        try {
            $branch = app(GitGuard::class)->createFeatureBranch($taskSlug);
            return "Ветка '{$branch}' создана и является текущей. Теперь можно использовать writeDiff.";
        } catch (\RuntimeException $e) {
            return 'ОШИБКА GIT: ' . $e->getMessage();
        }
    }

    #[Tool('Записать предложенное изменение как staged-patch (черновик). ВАЖНО: это НЕ применяет изменение к реальному файлу и НЕ создаёт коммит — это финальный шаг твоей автономной работы для задач с изменением кода, дальше требуется ручное применение человеком')]
    public function writeDiff(string $relativePath, string $newContent, string $reasoning): string
    {
        try {
            $result = app(ProjectFsGuard::class)->proposeDiff($relativePath, $newContent, $reasoning);
            return 'Изменение сохранено как черновик (НЕ применено к файлу, НЕ закоммичено): patch_id=' . $result['patch_id']
                . '. Человек должен просмотреть и применить его командой agent:apply-patch перед тем, как открывать PR.';
        } catch (AgentSecurityViolation $e) {
            return 'ОШИБКА ДОСТУПА: ' . $e->getMessage();
        }
    }

    #[Tool('Запустить тесты проекта на sandbox-тенанте (никогда не на проде)')]
    public function runTests(?string $filter = null): string
    {
        try {
            return app(TestRunnerGuard::class)->run($filter);
        } catch (\Throwable $e) {
            return 'ОШИБКА ТЕСТОВ: ' . $e->getMessage();
        }
    }

    #[Tool('Открыть Pull Request. Вызывай ТОЛЬКО когда человек явно подтвердил, что staged-patch уже применён и закоммичен через agent:apply-patch')]
    public function createPullRequest(string $title, string $description): string
    {
        try {
            return app(GitGuard::class)->openPullRequest($title, $description);
        } catch (\RuntimeException $e) {
            return 'ОШИБКА GIT: ' . $e->getMessage() . ' Между веткой и main нет коммитов — patch ещё не применён человеком через agent:apply-patch.';
        }
    }

    #[Tool('Задать архитектору уточняющий вопрос вместо предположения молча')]
    public function askArchitect(string $question): string
    {
        return app(ArchitectEscalationService::class)->log($question);
    }
}