<?php

namespace Tests\Agent;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Position;
use App\Services\Documents\DocumentPlaceholderService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Сущность «Сотрудник» в шаблонах документов (Фаза 12, entity_type='employee')
 * — EmployeeDocumentPlaceholderService::employeePlaceholders() и резолюция
 * юрлица из локации сотрудника. Покрывает полный диапазон плейсхолдеров:
 * ФИО, должность (translatable), даты, паспорт из JSON, оклад, тип оформления
 * и флаги-условия для {{#if ...}}.
 */
class EmployeeDocumentPlaceholderTest extends TenantAgentTestCase
{
    private Branch $branch;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Тестовая локация']);
        $this->position = Position::create(['name' => 'Мастер', 'is_active' => true]);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'branch_id' => $this->branch->id,
            'position_id' => $this->position->id,
            'type' => 'staff',
            'is_active' => true,
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'middle_name' => 'Иванович',
            'phone' => '+7 900 000-00-00',
            'personal_email' => 'ivanov@example.com',
            'birth_date' => '1990-05-15',
            'hire_date' => '2020-01-10',
            'passport_data' => [
                'series' => '4501',
                'number' => '123456',
                'issued_by' => 'УФМС России по г. Москве',
                'issue_date' => '2010-06-20',
                'department_code' => '770-001',
                'registration_address' => 'г. Москва, ул. Тестовая, д. 1',
            ],
            'salary_amount' => 5000000, // 50 000 ₽
            'self_employed_tax_percent' => 6,
        ], $overrides));
    }

    private function buildFor(Employee $employee): array
    {
        return DocumentPlaceholderService::buildFor('employee', $employee);
    }

    #[Test]
    public function it_builds_full_name_and_drops_empty_middle_name(): void
    {
        $employee = $this->makeEmployee();

        $this->assertSame('Иванов Иван Иванович', $this->buildFor($employee)['flat']['employee.full_name']);
        $this->assertSame('Иванов', $this->buildFor($employee)['flat']['employee.last_name']);
        $this->assertSame('Иван', $this->buildFor($employee)['flat']['employee.first_name']);

        $employee->update(['middle_name' => null]);
        $this->assertSame('Иванов Иван', $this->buildFor($employee->fresh())['flat']['employee.full_name']);
    }

    #[Test]
    public function it_resolves_localized_position_name(): void
    {
        $employee = $this->makeEmployee();

        $flat = $this->buildFor($employee)['flat'];

        $this->assertSame('Мастер', $flat['employee.position']);
        $this->assertSame('', $flat['employee.secondary_position']);
    }

    #[Test]
    public function it_formats_dates_and_passport_json(): void
    {
        $employee = $this->makeEmployee();
        $flat = $this->buildFor($employee)['flat'];

        $this->assertSame('15.05.1990', $flat['employee.birth_date']);
        $this->assertSame('10.01.2020', $flat['employee.hire_date']);
        $this->assertSame('', $flat['employee.termination_date']);

        $this->assertSame('4501', $flat['employee.passport_series']);
        $this->assertSame('123456', $flat['employee.passport_number']);
        $this->assertSame('УФМС России по г. Москве', $flat['employee.passport_issued_by']);
        $this->assertSame('2010-06-20', $flat['employee.passport_issue_date']);
        $this->assertSame('770-001', $flat['employee.passport_department_code']);
        $this->assertSame('г. Москва, ул. Тестовая, д. 1', $flat['employee.registration_address']);
    }

    #[Test]
    public function it_formats_salary_and_self_employed_tax(): void
    {
        $employee = $this->makeEmployee();
        $flat = $this->buildFor($employee)['flat'];

        $this->assertSame('50 000,00', $flat['employee.salary_amount']);
        $this->assertSame('6%', $flat['employee.self_employed_tax_percent']);
    }

    #[Test]
    public function it_sets_type_label_and_condition_flags_for_staff(): void
    {
        $employee = $this->makeEmployee();
        $flat = $this->buildFor($employee)['flat'];

        $this->assertSame('В штате', $flat['employee.type_label']);
        $this->assertSame('1', $flat['employee.is_staff']);
        $this->assertSame('', $flat['employee.is_self_employed']);
        $this->assertSame('1', $flat['employee.is_active']);
    }

    #[Test]
    public function it_sets_self_employed_label_and_flags(): void
    {
        $employee = $this->makeEmployee(['type' => 'self_employed']);
        $flat = $this->buildFor($employee)['flat'];

        $this->assertSame('Самозанятый', $flat['employee.type_label']);
        $this->assertSame('', $flat['employee.is_staff']);
        $this->assertSame('1', $flat['employee.is_self_employed']);
    }

    #[Test]
    public function it_sets_secondary_position_and_termination_flags(): void
    {
        $secondary = Position::create(['name' => 'Приёмщик', 'is_active' => true]);
        $employee = $this->makeEmployee([
            'secondary_position_id' => $secondary->id,
            'termination_date' => '2025-12-31',
        ]);

        $flat = $this->buildFor($employee)['flat'];

        $this->assertSame('Приёмщик', $flat['employee.secondary_position']);
        $this->assertSame('1', $flat['employee.has_secondary_position']);
        $this->assertSame('1', $flat['employee.has_termination_date']);
        $this->assertSame('31.12.2025', $flat['employee.termination_date']);
    }

    #[Test]
    public function it_resolves_legal_entity_from_employee_branch_when_exactly_one(): void
    {
        $legalEntity = LegalEntity::create([
            'name' => 'ООО Тест Работодатель',
            'is_active' => true,
            'vat_payer' => false,
        ]);
        $legalEntity->branches()->attach($this->branch->id);

        $employee = $this->makeEmployee();
        $data = $this->buildFor($employee);

        $this->assertNotNull($data['legal_entity']);
        $this->assertSame('ООО Тест Работодатель', $data['flat']['legal_entity.name']);
    }

    #[Test]
    public function it_leaves_legal_entity_empty_when_employee_branch_is_ambiguous(): void
    {
        $first = LegalEntity::create(['name' => 'ООО Первое', 'is_active' => true, 'vat_payer' => false]);
        $second = LegalEntity::create(['name' => 'ООО Второе', 'is_active' => true, 'vat_payer' => false]);
        $first->branches()->attach($this->branch->id);
        $second->branches()->attach($this->branch->id);

        $employee = $this->makeEmployee();
        $data = $this->buildFor($employee);

        $this->assertNull($data['legal_entity']);
        $this->assertArrayNotHasKey('legal_entity.name', $data['flat']);
    }
}
