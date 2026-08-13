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
use App\Models\WorkOrder;
use App\Models\Service;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\TransactionCategory;
use App\Models\Transaction;
use App\Models\Lookup;
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

    public function __construct(string $entityType, array $ids, int $userId)
    {
        $this->entityType = $entityType;
        $this->ids = $ids;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user) return;

        $fileName = "export_{$this->entityType}_" . date('Y_m_d_His') . '_' . uniqid() . '.csv';
        $filePath = "exports/{$fileName}";

        Storage::disk('local')->makeDirectory('exports');
        $absolutePath = Storage::disk('local')->path($filePath);
        
        $file = fopen($absolutePath, 'w');
        fputs($file, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM для UTF-8

        $this->processExport($file);

        fclose($file);

        $user->notify(new ExportReadyNotification($fileName, $this->entityType, count($this->ids)));
    }

    private function processExport($file): void
    {
        switch ($this->entityType) {
            case 'clients': $this->exportClients($file); break;
            case 'vehicles': $this->exportVehicles($file); break;
            case 'employees': $this->exportEmployees($file); break;
            case 'branches': $this->exportBranches($file); break;
            case 'legal_entities': $this->exportLegalEntities($file); break;
            case 'business_directions': $this->exportBusinessDirections($file); break;
            case 'custom_fields': $this->exportCustomFields($file); break;
            case 'positions': $this->exportPositions($file); break;
            case 'work_orders': $this->exportWorkOrders($file); break;
            case 'services': $this->exportServices($file); break;
            case 'products': $this->exportProducts($file); break;
            case 'stock_balances': $this->exportStockBalances($file); break;
            case 'stock_movements': $this->exportStockMovements($file); break;
            case 'transaction_categories': $this->exportTransactionCategories($file); break;
            case 'transactions': $this->exportTransactions($file); break;
        }
    }

    private function exportClients($file): void
    {
        fputcsv($file, ['ID', 'Имя', 'Псевдоним', 'Телефон', 'Доп. Телефон', 'Email', 'Тип', 'Группа', 'Источник', 'Баланс', 'Бонусы', 'Скидка', 'Локация'], ';', '"', '\\');
        
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
                ], ';', '"', '\\');
            }
        });
    }

    private function exportVehicles($file): void
    {
        fputcsv($file, ['ID', 'Марка', 'Модель', 'Госномер', 'VIN', 'Год', 'Владелец', 'Телефон владельца'], ';', '"', '\\');
        
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
                ], ';', '"', '\\');
            }
        });
    }

    private function exportEmployees($file): void
    {
        fputcsv($file, ['ID', 'Фамилия', 'Имя', 'Отчество', 'Телефон', 'Email', 'Локация', 'Должность', 'Тип', 'Статус'], ';', '"', '\\');
        
        Employee::with(['branch', 'position', 'user.roles'])->whereIn('id', $this->ids)->chunk(500, function($employees) use ($file) {
            $employeeTypes = [
                'staff' => 'В штате',
                'self_employed' => 'Самозанятый',
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
                ], ';', '"', '\\');
            }
        });
    }

    private function exportBranches($file): void
    {
        fputcsv($file, ['ID', 'Название', 'Юрлицо', 'Город', 'Адрес', 'Телефон', 'Статус'], ';', '"', '\\');
        
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
                ], ';', '"', '\\');
            }
        });
    }

    private function exportLegalEntities($file): void
    {
        fputcsv($file, ['ID', 'Название', 'Юрисдикция', 'Налоговый номер', 'Статус'], ';', '"', '\\');
        $tenantCountry = config('tenant.country_code', 'RU');
        
        LegalEntity::whereIn('id', $this->ids)->chunk(500, function($items) use ($file, $tenantCountry) {
            foreach ($items as $item) {
                fputcsv($file, [
                    $item->id,
                    $item->name,
                    $tenantCountry,
                    $item->tax_id,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';', '"', '\\');
            }
        });
    }

    private function exportBusinessDirections($file): void
    {
        fputcsv($file, ['ID', 'Название', 'Локации', 'Статус'], ';', '"', '\\');
        
        BusinessDirection::with('branches')->whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            foreach ($items as $item) {
                $branches = $item->branches->pluck('name')->join(', ');
                fputcsv($file, [
                    $item->id,
                    $item->name,
                    $branches ?: 'Во всех локациях',
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';', '"', '\\');
            }
        });
    }

    private function exportCustomFields($file): void
    {
        fputcsv($file, ['ID', 'Сущность', 'Ключ', 'Название', 'Тип', 'Обязательное', 'Фильтруемое', 'В списке'], ';', '"', '\\');
        
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
                ], ';', '"', '\\');
            }
        });
    }

    private function exportPositions($file): void
    {
        fputcsv($file, ['ID', 'Название', 'Статус'], ';', '"', '\\');
        
        Position::whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            foreach ($items as $item) {
                $name = is_array($item->name) ? ($item->name['ru'] ?? current($item->name)) : $item->name;
                fputcsv($file, [
                    $item->id,
                    $name,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';', '"', '\\');
            }
        });
    }

    private function exportWorkOrders($file): void
    {
        fputcsv($file, ['ID', 'Локация', 'Клиент', 'Автомобиль', 'Статус', 'Оплата', 'Пробег', 'Сумма', 'Скидка', 'Итого', 'Дата создания'], ';', '"', '\\');
        
        $statuses = Lookup::where('type', 'work_order_status')
            ->get()
            ->mapWithKeys(fn ($lookup) => [$lookup->value => $lookup->label ?: $lookup->value]);

        $paymentStatuses = [
            'unpaid' => 'Не оплачен',
            'partial' => 'Частично',
            'paid' => 'Оплачен',
        ];

        WorkOrder::with(['branch', 'client', 'vehicle.make', 'vehicle.vehicleModel'])->whereIn('id', $this->ids)->chunk(500, function($orders) use ($file, $statuses, $paymentStatuses) {
            foreach ($orders as $order) {
                $vehicleName = $order->vehicle ? trim(($order->vehicle->make->name ?? '') . ' ' . ($order->vehicle->vehicleModel->name ?? '') . ' ' . $order->vehicle->plate_number) : '—';
                fputcsv($file, [
                    $order->id,
                    $order->branch ? $order->branch->name : '',
                    $order->client ? $order->client->name : '',
                    $vehicleName,
                    $statuses[$order->status] ?? $order->status,
                    $paymentStatuses[$order->payment_status] ?? $order->payment_status,
                    $order->mileage,
                    $order->total_amount / 100,
                    $order->discount_amount / 100,
                    $order->final_amount / 100,
                    $order->created_at->format('Y-m-d H:i:s')
                ], ';', '"', '\\');
            }
        });
    }

    private function exportServices($file): void
    {
        fputcsv($file, ['ID', 'Категория', 'Название', 'Цена', 'Длительность (мин)', 'Статус'], ';', '"', '\\');
        
        Service::with('category')->whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            foreach ($items as $item) {
                $catName = $item->category ? (is_array($item->category->name) ? ($item->category->name['ru'] ?? current($item->category->name)) : $item->category->name) : 'Без категории';
                $name = is_array($item->name) ? ($item->name['ru'] ?? current($item->name)) : $item->name;
                
                fputcsv($file, [
                    $item->id,
                    $catName,
                    $name,
                    $item->price / 100,
                    $item->duration_minutes,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';', '"', '\\');
            }
        });
    }

    private function exportProducts($file): void
    {
        fputcsv($file, ['ID', 'Категория', 'Артикул', 'Название', 'Ед. изм.', 'Тип учета', 'Статус'], ';', '"', '\\');
        
        Product::with('category')->whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            $accountingTypes = ['average' => 'Средневзвешенный', 'batch' => 'Партионный (FIFO)'];
            foreach ($items as $item) {
                $catName = $item->category ? (is_array($item->category->name) ? ($item->category->name['ru'] ?? current($item->category->name)) : $item->category->name) : 'Без категории';
                $name = is_array($item->name) ? ($item->name['ru'] ?? current($item->name)) : $item->name;
                
                fputcsv($file, [
                    $item->id,
                    $catName,
                    $item->sku,
                    $name,
                    $item->unit,
                    $accountingTypes[$item->accounting_type] ?? $item->accounting_type,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';', '"', '\\');
            }
        });
    }

    private function exportStockBalances($file): void
    {
        fputcsv($file, ['ID', 'Склад', 'Категория', 'Артикул', 'Товар', 'Остаток', 'Ед. изм.', 'Средняя себестоимость', 'Общая стоимость'], ';', '"', '\\');
        
        StockBalance::with(['warehouse', 'product.category'])->whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            foreach ($items as $item) {
                $product = $item->product;
                $catName = $product && $product->category ? (is_array($product->category->name) ? ($product->category->name['ru'] ?? current($product->category->name)) : $product->category->name) : 'Без категории';
                $name = $product ? (is_array($product->name) ? ($product->name['ru'] ?? current($product->name)) : $product->name) : 'Неизвестно';
                
                fputcsv($file, [
                    $item->id,
                    $item->warehouse ? $item->warehouse->name : '',
                    $catName,
                    $product ? $product->sku : '',
                    $name,
                    $item->quantity,
                    $product ? $product->unit : '',
                    $item->avg_cost / 100,
                    ($item->quantity * $item->avg_cost) / 100
                ], ';', '"', '\\');
            }
        });
    }

    private function exportStockMovements($file): void
    {
        fputcsv($file, ['ID', 'Дата', 'Тип', 'Склад', 'Локация', 'Товар', 'Кол-во', 'Себестоимость', 'Заказ-наряд', 'Комментарий'], ';', '"', '\\');
        
        $types = [
            'in' => 'Приход',
            'out' => 'Расход',
            'transfer' => 'Перемещение',
            'audit' => 'Инвентаризация',
            'consolidation' => 'Консолидация',
        ];

        StockMovement::with(['warehouse', 'branch', 'product', 'workOrder'])->whereIn('id', $this->ids)->chunk(500, function($items) use ($file, $types) {
            foreach ($items as $item) {
                $product = $item->product;
                $name = $product ? (is_array($product->name) ? ($product->name['ru'] ?? current($product->name)) : $product->name) : 'Неизвестно';
                
                fputcsv($file, [
                    $item->id,
                    $item->created_at->format('Y-m-d H:i:s'),
                    $types[$item->type] ?? $item->type,
                    $item->warehouse ? $item->warehouse->name : '',
                    $item->branch ? $item->branch->name : '',
                    $name,
                    $item->quantity,
                    $item->cost_price / 100,
                    $item->work_order_id ? '#' . str_pad($item->work_order_id, 6, '0', STR_PAD_LEFT) : '',
                    $item->comment
                ], ';', '"', '\\');
            }
        });
    }

    private function exportTransactionCategories($file): void
    {
        fputcsv($file, ['ID', 'Тип', 'Название', 'Статус'], ';', '"', '\\');
        
        TransactionCategory::whereIn('id', $this->ids)->chunk(500, function($items) use ($file) {
            $types = ['income' => 'Доход', 'expense' => 'Расход'];
            foreach ($items as $item) {
                $name = is_array($item->name) ? ($item->name['ru'] ?? current($item->name)) : $item->name;
                fputcsv($file, [
                    $item->id,
                    $types[$item->type] ?? $item->type,
                    $name,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';', '"', '\\');
            }
        });
    }

    private function exportTransactions($file): void
    {
        fputcsv($file, ['ID', 'Дата', 'Тип', 'Счет (Касса)', 'Локация', 'Статья', 'Сумма', 'Основание', 'Комментарий'], ';', '"', '\\');
        
        $types = ['income' => 'Доход', 'expense' => 'Расход', 'transfer' => 'Перевод'];

        Transaction::with(['account', 'branch', 'category', 'payable'])->whereIn('id', $this->ids)->chunk(500, function($items) use ($file, $types) {
            foreach ($items as $item) {
                $catName = $item->category ? (is_array($item->category->name) ? ($item->category->name['ru'] ?? current($item->category->name)) : $item->category->name) : '—';
                
                $payableInfo = '—';
                if ($item->payable_type === 'App\\Models\\WorkOrder') {
                    $payableInfo = 'Заказ-наряд #' . str_pad($item->payable_id, 6, '0', STR_PAD_LEFT);
                }

                fputcsv($file, [
                    $item->id,
                    $item->created_at->format('Y-m-d H:i:s'),
                    $types[$item->type] ?? $item->type,
                    $item->account ? $item->account->name : '',
                    $item->branch ? $item->branch->name : '',
                    $catName,
                    $item->amount / 100,
                    $payableInfo,
                    $item->comment
                ], ';', '"', '\\');
            }
        });
    }
}