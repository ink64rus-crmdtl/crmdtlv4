<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Transaction;
use App\Models\WorkOrder;
use App\Services\Documents\DocumentGenerationService;
use App\Services\Documents\DocumentNumerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Фаза 12 — центральный реестр сформированных документов (список/фильтры)
 * + сама генерация, вызываемая и отсюда, и с карточек записей (общий
 * маршрут documents.generate, см. routes/tenant.php).
 */
class DocumentController extends Controller
{
    /**
     * short-key (используется в URL/фильтрах, тот же, что
     * DocumentTemplateController::ENTITY_TYPES) → класс модели + связи,
     * которые нужно подгрузить перед построением плейсхолдеров
     * (DocumentPlaceholderService читает branch/branch.legalEntities/
     * legal_entity_id всегда, плюс специфичные для типа связи).
     */
    private const ENTITY_MODELS = [
        'work_order' => ['class' => WorkOrder::class, 'with' => ['branch.legalEntities', 'legalEntity', 'client', 'vehicle', 'items']],
        'transaction' => ['class' => Transaction::class, 'with' => ['branch.legalEntities']],
        'client' => ['class' => Client::class, 'with' => ['branch.legalEntities']],
    ];

    public function index(Request $request): Response
    {
        $query = Document::with(['template', 'branch.legalEntities', 'documentable', 'supersededBy:id,number'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('document_template_id')) {
            $query->where('document_template_id', $request->input('document_template_id'));
        }

        if ($request->filled('entity_type') && isset(self::ENTITY_MODELS[$request->input('entity_type')])) {
            $query->where('documentable_type', self::ENTITY_MODELS[$request->input('entity_type')]['class']);
        }

        if ($request->filled('legal_entity_id')) {
            $query->whereHas('branch', fn ($q) => $q->where('legal_entity_id', $request->input('legal_entity_id')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q->where('number', 'like', $search)->orWhere('title', 'like', $search));
        }

        $documents = $query->paginate(15)->withQueryString();
        $documents->getCollection()->each->append('is_stale');

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'templates' => DocumentTemplate::where('is_active', true)->get(['id', 'name', 'entity_type']),
            'entityTypes' => DocumentTemplateController::ENTITY_TYPES,
            'filters' => $request->only(['document_template_id', 'entity_type', 'legal_entity_id', 'date_from', 'date_to', 'search']),
        ]);
    }

    /**
     * Список активных шаблонов, применимых к конкретной записи — для кнопки
     * "Сформировать документ" на карточке (Show.vue сам решает, показывать
     * ли кнопку, по непустому списку).
     */
    public function templatesFor(string $entityType)
    {
        if (!isset(self::ENTITY_MODELS[$entityType])) {
            return response()->json(['templates' => []]);
        }

        return response()->json([
            'templates' => DocumentTemplate::where('entity_type', $entityType)->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'document_template_id' => ['required', 'exists:document_templates,id'],
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'integer'],
        ]);

        if (!isset(self::ENTITY_MODELS[$validated['entity_type']])) {
            throw ValidationException::withMessages(['entity_type' => 'Неизвестный тип сущности.']);
        }

        $config = self::ENTITY_MODELS[$validated['entity_type']];
        $entity = $config['class']::with($config['with'])->findOrFail($validated['entity_id']);
        $template = DocumentTemplate::findOrFail($validated['document_template_id']);

        if ($template->entity_type !== $validated['entity_type']) {
            throw ValidationException::withMessages(['document_template_id' => 'Этот шаблон не подходит для выбранной записи.']);
        }

        try {
            $document = DocumentGenerationService::generate($template, $validated['entity_type'], $entity);
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Документ №' . $document->number . ' сформирован');
    }

    /**
     * Формирует НОВЫЙ документ (новый номер) по тому же шаблону и той же
     * записи, но с ТЕКУЩИМИ данными — старый документ не удаляет, только
     * помечает superseded_by_document_id: в UI он перестаёт быть активным
     * предупреждением "устарел, перегенерируйте" и становится нейтральной
     * пометкой "заменён документом №X" (см. Document::isStale() — "устарел"
     * не значит "неверный", это зафиксированный во времени артефакт, что
     * реально было отправлено клиенту раньше — история не переписывается).
     */
    public function regenerateAsNew(Document $document)
    {
        [$template, $entityType, $entity, $error] = $this->resolveForRegeneration($document);

        if ($error) {
            return redirect()->back()->withErrors(['error' => $error]);
        }

        try {
            $newDocument = DocumentGenerationService::generate($template, $entityType, $entity);
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        $document->update(['superseded_by_document_id' => $newDocument->id]);

        return redirect()->back()->with('success', 'Сформирован новый документ №' . $newDocument->number . ', прежний сохранён в истории');
    }

    /**
     * "Заменить этот документ" — в отличие от regenerateAsNew(), номер и id
     * остаются теми же (DocumentGenerationService::replace() не обращается
     * к DocumentNumerator), меняется только содержимое PDF на актуальное —
     * для случая, когда пользователю не нужна отдельная запись в истории,
     * а нужно просто исправить/освежить уже выпущенный документ.
     */
    public function replace(Document $document)
    {
        [$template, $entityType, $entity, $error] = $this->resolveForRegeneration($document);

        if ($error) {
            return redirect()->back()->withErrors(['error' => $error]);
        }

        try {
            DocumentGenerationService::replace($document, $template, $entityType, $entity);
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Документ №' . $document->number . ' заменён актуальными данными');
    }

    /**
     * @return array{0: ?DocumentTemplate, 1: ?string, 2: ?\Illuminate\Database\Eloquent\Model, 3: ?string} [шаблон, short-key типа сущности, сущность, текст ошибки]
     */
    private function resolveForRegeneration(Document $document): array
    {
        $template = $document->template;

        if (!$template) {
            return [null, null, null, 'Шаблон, по которому сформирован документ, был удалён.'];
        }

        $entityType = collect(self::ENTITY_MODELS)->search(fn ($config) => $config['class'] === $document->documentable_type);

        if ($entityType === false) {
            return [null, null, null, 'Неизвестный тип связанной записи.'];
        }

        $config = self::ENTITY_MODELS[$entityType];
        $entity = $config['class']::with($config['with'])->find($document->documentable_id);

        if (!$entity) {
            return [null, null, null, 'Связанная запись была удалена.'];
        }

        return [$template, $entityType, $entity, null];
    }

    public function destroy(Document $document)
    {
        DocumentNumerator::reclaimIfLast($document);
        $document->clearMediaCollection('file');
        $document->delete();

        return redirect()->back()->with('success', 'Документ №' . $document->number . ' удалён');
    }

    public function download(Document $document)
    {
        $media = $document->getFirstMedia('file');

        if (!$media) {
            abort(404, 'Файл документа не найден');
        }

        return ResponseFacade::download($media->getPath(), $media->file_name);
    }

    /**
     * Открывает PDF inline (не Content-Disposition: attachment, как
     * download()) — браузер показывает его своим встроенным просмотрщиком
     * в новой вкладке, у которого уже есть кнопка печати/Ctrl+P. Отдельного
     * "отправить на физический принтер" API у браузера нет — это стандартный
     * путь печати документа из веб-приложения (тот же, что у amoCRM/Bitrix24).
     */
    public function print(Document $document)
    {
        $media = $document->getFirstMedia('file');

        if (!$media) {
            abort(404, 'Файл документа не найден');
        }

        return ResponseFacade::file($media->getPath(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $media->file_name . '"',
        ]);
    }
}
