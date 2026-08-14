<?php

namespace Tests\Unit;

use App\Services\Documents\DocumentRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DocumentRendererTest extends TestCase
{
    #[Test]
    public function true_condition_keeps_block_content(): void
    {
        $html = (new DocumentRenderer)->render(
            '<p>до</p>{{#if work_order.vat_exclusive}}<p>НДС {{work_order.vat_rate}}</p>{{/if}}<p>после</p>',
            ['work_order.vat_exclusive' => '1', 'work_order.vat_rate' => '20%']
        );

        $this->assertStringContainsString('<p>НДС 20%</p>', $html);
        $this->assertStringContainsString('<p>до</p>', $html);
        $this->assertStringContainsString('<p>после</p>', $html);
    }

    #[Test]
    public function false_condition_removes_block_entirely_without_orphaned_markers(): void
    {
        $html = (new DocumentRenderer)->render(
            '<p>до</p>{{#if work_order.vat_exclusive}}<p>НДС {{work_order.vat_rate}}</p>{{/if}}<p>после</p>',
            ['work_order.vat_exclusive' => '', 'work_order.vat_rate' => '']
        );

        $this->assertStringNotContainsString('НДС', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
        $this->assertStringContainsString('<p>до</p><p>после</p>', $html);
    }

    #[Test]
    public function missing_condition_key_defaults_to_hidden(): void
    {
        $html = (new DocumentRenderer)->render(
            '<p>до</p>{{#if work_order.typo_key}}<p>секрет</p>{{/if}}<p>после</p>',
            ['work_order.vat_rate' => '20%']
        );

        $this->assertStringNotContainsString('секрет', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    #[Test]
    public function adjacent_mutually_exclusive_conditions_render_only_the_true_one(): void
    {
        $body = '{{#if a}}<p>A</p>{{/if}}{{#if b}}<p>B</p>{{/if}}';

        $html = (new DocumentRenderer)->render($body, ['a' => '', 'b' => '1']);

        $this->assertStringNotContainsString('<p>A</p>', $html);
        $this->assertStringContainsString('<p>B</p>', $html);
    }

    #[Test]
    public function condition_inside_table_row_resolves_per_row_context(): void
    {
        $body = '<table><tr><td>{{item.name}}</td><td>{{#if item.has_vat}}НДС {{item.vat_rate}}{{/if}}</td></tr></table>';

        $html = (new DocumentRenderer)->render($body, [], [
            'items' => [
                ['item.name' => 'Полировка', 'item.has_vat' => '1', 'item.vat_rate' => '20%'],
                ['item.name' => 'Мойка', 'item.has_vat' => '', 'item.vat_rate' => ''],
            ],
        ]);

        $this->assertStringContainsString('Полировка</td><td>НДС 20%', $html);
        $this->assertStringContainsString('Мойка</td><td></td>', $html);
    }

    #[Test]
    public function condition_inside_table_row_can_reference_global_flat_context(): void
    {
        $body = '<table><tr><td>{{item.name}}</td><td>{{#if work_order.vat_exclusive}}сверху{{/if}}</td></tr></table>';

        $html = (new DocumentRenderer)->render(
            $body,
            ['work_order.vat_exclusive' => '1'],
            ['items' => [['item.name' => 'Полировка']]]
        );

        $this->assertStringContainsString('Полировка</td><td>сверху</td>', $html);
    }

    #[Test]
    public function unclosed_condition_marker_is_stripped_without_leaking(): void
    {
        $html = (new DocumentRenderer)->render(
            '<p>до</p>{{#if work_order.vat_exclusive}}<p>текст без закрытия</p>',
            ['work_order.vat_exclusive' => '1']
        );

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
    }

    #[Test]
    public function body_without_any_condition_renders_unchanged_by_the_new_pass(): void
    {
        $body = '<p>{{client.name}}</p><table><tr><td>{{item.name}}</td></tr></table>';

        $html = (new DocumentRenderer)->render(
            $body,
            ['client.name' => 'Иван'],
            ['items' => [['item.name' => 'Полировка'], ['item.name' => 'Мойка']]]
        );

        $this->assertStringContainsString('<p>Иван</p>', $html);
        $this->assertStringContainsString('<td>Полировка</td>', $html);
        $this->assertStringContainsString('<td>Мойка</td>', $html);
    }
}
