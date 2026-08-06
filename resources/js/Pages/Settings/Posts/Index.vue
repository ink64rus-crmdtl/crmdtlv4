<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import draggable from 'vuedraggable';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    posts: Object,
    filters: Object,
    branches: Array,
    businessDirections: Array,
    hasBranches: Boolean,
});

const isModalOpen = ref(false);
const editingPost = ref(null);

const form = useForm({
    branch_id: '',
    name: '',
    is_active: true,
    prevent_overlapping_appointments: false,
    business_direction_ids: [],
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('settings.posts.index'), {
        search: search.value,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.posts.data.length > 0 && selectedIds.value.length === props.posts.data.length,
    set: (value) => {
        selectedIds.value = value ? props.posts.data.map(p => p.id) : [];
    }
});

const bulkDelete = () => {
    if (confirm(`Удалить выбранные посты (${selectedIds.value.length})?`)) {
        router.post(route('settings.posts.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => { selectedIds.value = []; }
        });
    }
};
// ----------------------------------------

// --- РУЧНАЯ СОРТИРОВКА (drag-and-drop) ---
const sortedPosts = ref([...props.posts.data]);
watch(() => props.posts.data, (data) => { sortedPosts.value = [...data]; });

const onPostsReordered = () => {
    router.post(route('settings.posts.reorder'), {
        ids: sortedPosts.value.map(p => p.id),
    }, { preserveScroll: true, preserveState: true });
};
// ----------------------------------------

const openModal = (post = null) => {
    editingPost.value = post;
    if (post) {
        form.branch_id = post.branch_id || '';
        form.name = post.name;
        form.is_active = Boolean(post.is_active);
        form.prevent_overlapping_appointments = Boolean(post.prevent_overlapping_appointments);
        form.business_direction_ids = post.business_directions ? post.business_directions.map(d => d.id) : [];
    } else {
        form.reset();
        form.is_active = true;
        form.prevent_overlapping_appointments = false;
        form.business_direction_ids = [];
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingPost.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingPost.value) {
        form.put(route('settings.posts.update', editingPost.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('settings.posts.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deletePost = (post) => {
    if (confirm(`Удалить пост "${post.name}"?`)) {
        form.delete(route('settings.posts.destroy', post.id));
    }
};
</script>

<template>
    <Head title="Посты" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">

            <SettingsNav />

            <PageHelper title="Для чего нужны Посты?">
                <p><strong>Посты</strong> — это физические места оказания услуги (боксы, подъёмники, зоны мойки). Они используются для визуального распределения загрузки в календаре записей (вид «по постам»).</p>
                <p v-if="hasBranches">У вас уже добавлены филиалы — каждый пост обязательно привязывается к конкретному филиалу.</p>
                <p v-else>Филиалы пока не добавлены — посты можно создавать без привязки, как общие посты детейлинг-центра. Как только вы добавите первый филиал, новые посты нужно будет привязывать к нему.</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Посты</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление постами/боксами {{ hasBranches ? 'по филиалам' : 'детейлинг-центра' }}
                    </p>
                </div>
            </div>

            <BulkActions
                v-if="selectedIds.length > 0"
                :selectedCount="selectedIds.length"
                noun="постов"
                @delete="bulkDelete"
            />

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    placeholder="Поиск по названию..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Добавить пост
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-2 border-b border-gray-200 dark:border-gray-700 w-8"></th>
                                <th class="py-3 px-4 w-10 border-b border-gray-200 dark:border-gray-700 text-center">
                                    <input type="checkbox" v-model="selectAll" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Филиал</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Направления</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody v-if="posts.data.length === 0" class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr>
                                <td colspan="7" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Посты не найдены.
                                </td>
                            </tr>
                        </tbody>
                        <draggable
                            v-else
                            v-model="sortedPosts"
                            tag="tbody"
                            item-key="id"
                            handle=".post-drag-handle"
                            @end="onPostsReordered"
                        >
                            <template #item="{ element: post }">
                                <tr class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td class="py-4 px-2 text-center">
                                        <i class="ri-draggable post-drag-handle text-gray-400 cursor-grab active:cursor-grabbing" title="Перетащить для сортировки"></i>
                                    </td>
                                    <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center">
                                        <input type="checkbox" :value="post.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    </td>
                                    <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 font-semibold">
                                        <div class="flex items-center gap-2">
                                            <i class="ri-tools-fill text-primary"></i>
                                            {{ post.name }}
                                        </div>
                                    </td>
                                    <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                        <span v-if="post.branch" class="inline-flex items-center gap-1 py-0.5 px-2 rounded bg-gray-100 dark:bg-gray-700 text-xs font-medium text-gray-700 dark:text-gray-300">
                                            <i class="ri-store-2-line"></i> {{ post.branch.name }}
                                        </span>
                                        <span v-else class="text-xs text-gray-400 dark:text-gray-500">Общий (без филиала)</span>
                                    </td>
                                    <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                        <div class="flex flex-wrap gap-1.5" v-if="post.business_directions && post.business_directions.length > 0">
                                            <span v-for="d in post.business_directions" :key="d.id" class="inline-flex items-center gap-1 py-0.5 px-2 rounded bg-info/10 text-info text-xs font-medium">
                                                <i class="ri-node-tree"></i> {{ d.name }}
                                            </span>
                                        </div>
                                        <span v-else class="text-xs text-gray-400 dark:text-gray-500">Любое направление</span>
                                    </td>
                                    <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                        <span :class="[post.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                            {{ post.is_active ? 'Активен' : 'Неактивен' }}
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-sm text-right border-b border-gray-100 dark:border-gray-700/50 space-x-2">
                                        <button @click="openModal(post)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать">
                                            <i class="ri-pencil-line"></i>
                                        </button>
                                        <button @click="deletePost(post)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                            <i class="ri-delete-bin-line"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </draggable>
                    </table>
                </div>
                <Pagination :meta="posts" />
            </div>
        </div>

        <!-- Модальное окно -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingPost ? 'Редактирование поста' : 'Новый пост' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название поста <span class="text-danger">*</span></label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                placeholder="Например: Пост 1 / Бокс А"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-danger">{{ form.errors.name }}</p>
                        </div>

                        <div v-if="hasBranches">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал <span class="text-danger">*</span></label>
                            <select v-model="form.branch_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите филиал...</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                            </select>
                            <p v-if="form.errors.branch_id" class="mt-1 text-xs text-danger">{{ form.errors.branch_id }}</p>
                        </div>
                        <p v-else class="text-xs text-gray-500 dark:text-gray-400">Филиалы не добавлены — пост будет общим для всего детейлинг-центра.</p>

                        <!-- Направления деятельности -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3">Направления деятельности на этом посту</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Если не выбрать ни одного, пост подходит для любого направления.</p>
                            <div v-if="businessDirections.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label v-for="direction in businessDirections" :key="direction.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="direction.id" v-model="form.business_direction_ids" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors">{{ direction.name }}</span>
                                </label>
                            </div>
                            <p v-else class="text-xs text-gray-400">Направления деятельности ещё не добавлены (Настройки → Направления).</p>
                        </div>

                        <!-- Запрет пересекающихся записей -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <div class="flex items-center">
                                <div @click="form.prevent_overlapping_appointments = !form.prevent_overlapping_appointments" :class="[form.prevent_overlapping_appointments ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.prevent_overlapping_appointments ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-2.5 block text-sm font-semibold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.prevent_overlapping_appointments = !form.prevent_overlapping_appointments">
                                    Не допускать пересекающиеся записи на этот пост
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 ml-11">Если включено, система не даст создать или перенести запись на этот пост, если её время пересекается с уже существующей (неотменённой) записью на нём. Отменённые записи в расчёт не берутся.</p>
                        </div>

                        <div class="flex items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Пост активен
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Отмена
                        </button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Сохранить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
