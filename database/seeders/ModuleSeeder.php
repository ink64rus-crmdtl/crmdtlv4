<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use Spatie\Permission\Models\Permission;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            ['key' => 'dashboard', 'label' => ['en' => 'Dashboard', 'ru' => 'Дашборд'], 'icon' => 'ri-home-4-line', 'is_core' => true, 'sort_order' => 10, 'required_permission' => 'view_dashboard'],
            ['key' => 'crm', 'label' => ['en' => 'CRM', 'ru' => 'Клиенты и Авто'], 'icon' => 'ri-group-line', 'is_core' => true, 'sort_order' => 20, 'required_permission' => 'view_crm'],
            ['key' => 'operations', 'label' => ['en' => 'Orders & Appointments', 'ru' => 'Заказы и Записи'], 'icon' => 'ri-briefcase-line', 'is_core' => true, 'sort_order' => 30, 'required_permission' => 'view_operations'],
            ['key' => 'warehouse', 'label' => ['en' => 'Warehouse', 'ru' => 'Склад'], 'icon' => 'ri-archive-line', 'is_core' => true, 'sort_order' => 40, 'required_permission' => 'view_warehouse'],
            ['key' => 'finance', 'label' => ['en' => 'Finance', 'ru' => 'Финансы'], 'icon' => 'ri-money-dollar-circle-line', 'is_core' => true, 'sort_order' => 50, 'required_permission' => 'view_finance'],
            ['key' => 'hr', 'label' => ['en' => 'HR', 'ru' => 'Сотрудники'], 'icon' => 'ri-team-line', 'is_core' => true, 'sort_order' => 60, 'required_permission' => 'view_hr'],
            ['key' => 'communications', 'label' => ['en' => 'Communications', 'ru' => 'Общение'], 'icon' => 'ri-chat-3-line', 'is_core' => true, 'sort_order' => 70, 'required_permission' => 'view_communications'],
            ['key' => 'documents', 'label' => ['en' => 'Documents', 'ru' => 'Документы'], 'icon' => 'ri-file-text-line', 'is_core' => true, 'sort_order' => 80, 'required_permission' => 'view_documents'],
            ['key' => 'dictionaries', 'label' => ['en' => 'Dictionaries', 'ru' => 'Справочники'], 'icon' => 'ri-book-2-line', 'is_core' => true, 'sort_order' => 85, 'required_permission' => 'view_settings'],
            ['key' => 'settings', 'label' => ['en' => 'Settings', 'ru' => 'Настройки'], 'icon' => 'ri-settings-3-line', 'is_core' => true, 'sort_order' => 90, 'required_permission' => 'view_settings'],
            ['key' => 'system', 'label' => ['en' => 'System', 'ru' => 'Система'], 'icon' => 'ri-server-line', 'is_core' => true, 'sort_order' => 100, 'required_permission' => 'view_system'],
        ];

        foreach ($modules as $mod) {
            if (!empty($mod['required_permission'])) {
                Permission::firstOrCreate(['name' => $mod['required_permission'], 'guard_name' => 'web']);
            }
            Module::updateOrCreate(['key' => $mod['key']], $mod);
        }
    }
}