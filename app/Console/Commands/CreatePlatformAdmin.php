<?php

namespace App\Console\Commands;

use App\Models\Central\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Единственный способ завести администратора платформы — публичной
 * регистрации на central-домене для этого нет и не будет (см. CLAUDE.md,
 * Фаза 16). Работает напрямую с central БД, без tenancy()->initialize().
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email?} {--name=} {--password=}';
    protected $description = 'Создать администратора платформы (central-кабинет /admin)';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email администратора');
        $name = $this->option('name') ?: $this->ask('Имя', 'Администратор платформы');
        $password = $this->option('password') ?: $this->secret('Пароль (мин. 8 символов)');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:platform_admins,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        PlatformAdmin::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Администратор платформы создан: {$email}");

        return self::SUCCESS;
    }
}
