<script setup>
/**
 * TPIX TRADE - Admin Languages Management
 * Language list with add/edit/set-default functionality
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/Admin/DataTable.vue';
import Modal from '@/Components/Admin/Modal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';

const props = defineProps({
    languages: {
        type: Array,
        default: () => [],
    },
});

const columns = [
    { key: 'flag', label: '' },
    { key: 'code', label: 'Code', sortable: true },
    { key: 'name', label: 'Name', sortable: true },
    { key: 'native_name', label: 'Native Name' },
    { key: 'is_active', label: 'Active', align: 'center' },
    { key: 'is_default', label: 'Default', align: 'center' },
    { key: 'actions', label: 'Actions', align: 'right' },
];

const showModal = ref(false);
const showDeleteConfirm = ref(false);
const editingLanguage = ref(null);
const deletingLanguage = ref(null);

const form = useForm({
    code: '',
    name: '',
    native_name: '',
    flag: '',
    is_active: true,
});

const openCreateModal = () => {
    editingLanguage.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEditModal = (lang) => {
    editingLanguage.value = lang;
    form.code = lang.code;
    form.name = lang.name;
    form.native_name = lang.native_name || '';
    form.flag = lang.flag || '';
    form.is_active = lang.is_active;
    form.clearErrors();
    showModal.value = true;
};

const saveLanguage = () => {
    if (editingLanguage.value) {
        form.put(`/admin/languages/${editingLanguage.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        form.post('/admin/languages', {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    }
};

const confirmDelete = (lang) => {
    deletingLanguage.value = lang;
    showDeleteConfirm.value = true;
};

const deleteLanguage = () => {
    router.delete(`/admin/languages/${deletingLanguage.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showDeleteConfirm.value = false; },
    });
};

const toggleActive = (lang) => {
    router.put(`/admin/languages/${lang.id}/toggle`, {}, { preserveScroll: true });
};

const setDefault = (lang) => {
    router.put(`/admin/languages/${lang.id}/default`, {}, { preserveScroll: true });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
</script>

<template>
    <Head title="Languages" />

    <AdminLayout title="Languages">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Languages</h2>
                <p class="text-sm text-dark-400 mt-1">Manage platform languages and translations</p>
            </div>
            <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Language
            </button>
        </div>

        <!-- Table -->
        <DataTable :columns="columns" :data="languages">
            <template #cell-flag="{ row }">
                <span class="text-2xl">{{ row.flag || '🌐' }}</span>
            </template>
            <template #cell-code="{ value }">
                <span class="font-mono text-primary-400 uppercase">{{ value }}</span>
            </template>
            <template #cell-name="{ value }">
                <span class="font-medium text-white">{{ value }}</span>
            </template>
            <template #cell-native_name="{ value }">
                <span class="text-dark-300">{{ value || '-' }}</span>
            </template>
            <template #cell-is_active="{ row }">
                <button @click.stop="toggleActive(row)" class="flex items-center justify-center">
                    <span v-if="row.is_active" class="w-2.5 h-2.5 rounded-full bg-green-400"></span>
                    <span v-else class="w-2.5 h-2.5 rounded-full bg-dark-600"></span>
                </button>
            </template>
            <template #cell-is_default="{ row }">
                <div class="flex items-center justify-center">
                    <span v-if="row.is_default" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20">
                        Default
                    </span>
                    <button v-else @click.stop="setDefault(row)" class="text-xs text-dark-500 hover:text-primary-400 transition-colors">
                        Set Default
                    </button>
                </div>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-2" @click.stop>
                    <Link
                        :href="`/admin/languages/${row.id}/translations`"
                        class="p-1.5 rounded-lg text-dark-400 hover:text-primary-400 hover:bg-primary-500/10 transition-colors"
                        title="Manage Translations"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                        </svg>
                    </Link>
                    <button @click="openEditModal(row)" class="p-1.5 rounded-lg text-dark-400 hover:text-white hover:bg-white/5 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                    <button v-if="!row.is_default" @click="confirmDelete(row)" class="p-1.5 rounded-lg text-dark-400 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </template>
        </DataTable>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" :title="editingLanguage ? 'Edit Language' : 'Add Language'" @close="showModal = false">
            <form @submit.prevent="saveLanguage" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Language Code</label>
                        <input v-model="form.code" type="text" :class="inputClass" placeholder="en" maxlength="5" :disabled="!!editingLanguage" />
                        <p v-if="form.errors.code" class="mt-1 text-sm text-red-400">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Flag Emoji</label>
                        <input v-model="form.flag" type="text" :class="inputClass" placeholder="🇺🇸" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Name (English)</label>
                    <input v-model="form.name" type="text" :class="inputClass" placeholder="English" />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-400">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Native Name</label>
                    <input v-model="form.native_name" type="text" :class="inputClass" placeholder="English" />
                </div>
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium text-dark-300">Active</label>
                    <button type="button" @click="form.is_active = !form.is_active" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="form.is_active ? 'bg-primary-500' : 'bg-dark-600'">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"></span>
                    </button>
                </div>
            </form>
            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button @click="showModal = false" class="px-4 py-2 rounded-xl text-sm text-dark-300 hover:text-white transition-colors">Cancel</button>
                    <button @click="saveLanguage" :disabled="form.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ form.processing ? 'Saving...' : (editingLanguage ? 'Update' : 'Create') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete Language"
            :message="`Are you sure you want to delete '${deletingLanguage?.name}'? All translations for this language will be removed.`"
            confirm-text="Delete"
            :danger="true"
            @confirm="deleteLanguage"
            @cancel="showDeleteConfirm = false"
        />
    </AdminLayout>
</template>
