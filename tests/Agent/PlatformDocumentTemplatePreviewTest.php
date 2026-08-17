<?php

namespace Tests\Agent;

use App\Http\Controllers\Tenant\DocumentTemplateController;
use App\Models\Central\PlatformDocumentTemplate;
use App\Services\Documents\PlatformDocumentTemplateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Предпросмотр библиотеки шаблонов документов (CLAUDE.md, "Предпросмотр
 * библиотеки шаблонов документов") + проверка страновой границы: тенант
 * обязан видеть/просматривать/импортировать ТОЛЬКО шаблоны своей страны
 * и общие (country_code=null), никогда — шаблоны конкретно другой страны,
 * даже зная id напрямую (findVisibleForCurrentTenant()).
 *
 * PlatformDocumentTemplate — central-модель: тестовые записи создаются на
 * central-подключении, транзакция TenantAgentTestCase его НЕ откатывает
 * (см. докблок класса) — поэтому обязательная ручная зачистка в tearDown(),
 * иначе мусор осел бы в РЕАЛЬНОЙ платформенной библиотеке шаблонов.
 */
class PlatformDocumentTemplatePreviewTest extends TenantAgentTestCase
{
    private array $centralTemplateIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->centralTemplateIds)) {
            $ids = $this->centralTemplateIds;
            tenancy()->central(function () use ($ids) {
                PlatformDocumentTemplate::withTrashed()->whereIn('id', $ids)->forceDelete();
            });
        }

        parent::tearDown();
    }

    private function makeCentralTemplate(array $overrides = []): PlatformDocumentTemplate
    {
        $template = tenancy()->central(fn () => PlatformDocumentTemplate::create(array_merge([
            'country_code' => null,
            'name' => 'Тестовый шаблон предпросмотра',
            'entity_type' => 'work_order',
            'format' => 'html',
            'body' => '<p>Итого: {{work_order.total_amount}}</p>',
            'is_active' => true,
        ], $overrides)));

        $this->centralTemplateIds[] = $template->id;

        return $template;
    }

    private function otherCountry(): string
    {
        return tenant('country_code') === 'DE' ? 'RU' : 'DE';
    }

    #[Test]
    public function render_preview_strips_unresolved_placeholders_without_crashing(): void
    {
        $template = $this->makeCentralTemplate(['body' => '<p>Клиент: {{client.name}}, Итого: {{work_order.total_amount}}</p>']);

        $html = PlatformDocumentTemplateService::renderPreview($template);

        $this->assertStringNotContainsString('{{client.name}}', $html);
        $this->assertStringNotContainsString('{{work_order.total_amount}}', $html);
        $this->assertStringContainsString('Клиент:', $html);
    }

    #[Test]
    public function render_preview_strips_conditional_blocks_as_false(): void
    {
        $template = $this->makeCentralTemplate(['body' => '<p>До</p>{{#if work_order.vat_rate}}<p>НДС: {{work_order.vat_rate}}%</p>{{/if}}<p>После</p>']);

        $html = PlatformDocumentTemplateService::renderPreview($template);

        $this->assertStringNotContainsString('НДС:', $html);
        $this->assertStringContainsString('До', $html);
        $this->assertStringContainsString('После', $html);
    }

    #[Test]
    public function find_visible_returns_template_for_tenant_own_country(): void
    {
        $template = $this->makeCentralTemplate(['country_code' => tenant('country_code')]);

        $found = PlatformDocumentTemplateService::findVisibleForCurrentTenant($template->id);

        $this->assertSame($template->id, $found->id);
    }

    #[Test]
    public function find_visible_returns_universal_template(): void
    {
        $template = $this->makeCentralTemplate(['country_code' => null]);

        $found = PlatformDocumentTemplateService::findVisibleForCurrentTenant($template->id);

        $this->assertSame($template->id, $found->id);
    }

    #[Test]
    public function find_visible_throws_for_a_different_countrys_template(): void
    {
        $template = $this->makeCentralTemplate(['country_code' => $this->otherCountry()]);

        $this->expectException(ModelNotFoundException::class);
        PlatformDocumentTemplateService::findVisibleForCurrentTenant($template->id);
    }

    #[Test]
    public function find_visible_throws_for_inactive_template(): void
    {
        $template = $this->makeCentralTemplate(['country_code' => tenant('country_code'), 'is_active' => false]);

        $this->expectException(ModelNotFoundException::class);
        PlatformDocumentTemplateService::findVisibleForCurrentTenant($template->id);
    }

    #[Test]
    public function import_now_rejects_a_different_countrys_template(): void
    {
        // Регрессия на живой пробел: раньше import() доверял id без проверки
        // страны/активности — прямой POST с угаданным id мог импортировать
        // чужой шаблон в обход того, что реально показывает library().
        $template = $this->makeCentralTemplate(['country_code' => $this->otherCountry()]);

        $this->expectException(ModelNotFoundException::class);
        PlatformDocumentTemplateService::import($template->id);
    }

    #[Test]
    public function controller_preview_endpoint_returns_rendered_html_for_visible_template(): void
    {
        $template = $this->makeCentralTemplate(['country_code' => tenant('country_code'), 'body' => '<p>Проверочный текст</p>']);

        $response = app(DocumentTemplateController::class)->previewLibraryTemplate($template->id);
        $data = $response->getData(true);

        $this->assertStringContainsString('Проверочный текст', $data['html']);
    }

    #[Test]
    public function controller_preview_endpoint_rejects_a_different_countrys_template(): void
    {
        $template = $this->makeCentralTemplate(['country_code' => $this->otherCountry()]);

        $this->expectException(ModelNotFoundException::class);
        app(DocumentTemplateController::class)->previewLibraryTemplate($template->id);
    }
}
