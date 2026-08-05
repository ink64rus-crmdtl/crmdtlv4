<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Product;
use App\Models\Account;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\ListView;
use App\Models\Setting;
use App\Models\Lookup;
use App\Models\BusinessDirection;
use App\Models\ServiceCategory;
use App\Models\ProductCategory;
use App\Models\Employee;
use App\Services\FieldPermissionService;
use App\Services\QueryFilterService;
use App\Services\WarehouseResolver;
use App\Services\StockService;
use App\Services\FinanceService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Exception;

class WorkOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = auth()->user();
        
        $query = WorkOrder::with(['branch', 'client', 'vehicle.make', 'vehicle.vehicleModel']);
        
        $query = QueryFilterService::apply(
            $query, 
            $request->all(), 
            ['id'],
            'work_order'
        );

        if (!request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        $workOrders = $query->paginate(15)->withQueryString();
        
        $branches = Branch::forSelect()->get(['id', 'name']);
        $clients = Client::orderBy('name')->get(['id', 'name', 'phone']);
        $vehicles = Vehicle::with(['make', 'vehicleModel'])->get(['id', 'client_id', 'vehicle_make_id', 'vehicle_model_id', 'plate_number']);

        $baseColumns = [
            ['key' => 'id', 'label' => '№ Заказа', 'type' => 'system', 'is_default' => true],
            ['key' => 'created_at', 'label' => 'Дата', 'type' => 'system', 'is_default' => true],
            ['key' => 'client', 'label' => 'Клиент', 'type' => 'system', 'is_default' => true],
            ['key' => 'vehicle', 'label' => 'Автомобиль', 'type' => 'system', 'is_default' => true],
            ['key' => 'status', 'label' => 'Статус', 'type' => 'system', 'is_default' => true],
            ['key' => 'payment_status', 'label' => 'Оплата', 'type' => 'system', 'is_default' => true],
            ['key' => 'final_amount', 'label' => 'Сумма', 'type' => 'system', 'is_default' => true],
            ['key' => 'branch', 'label' => 'Филиал', 'type' => 'system', 'is_default' => false],
            ['key' => 'mileage', 'label' => 'Пробег', 'type' => 'system', 'is_default' => false],
        ];

        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'work_order')->orderBy('sort_order')->get();
        foreach ($customFieldDefs as $cf) {
            $baseColumns[] = [
                'key' => $cf->key,
                'label' => $cf->label[app()->getLocale()] ?? current($cf->label),
                'type' => 'custom',
                'is_default' => $cf->is_visible_in_list,
            ];
        }

        $cfValues = CustomFieldValue::where('entity_type', 'work_order')
            ->whereIn('entity_id', $workOrders->getCollection()->pluck('id'))
            ->get();

        $workOrders->getCollection()->transform(function ($order) use ($cfValues, $customFieldDefs) {
            $orderData = $order->toArray();
            $orderData['custom_fields'] = [];
            
            foreach ($customFieldDefs as $def) {
                $val = $cfValues->where('entity_id', $order->id)->where('custom_field_definition_id', $def->id)->first();
                $orderData['custom_fields'][$def->key] = $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null;
            }
            
            return $orderData;
        });

        $allFieldKeys = array_column($baseColumns, 'key');
        $visibleKeys = FieldPermissionService::visibleFields($user, 'work_order', $allFieldKeys);

        $availableColumns = array_values(array_filter($baseColumns, function($col) use ($visibleKeys) {
            return in_array($col['key'], $visibleKeys);
        }));

        $listView = ListView::where('entity_type', 'work_order')
            ->where('user_id', $user->id)
            ->first();

        $visibleColumns = $listView 
            ? $listView->visible_columns 
            : array_values(array_map(fn($c) => $c['key'], array_filter($availableColumns, fn($c) => $c['is_default'])));

        return Inertia::render('Operations/WorkOrders/Index', [
            'workOrders' => $workOrders,
            'filters' => $request->all(),
            'branches' => $branches,
            'clients' => $clients,
            'vehicles' => $vehicles,
            'customFieldDefs' => $customFieldDefs,
            'availableColumns' => $availableColumns,
            'listView' => [
                'visible_columns' => $visibleColumns,
            ],
            'workOrderStatuses' => $this->workOrderStatuses(),
        ]);
    }

    public function show(WorkOrder $workOrder): Response
    {
        $workOrder->load(['branch', 'client', 'vehicle.make', 'vehicle.vehicleModel', 'items.employees', 'transactions.account']);
        
        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'work_order')->orderBy('sort_order')->get();
        $cfValues = CustomFieldValue::where('entity_type', 'work_order')->where('entity_id', $workOrder->id)->get();
        
        $customFieldsData = [];
        foreach ($customFieldDefs as $def) {
            $val = $cfValues->where('custom_field_definition_id', $def->id)->first();
            $customFieldsData[] = [
                'definition' => $def,
                'value' => $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null,
            ];
        }

        $branches = Branch::forSelect()->get(['id', 'name']);
        $clients = Client::orderBy('name')->get(['id', 'name', 'phone']);
        $vehicles = Vehicle::with(['make', 'vehicleModel'])->get(['id', 'client_id', 'vehicle_make_id', 'vehicle_model_id', 'plate_number']);
        
        $services = Service::where('is_active', true)->get(['id', 'name', 'price', 'prices', 'service_category_id', 'business_direction_id']);
        $products = Product::where('is_active', true)->get(['id', 'name', 'sku', 'unit', 'product_category_id']);
        
        $accounts = auth()->user()->availableAccounts()->where('is_active', true)->get(['accounts.id', 'accounts.name', 'accounts.type', 'accounts.commission_percent']);

        $pricingBasis = Setting::where('key', 'pricing_basis')->value('value') ?? 'none';
        $bonusRubPerPoint = (float) (Setting::where('key', 'bonus_rub_per_point')->value('value') ?? 1);
        $lookups = Lookup::whereIn('type', ['vehicle_body', 'vehicle_class'])->where('is_active', true)->get()->groupBy('type');
        $businessDirections = BusinessDirection::where('is_active', true)->get(['id', 'name']);
        $serviceCategories = ServiceCategory::where('is_active', true)->get(['id', 'name', 'business_direction_id']);
        $productCategories = ProductCategory::where('is_active', true)->get(['id', 'name']);
        $employees = Employee::where('is_active', true)->get(['id', 'first_name', 'last_name']);

        return Inertia::render('Operations/WorkOrders/Show', [
            'workOrder' => $workOrder,
            'customFieldsData' => $customFieldsData,
            'branches' => $branches,
            'clients' => $clients,
            'vehicles' => $vehicles,
            'services' => $services,
            'products' => $products,
            'accounts' => $accounts,
            'customFieldDefs' => $customFieldDefs,
            'pricingBasis' => $pricingBasis,
            'bonusRubPerPoint' => $bonusRubPerPoint,
            'lookups' => $lookups,
            'businessDirections' => $businessDirections,
            'serviceCategories' => $serviceCategories,
            'productCategories' => $productCategories,
            'employees' => $employees,
            'workOrderStatuses' => $this->workOrderStatuses(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'status' => ['required', 'string', Rule::in($this->activeStatusValues())],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($validated) {
            $workOrder = WorkOrder::create([
                'branch_id' => $validated['branch_id'],
                'client_id' => $validated['client_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'status' => $validated['status'],
                'payment_status' => 'unpaid',
                'mileage' => $validated['mileage'] ?? null,
                'total_amount' => 0,
                'discount_amount' => 0,
                'final_amount' => 0,
            ]);

            if (!empty($validated['custom_fields'])) {
                $this->saveCustomFields($workOrder, $validated['custom_fields']);
            }
        });

        return redirect()->back()->with('success', 'Заказ-наряд успешно создан');
    }

    public function update(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'status' => ['required', 'string', Rule::in($this->activeStatusValues())],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($validated, $workOrder) {
            $workOrder->update([
                'branch_id' => $validated['branch_id'],
                'client_id' => $validated['client_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'status' => $validated['status'],
                'mileage' => $validated['mileage'] ?? null,
            ]);

            if (isset($validated['custom_fields'])) {
                $this->saveCustomFields($workOrder, $validated['custom_fields']);
            }
        });

        return redirect()->back()->with('success', 'Заказ-наряд обновлен');
    }

    public function destroy(WorkOrder $workOrder)
    {
        $workOrder->delete();
        return redirect()->back()->with('success', 'Заказ-наряд удален');
    }

    public function updateStatus(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($this->activeStatusValues())],
        ]);

        $workOrder->update(['status' => $validated['status']]);

        return redirect()->back()->with('success', 'Статус обновлён');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:work_orders,id'],
        ]);

        WorkOrder::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные заказы удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:work_orders,id'],
        ]);

        ExportEntitiesJob::dispatch('work_orders', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }

    // --- УПРАВЛЕНИЕ ПОЗИЦИЯМИ (ITEMS) ---

    public function addItem(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'itemable_type' => ['required', 'string', 'in:service,product'],
            'itemable_id' => ['required', 'integer'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $priceCents = (int) round($validated['price'] * 100);
        $discountCents = (int) round(($validated['discount_amount'] ?? 0) * 100);
        $totalCents = max(0, (int) round($validated['quantity'] * $priceCents) - $discountCents);

        $item = $workOrder->items()->create([
            'itemable_type' => $validated['itemable_type'] === 'service' ? Service::class : Product::class,
            'itemable_id' => $validated['itemable_id'],
            'name' => $validated['name'],
            'quantity' => $validated['quantity'],
            'price' => $priceCents,
            'discount_amount' => $discountCents,
            'total' => $totalCents,
        ]);

        if (!empty($validated['employee_ids'])) {
            $item->employees()->sync($validated['employee_ids']);
        }

        $this->recalculateTotals($workOrder);

        return redirect()->back()->with('success', 'Позиция добавлена');
    }

    public function updateItem(Request $request, WorkOrder $workOrder, WorkOrderItem $item)
    {
        if ($item->work_order_id !== $workOrder->id) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($request->has('employee_ids')) {
            $item->employees()->sync($validated['employee_ids'] ?? []);
        }

        $data = [];
        if ($request->has('quantity') || $request->has('price') || $request->has('discount_amount')) {
            $qty = $validated['quantity'] ?? $item->quantity;
            $price = $request->has('price') ? (int) round($validated['price'] * 100) : $item->price;
            $discount = $request->has('discount_amount') ? (int) round($validated['discount_amount'] * 100) : $item->discount_amount;

            $data['quantity'] = $qty;
            $data['price'] = $price;
            $data['discount_amount'] = $discount;
            $data['total'] = max(0, (int) round($qty * $price) - $discount);
        }

        if (!empty($data)) {
            $item->update($data);
        }

        $this->recalculateTotals($workOrder);

        return redirect()->back()->with('success', 'Позиция обновлена');
    }

    public function removeItem(WorkOrder $workOrder, WorkOrderItem $item)
    {
        if ($item->work_order_id !== $workOrder->id) {
            abort(403);
        }
        
        $item->delete();
        $this->recalculateTotals($workOrder);
        
        return redirect()->back()->with('success', 'Позиция удалена');
    }

    public function updateDiscount(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'discount_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $workOrder->discount_amount = (int) round($validated['discount_amount'] * 100);
        $workOrder->save();

        $this->recalculateTotals($workOrder);

        return redirect()->back()->with('success', 'Скидка обновлена');
    }

    // --- ФИНАНСЫ И СКЛАД (ФАЗА 8.3) ---

    public function processPayment(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $amountCents = (int) round($validated['amount'] * 100);

        // Оплата бонусами не может превышать бонусный баланс клиента
        if ($account->type === 'bonus') {
            $rate = (float) (Setting::where('key', 'bonus_rub_per_point')->value('value') ?? 1);
            $client = $workOrder->client;
            $maxBonusCents = $rate > 0 ? (int) floor($client->bonus_points * $rate * 100) : 0;

            if ($amountCents > $maxBonusCents) {
                return redirect()->back()->withErrors(['account_id' => 'Недостаточно бонусов у клиента для списания этой суммы.']);
            }
        }

        try {
            DB::transaction(function () use ($workOrder, $amountCents, $account) {
                FinanceService::processTransaction([
                    'account_id' => $account->id,
                    'branch_id' => $workOrder->branch_id,
                    'type' => 'income',
                    'amount' => $amountCents,
                    'comment' => 'Оплата по заказ-наряду #' . $workOrder->id,
                    'payable_type' => WorkOrder::class,
                    'payable_id' => $workOrder->id,
                ], auth()->id());

                // Списание бонусных баллов клиента пропорционально оплаченной сумме
                if ($account->type === 'bonus') {
                    $rate = (float) (Setting::where('key', 'bonus_rub_per_point')->value('value') ?? 1);
                    $pointsUsed = $rate > 0 ? (int) round(($amountCents / 100) / $rate) : 0;
                    $client = $workOrder->client;
                    $pointsUsed = max(0, min($pointsUsed, $client->bonus_points));

                    if ($pointsUsed > 0) {
                        $client->decrement('bonus_points', $pointsUsed);
                    }
                }

                // Комиссия эквайринга: отдельная расходная транзакция с того же счета.
                // Заказ считается оплаченным на валовую сумму (комиссия не уменьшает погашенный долг клиента).
                if ($account->type === 'acquiring' && $account->commission_percent > 0) {
                    $commissionCents = (int) round($amountCents * $account->commission_percent / 100);

                    if ($commissionCents > 0) {
                        FinanceService::processTransaction([
                            'account_id' => $account->id,
                            'branch_id' => $workOrder->branch_id,
                            'type' => 'expense',
                            'amount' => $commissionCents,
                            'comment' => 'Комиссия эквайринга по заказ-наряду #' . $workOrder->id,
                            'payable_type' => WorkOrder::class,
                            'payable_id' => $workOrder->id,
                        ], auth()->id());
                    }
                }

                $workOrder->syncPaymentStatus();
            });

            return redirect()->back()->with('success', 'Оплата успешно принята');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при оплате: ' . $e->getMessage()]);
        }
    }

    public function completeOrder(Request $request, WorkOrder $workOrder)
    {
        if ($workOrder->status === 'completed') {
            return redirect()->back()->withErrors(['status' => 'Заказ уже завершен']);
        }

        try {
            DB::transaction(function () use ($workOrder) {
                $branch = $workOrder->branch;
                
                foreach ($workOrder->items as $item) {
                    if ($item->itemable_type === Product::class) {
                        $product = $item->itemable;
                        if (!$product) continue;

                        $warehouse = WarehouseResolver::resolveFor($product, $branch);
                        
                        if (!$warehouse) {
                            $productName = is_array($product->name) ? ($product->name['ru'] ?? current($product->name)) : $product->name;
                            throw new Exception("Не удалось определить склад для списания товара: {$productName}");
                        }

                        StockService::deduct(
                            $product, 
                            $warehouse, 
                            $branch->id, 
                            $item->quantity, 
                            $workOrder->id, 
                            auth()->id()
                        );
                    }
                }

                $workOrder->update(['status' => 'completed']);
            });

            return redirect()->back()->with('success', 'Заказ успешно завершен, материалы списаны со склада');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // --- QUICK ADD FROM WORK ORDER ---

    public function storeServiceQuick(Request $request)
    {
        $validated = $request->validate([
            'service_category_id' => ['nullable', 'exists:service_categories,id'],
            'business_direction_id' => ['nullable', 'exists:business_directions,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'prices' => ['nullable', 'array'],
            'duration_minutes' => ['required', 'integer', 'min:0'],
        ]);

        Service::create([
            'service_category_id' => $validated['service_category_id'],
            'business_direction_id' => $validated['business_direction_id'],
            'name' => [app()->getLocale() => $validated['name']],
            'price' => (int) round($validated['price'] * 100),
            'prices' => $validated['prices'] ?? null,
            'duration_minutes' => $validated['duration_minutes'],
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Услуга быстро добавлена в справочник');
    }

    public function storeProductQuick(Request $request)
    {
        $validated = $request->validate([
            'product_category_id' => ['nullable', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'accounting_type' => ['required', 'string', 'in:average,batch'],
        ]);

        Product::create([
            'product_category_id' => $validated['product_category_id'],
            'name' => [app()->getLocale() => $validated['name']],
            'sku' => $validated['sku'],
            'unit' => $validated['unit'],
            'accounting_type' => $validated['accounting_type'],
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Товар быстро добавлен в справочник');
    }

    private function workOrderStatuses()
    {
        return Lookup::where('type', 'work_order_status')
            ->orderBy('sort_order')
            ->get(['id', 'value', 'label', 'color', 'is_active', 'is_system']);
    }

    private function activeStatusValues(): array
    {
        return Lookup::where('type', 'work_order_status')
            ->where('is_active', true)
            ->pluck('value')
            ->all();
    }

    private function recalculateTotals(WorkOrder $workOrder)
    {
        $total = $workOrder->items()->sum('total');
        $discount = $workOrder->discount_amount;
        
        if ($discount > $total) {
            $discount = $total;
        }
        
        $final = $total - $discount;

        $workOrder->update([
            'total_amount' => $total,
            'discount_amount' => $discount,
            'final_amount' => $final,
        ]);

        $workOrder->syncPaymentStatus();
    }

    private function saveCustomFields(WorkOrder $workOrder, array $customFieldsData)
    {
        foreach ($customFieldsData as $key => $value) {
            $def = CustomFieldDefinition::where('entity_type', 'work_order')->where('key', $key)->first();
            
            if ($def) {
                $valData = ['value' => null, 'value_text' => null, 'value_number' => null, 'value_date' => null];
                
                if ($def->type === 'number') {
                    $valData['value_number'] = $value;
                } elseif ($def->type === 'date') {
                    $valData['value_date'] = $value;
                } elseif ($def->type === 'select' || $def->type === 'text') {
                    $valData['value_text'] = is_array($value) ? implode(', ', $value) : $value;
                } elseif ($def->type === 'checkbox') {
                    $valData['value_text'] = $value ? '1' : '0';
                } else {
                    $valData['value'] = json_encode($value);
                }

                CustomFieldValue::updateOrCreate(
                    [
                        'custom_field_definition_id' => $def->id,
                        'entity_type' => 'work_order',
                        'entity_id' => $workOrder->id,
                    ],
                    $valData
                );
            }
        }
    }
}