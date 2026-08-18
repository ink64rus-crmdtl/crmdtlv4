<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PipelineSeeder;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Наполнение базы нового тенанта: роли, модули, стартовая воронка и
 * администратор. Раньше это выполнялось прямо в RegisterTenantController::store()
 * после синхронного пайплайна создания БД — один HTTP-запрос висел по нескольку
 * минут. Теперь создание БД + миграции идут очередью (TenancyServiceProvider),
 * а эта джоба стартует следом и ждёт завершения миграций (release-цикл — Horizon
 * держит несколько воркеров на одной очереди, FIFO-порядок не гарантирован).
 * Статус готовности фронт смотрит по полю data.provisioned_at
 * (RegisterTenantController::status()).
 */
class ProvisionTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // MigrateDatabase в пайплайне идёт одной длинной джобой (134 миграции),
    // а эта джоба может проснуться раньше неё — пока база не готова, release().
    // На медленной VM миграции занимают ~20 минут, поэтому запас попыток
    // должен покрывать наихудший случай (200 × 15 c ≈ 50 минут ожидания),
    // иначе джоба исчерпает tries и зависнет в «pending» навсегда.
    public $tries = 200;
    public $backoff = 15;

    public function __construct(
        public Tenant $tenant,
        public array $payload,
    ) {}

    public function handle(): void
    {
        // Шаг 1 — готовность базы. Пока CreateDatabase/MigrateDatabase не
        // завершились, не трогаем данные: ни ошибки, ни сидеров.
        $ready = false;
        try {
            $ready = $this->tenant->run(function () {
                $migrationFiles = count(glob(database_path('migrations/tenant') . '/*_*.php'));
                $applied = \DB::table('migrations')->count();

                return $applied >= $migrationFiles;
            });
        } catch (Throwable $e) {
            // Базы ещё нет вообще (QueryException "Unknown database") либо
            // таблицы migrations пока нет — это не ошибка провижининга,
            // а просто незавершённый первый шаг. Ждём и пробуем снова.
            $this->release($this->backoff);

            return;
        }

        if (! $ready) {
            $this->release($this->backoff);

            return;
        }

        try {
            $this->tenant->run(function () {
                // Запускаем сидеры ролей и модулей
                (new TenantRoleSeeder)->run();
                (new ModuleSeeder)->run();
                // Стартовая воронка продаж со стадиями (Фаза 17) — без неё
                // раздел «Продажи» открылся бы у нового тенанта пустым, и
                // первую сделку было бы некуда положить.
                (new PipelineSeeder)->run();

                // Создаем администратора
                $owner = User::create([
                    'name' => $this->payload['admin_name'],
                    'email' => $this->payload['admin_email'],
                    'password' => Hash::make($this->payload['password']),
                ]);

                // Выдаем роль admin
                $owner->assignRole('admin');
            });

            $this->tenant->provisioned_at = now()->toIso8601String();
            $this->tenant->save();
        } catch (Throwable $e) {
            // Пишем причину в data — status()-эндпоинт отдаст 'failed' и фронт
            // покажет ошибку вместо бесконечного «Создаем вашу CRM...».
            $this->tenant->provision_error = $e->getMessage();
            $this->tenant->save();

            throw $e;
        }
    }

    /**
     * Страховка от вечного «pending»: если джоба так и не дождалась миграций
     * и исчерпала все попытки (release-цикл + tries), пишем причину в data —
     * фронт увидит 'failed', а не бесконечный спиннер.
     */
    public function failed(Throwable $e): void
    {
        try {
            $this->tenant->provision_error = $e->getMessage();
            $this->tenant->save();
        } catch (Throwable $ignored) {
            \Log::error('ProvisionTenantJob::failed() не смог записать provision_error', [
                'tenant_id' => $this->tenant->id,
                'exception' => $ignored->getMessage(),
            ]);
        }
    }
}