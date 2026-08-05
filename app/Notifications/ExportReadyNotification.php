<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ExportReadyNotification extends Notification
{
    use Queueable;

    public string $fileName;
    public string $entityType;
    public int $count;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $fileName, string $entityType, int $count)
    {
        $this->fileName = $fileName;
        $this->entityType = $entityType;
        $this->count = $count;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $entityNames = [
            'clients' => 'клиентов',
            'vehicles' => 'автомобилей',
            'employees' => 'сотрудников',
            'branches' => 'филиалов',
            'legal_entities' => 'юрлиц',
            'business_directions' => 'направлений',
            'custom_fields' => 'кастомных полей',
            'positions' => 'должностей',
            'work_orders' => 'заказ-нарядов',
        ];

        $entityName = $entityNames[$this->entityType] ?? 'записей';

        return [
            'file_name' => $this->fileName,
            'entity_type' => $this->entityType,
            'count' => $this->count,
            'message' => "Экспорт {$entityName} ({$this->count} шт.) успешно завершен. Файл готов к скачиванию.",
        ];
    }
}