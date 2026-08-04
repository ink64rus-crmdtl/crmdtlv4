<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Employee;
use App\Models\Branch;
use App\Models\LegalEntity;
use App\Models\BusinessDirection;
use App\Models\CustomFieldDefinition;
use App\Models\Position;
use App\Notifications\ExportReadyNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ExportEntitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $entityType;
    public array $ids;
    public int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $entityType, array $ids, int $userId)
    {
        $this->entityType = $entityType;
        $this->ids = $ids;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        $fileName = "export_{$this->entityType}_" . date('Y_m_d_His') . '_' . uniqid() . '.csv';
        $filePath = "exports/{$fileName}";

        Storage::disk('local')->makeDirectory('exports');
        $absolutePath = Storage::disk('local')->path($filePath);
        
        $file = fopen($absolutePath, 'w');
        // Добавляем BOM для корректного отображения UTF-8 в MS Excel
        fputs($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $this->processExport($file);

        fclose($file);

        // Отправляем уведомление пользователю о готовности файла
        $user->notify(new ExportReadyNotification($fileName, $this->entityType, count($this->ids)));
    }

    private function processExport($file): void
    {
        switch ($this->entityType) {
            case 'clients':
                $this->exportClients($file);
                break;
            case 'vehicles':
                $this->exportVehicles($file);
                break;
            case 'employees':
                $this->exportEmployees($file);
                break;
            case 'branches':
                $this->exportBranches($file);
                break;
            case 'legal_entities':
                $this->exportLegalEntities($file);
                break;
            case 'business_directions':
                $this->exportBusinessDirections($file);
                break;
            case 'custom_fields':
                $this->exportCustomFields($file);
                break;
            case 'positions':
                $this->exportPositions($file);
                break;
        }
    }

    private function exportClients($file): void
    {
        fputcsv($file, ['ID', 'Имя', 'Псевдоним', 'Телефон', 'Доп. Телефон', 'Email', 'Тип', 'Группа', 'Источник', 'Баланс', 'Бонусы', 'Скидка', 'Филиал'], ';');
        
        Client::with(['branch', 'group'])->whereIn('id', $this->ids)->chunk(500, function($clients) use ($file) {
            foreach ($clients as $client) {
                fputcsv($file, [
                    $client->id,
                    $client->name,
                    $client->alias,
                    $client->phone,
                    $client->phone_2,
                    $client->email,
                    $client->type === 'b2b' ? 'Юрлицо' : 'Физлицо',
                    $client->group ? $client->group->name : 'Без группы',
                    $client->source,
                    $client->balance / 100,
                    $client->bonus_points,
                    $client->discount_percent . '%',
                    $client->branch ? $client->branch->name : ''
                ], ';');
            }
        });
    }

    private function exportVehicles($file): void
    {
        fputcsv($file, ['ID', 'Марка', 'Модель', 'Госномер', 'VIN', 'Год', 'Владелец', 'Телефон владельца'], ';');
        
        Vehicle::with(['client', 'make', 'vehicleModel'])->whereIn('id', $this->ids)->chunk(500, function($vehicles) use ($file) {
            foreach ($vehicles as $vehicle) {
                fputcsv($file, [
                    $vehicle->id,
                    $vehicle->make ? $vehicle->make->name : '',
                    $vehicle->vehicleModel ? $vehicle->vehicleModel->name : '',
                    $vehicle->plate_number,
                    $vehicle->vin,
                    $vehicle->year,
                    $vehicle->client ? $vehicle->client->name : 'Неизвестно',
                    $vehicle->client ? $vehicle->client->phone : ''
                ], ';');
            }
        });
    }

    private function exportEmployees($file): void
    {
        fputcsv($file, ['ID', 'Фамилия', 'Имя', 'Отчество', 'Телефон', 'Email', 'Филиал', 'Должность', 'Тип', 'Статус'], ';');
        
        Employee::with(['branch', 'position', 'user.roles'])->whereIn('id', $this->ids)->chunk(500, function($employees) use ($file) {
            $employeeTypes = [
                'staff' => 'В штате',
                'self_employed' => 'Самозанятый',
                'outsource' => 'Аутсорс / Подрядчик'
            ];
            foreach ($employees as $emp) {
                $posName = 'Без должности';
                if ($emp->position) {
                    $posName = is_array($emp->position->name) ? ($emp->position->name['ru'] ?? current($emp->position->name)) : $emp->position->name;
                }
                fputcsv($file, [
                    $emp->id,
                    $emp->last_name,
                    $emp->first_name,
                    $emp->middle_name,
                    $emp->phone,
                    $emp->personal_email,
                    $emp->branch ? $emp->branch->name : '',
                    $posName,
                    $employeeTypes[$emp->type] ?? $emp->type,
                    $emp->is_active ? 'Активен' : 'Уволен'
                ], ';');
            }
        });
    }

    private function exportBranches($file): void
    {
        fputcsv($file, ['ID', 'Название', 'Юрлицо', 'Город', 'Адрес', 'Телефон', 'Статус'], ';');
        
        Branch::with('legalEntity')->whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            foreach ($items as $item) {
                fputcsv($file, [
                    $item->id,
                    $item->name,
                    $item->legalEntity ? $item->legalEntity->name : 'Не привязан',
                    $item->city,
                    $item->address,
                    $item->phone,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';');
            }
        });
    }

    private function exportLegalEntities($file): void
    {
        fputcsv($file, ['ID', 'Название', 'Юрисдикция', 'Налоговый номер', 'Статус'], ';');
        $tenantCountry = config('tenant.country_code', 'RU');
        
        LegalEntity::whereIn('id', $this->ids)->chunk(500, function($items) use ($file, $tenantCountry) {
            foreach ($items as $item) {
                fputcsv($file, [
                    $item->id,
                    $item->name,
                    $tenantCountry,
                    $item->tax_id,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';');
            }
        });
    }

    private function exportBusinessDirections($file): void
    {
        fputcsv($file, ['ID', 'Название', 'Филиалы', 'Статус'], ';');
        
        BusinessDirection::with('branches')->whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            foreach ($items as $item) {
                $branches = $item->branches->pluck('name')->join(', ');
                fputcsv($file, [
                    $item->id,
                    $item->name,
                    $branches ?: 'Во всех филиалах',
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';');
            }
        });
    }

    private function exportCustomFields($file): void
    {
        fputcsv($file, ['ID', 'Сущность', 'Ключ', 'Название', 'Тип', 'Обязательное', 'Фильтруемое', 'В списке'], ';');
        
        CustomFieldDefinition::whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            $entityTypes = [
                'client' => 'Клиент',
                'vehicle' => 'Автомобиль',
                'work_order' => 'Заказ-наряд',
                'employee' => 'Сотрудник',
            ];
            $fieldTypes = [
                'text' => 'Текст',
                'number' => 'Число',
                'date' => 'Дата',
                'select' => 'Выпадающий список',
                'checkbox' => 'Галочка (Да/Нет)',
            ];
            foreach ($items as $item) {
                $label = is_array($item->label) ? ($item->label['ru'] ?? current($item->label)) : $item->label;
                fputcsv($file, [
                    $item->id,
                    $entityTypes[$item->entity_type] ?? $item->entity_type,
                    $item->key,
                    $label,
                    $fieldTypes[$item->type] ?? $item->type,
                    $item->is_required ? 'Да' : 'Нет',
                    $item->is_filterable ? 'Да' : 'Нет',
                    $item->is_visible_in_list ? 'Да' : 'Нет'
                ], ';');
            }
        });
    }

    private function exportPositions($file): void
    {
        fputcsv($file, ['ID', 'Название', 'Статус'], ';');
        
        Position::whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            foreach ($items as $item) {
                $name = is_array($item->name) ? ($item->name['ru'] ?? current($item->name)) : $item->name;
                fputcsv($file, [
                    $item->id,
                    $name,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';');
            }
        });
    }
}