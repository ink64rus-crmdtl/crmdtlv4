<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            ['key' => 'dashboard', 'label' => ['en' => 'Dashboard', 'ru' => 'Дашборд'], 'icon' => 'HomeIcon', 'is_core' => true, 'sort_order' => 10],
            ['key' => 'crm', 'label' => ['en' => 'CRM', 'ru' => 'Клиенты и Авто'], 'icon' => 'UsersIcon', 'is_core' => true, 'sort_order' => 20],
            ['key' => 'operations', 'label' => ['en' => 'Operations', 'ru' => 'Заказы'], 'icon' => 'BriefcaseIcon', 'is_core' => true, 'sort_order' => 30],
            ['key' => 'warehouse', 'label' => ['en' => 'Warehouse', 'ru' => 'Склад'], 'icon' => 'ArchiveBoxIcon', 'is_core' => true, 'sort_order' => 40],
            ['key' => 'finance', 'label' => ['en' => 'Finance', 'ru' => 'Финансы'], 'icon' => 'BanknotesIcon', 'is_core' => true, 'sort_order' => 50],
            ['key' => 'hr', 'label' => ['en' => 'HR', 'ru' => 'Сотрудники'], 'icon' => 'UserGroupIcon', 'is_core' => true, 'sort_order' => 60],
            ['key' => 'communications', 'label' => ['en' => 'Communications', 'ru' => 'Общение'], 'icon' => 'ChatBubbleLeftRightIcon', 'is_core' => true, 'sort_order' => 70],
            ['key' => 'documents', 'label' => ['en' => 'Documents', 'ru' => 'Документы'], 'icon' => 'DocumentTextIcon', 'is_core' => true, 'sort_order' => 80],
            ['key' => 'settings', 'label' => ['en' => 'Settings', 'ru' => 'Настройки'], 'icon' => 'Cog6ToothIcon', 'is_core' => true, 'sort_order' => 90],
            ['key' => 'system', 'label' => ['en' => 'System', 'ru' => 'Система'], 'icon' => 'ri-server-line', 'is_core' => true, 'sort_order' => 100],
        ];

        foreach ($modules as $mod) {
            Module::firstOrCreate(['key' => $mod['key']], $mod);
        }
    }
}