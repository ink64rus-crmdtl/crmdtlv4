<?php

namespace Tests\Agent;

use App\Http\Controllers\Tenant\AppointmentController;
use App\Http\Controllers\Tenant\WorkOrderController;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * order_date/ready_at (CLAUDE.md, "Дата/время создания и готовности заказ-наряда") —
 * бизнес-даты заказа, отдельные от технического created_at, конвертируются из
 * wall-clock времени локации в UTC тем же способом, что и Appointment.start_at/end_at.
 */
class WorkOrderOrderDateReadyAtTest extends TenantAgentTestCase
{
    private Branch $branch;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация', 'timezone' => 'Europe/Moscow']);
        $this->client = Client::create(['branch_id' => $this->branch->id, 'type' => 'b2c', 'name' => 'Заказчик']);
    }

    private function controller(): WorkOrderController
    {
        return app(WorkOrderController::class);
    }

    #[Test]
    public function store_defaults_order_date_to_now_when_not_provided(): void
    {
        $request = new Request;
        $request->merge([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'status' => 'new',
        ]);

        $before = now();
        $this->controller()->store($request);
        $after = now();

        $workOrder = WorkOrder::where('branch_id', $this->branch->id)->latest('id')->first();
        $this->assertNotNull($workOrder->order_date);
        $this->assertTrue($workOrder->order_date->betweenIncluded($before->subSecond(), $after->addSecond()));
        $this->assertNull($workOrder->ready_at);
    }

    #[Test]
    public function store_converts_explicit_local_datetimes_to_utc(): void
    {
        $request = new Request;
        $request->merge([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'status' => 'new',
            // 12:00 по Europe/Moscow (UTC+3) = 09:00 UTC.
            'order_date' => '2026-08-20T12:00',
            'ready_at' => '2026-08-20T15:30',
        ]);

        $this->controller()->store($request);

        $workOrder = WorkOrder::where('branch_id', $this->branch->id)->latest('id')->first();
        $this->assertSame('2026-08-20 09:00:00', $workOrder->order_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-20 12:30:00', $workOrder->ready_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function update_edits_both_fields_on_unlocked_order(): void
    {
        $workOrder = WorkOrder::create([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'status' => 'in_progress',
            'payment_status' => 'unpaid',
            'total_amount' => 0,
            'discount_amount' => 0,
            'final_amount' => 0,
            'order_date' => now(),
        ]);

        $request = new Request;
        $request->merge([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'status' => 'in_progress',
            'order_date' => '2026-08-21T09:00',
            'ready_at' => '2026-08-21T18:00',
        ]);
        $this->controller()->update($request, $workOrder->fresh());

        $fresh = $workOrder->fresh();
        $this->assertSame('2026-08-21 06:00:00', $fresh->order_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-21 15:00:00', $fresh->ready_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function update_is_still_blocked_on_completed_order(): void
    {
        $workOrder = WorkOrder::create([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total_amount' => 0,
            'discount_amount' => 0,
            'final_amount' => 0,
            'order_date' => now(),
        ]);

        $request = new Request;
        $request->merge([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'status' => 'completed',
            'ready_at' => '2026-08-21T18:00',
        ]);

        $this->expectException(HttpException::class);
        $this->controller()->update($request, $workOrder->fresh());
    }

    #[Test]
    public function convert_to_work_order_copies_ready_at_from_appointment_end_at(): void
    {
        $category = ServiceCategory::create(['name' => 'Тестовая группа', 'is_active' => true]);
        $service = Service::create(['service_category_id' => $category->id, 'name' => 'Тестовая услуга', 'price' => 100000, 'is_active' => true]);
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'client_id' => $this->client->id,
            'type' => 'service',
            'status' => 'confirmed',
            'start_at' => now()->addHour(),
            'end_at' => now()->addHours(3),
        ]);

        $before = now();
        $response = app(AppointmentController::class)->convertToWorkOrder($appointment->fresh());
        $after = now();

        $workOrder = WorkOrder::where('branch_id', $this->branch->id)->latest('id')->first();
        $this->assertNotNull($workOrder);
        // Регрессия на живой баг: order_date собирался через now($branchTz) без ->utc(),
        // из-за чего Eloquent записывал местные "числа на часах" в UTC-колонку как
        // есть — order_date уезжал на разницу часовых поясов (у Europe/Moscow — на
        // 3 часа вперёд). betweenIncluded() против голого now() ловит именно это.
        $this->assertTrue($workOrder->order_date->betweenIncluded($before->subSecond(), $after->addSecond()));
        $this->assertSame(
            $appointment->fresh()->end_at->format('Y-m-d H:i:s'),
            $workOrder->ready_at->format('Y-m-d H:i:s')
        );
        $this->assertNotNull($response);
    }
}
