<?php

namespace App\Services\Messaging;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Deal;
use App\Models\MessageTemplate;
use App\Models\WorkOrder;
use App\Services\CustomFieldPlaceholderService;
use App\Services\TimezoneResolver;

/**
 * Рендер плейсхолдеров вида {{client.name}} в теле шаблона (Фаза 11.1) —
 * по аналогии с document_templates из будущей Фазы 12 (те же {{ }}), но
 * набор доступных полей свой: сообщение мессенджера короткое, поэтому
 * плейсхолдеров сознательно немного.
 */
class MessageTemplateService
{
    public static function renderForWorkOrder(MessageTemplate $template, WorkOrder $workOrder): string
    {
        return self::render($template, array_merge(
            self::clientPlaceholders($workOrder->client),
            CustomFieldPlaceholderService::forEntity('work_order', $workOrder),
            [
                'work_order.id' => (string) $workOrder->id,
                'work_order.final_amount' => number_format($workOrder->final_amount / 100, 2, '.', ' '),
            ]
        ));
    }

    /** Фаза 17, этап 3 — автоматизация «отправить сообщение» при входе сделки в стадию. */
    public static function renderForDeal(MessageTemplate $template, Deal $deal): string
    {
        return self::render($template, array_merge(
            self::clientPlaceholders($deal->client),
            [
                'deal.title' => $deal->title,
                'deal.amount' => number_format($deal->amount / 100, 2, '.', ' '),
                'branch.name' => $deal->branch?->name ?? '',
            ]
        ));
    }

    public static function renderForAppointment(MessageTemplate $template, Appointment $appointment): string
    {
        $branchTz = TimezoneResolver::forBranch($appointment->branch_id);

        return self::render($template, array_merge(
            self::clientPlaceholders($appointment->client),
            [
                'appointment.start_at' => $appointment->start_at->clone()->setTimezone($branchTz)->translatedFormat('d.m.Y H:i'),
                'branch.name' => $appointment->branch?->name ?? '',
            ]
        ));
    }

    private static function clientPlaceholders(?Client $client): array
    {
        return array_merge(
            ['client.name' => $client?->name ?? ''],
            CustomFieldPlaceholderService::forEntity('client', $client)
        );
    }

    private static function render(MessageTemplate $template, array $context): string
    {
        $body = $template->body;

        return strtr($body, array_combine(
            array_map(fn ($key) => '{{'.$key.'}}', array_keys($context)),
            array_values($context)
        ));
    }
}
