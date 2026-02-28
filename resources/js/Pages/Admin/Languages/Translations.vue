<script setup>
/**
 * TPIX TRADE - Admin Language Translations
 * Inline editable translation key-value management
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Admin/Modal.vue';

const props = defineProps({
    language: {
        type: Object,
        required: true,
    },
    translations: {
        type: Object,
        default: () => ({}),
    },
    groups: {
        type: Array,
        default: () => ['general', 'navigation', 'trading', 'errors', 'auth', 'wallet', 'settings', 'support'],
    },
});

const activeGroup = ref(props.groups[0] || 'general');
const showAddModal = ref(false);
const editedValues = ref({});
const hasChanges = ref(false);

const currentTranslations = computed(() => {
    return props.translations[activeGroup.value] || {};
});

const editValue = (key, value) => {
    if (!editedValues.value[activeGroup.value]) {
        editedValues.value[activeGroup.value] = {};
    }
    editedValues.value[activeGroup.value][key] = value;
    hasChanges.value = true;
};

const getEditedValue = (key) => {
    return editedValues.value[activeGroup.value]?.[key];
};

const saveTranslations = () => {
    router.put(`/admin/languages/${props.language.id}/translations`, {
        translations: editedValues.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            editedValues.value = {};
            hasChanges.value = false;
        },
    });
};

const addForm = useForm({
    group: '',
    key: '',
    value: '',
});

const openAddModal = () => {
    addForm.group = activeGroup.value;
    addForm.key = '';
    addForm.value = '';
    addForm.clearErrors();
    showAddModal.value = true;
};

const addTranslation = () => {
    addForm.post(`/admin/languages/${props.language.id}/translations/add`, {
        preserveScroll: true,
        onSuccess: () => { showAddModal.value = false; },
    });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
</script>

<template>
    <Head :title="`Translations - ${language.name}`" />

    <AdminLayout :title="`Translations: ${language.name}`">
        <!-- Back Link -->
        <div class="mb-6">
            <Link href="/admin/languages" class="inline-flex items-center gap-2 text-sm text-dark-400 hover:text-white transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Languages
            </Link>
        </div>

        <!-- Language Header -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <span class="text-3xl">{{ language.flag || '🌐' }}</span>
                    <div>
                        <h2 class="text-xl font-semibold text-white">{{ language.name }}</h2>
                        <p class="text-sm text-dark-400">{{ language.native_name }} &middot; {{ language.code.toUpperCase() }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button class="btn-secondary px-4 py-2.5 text-sm opacity-50 cursor-not-allowed" disabled title="Coming soon">
                        Import
                    </button>
                    <button class="btn-secondary px-4 py-2.5 text-sm opacity-50 cursor-not-allowed" disabled title="Coming soon">
                        Export
                    </button>
                    <button @click="openAddModal" class="btn-primary px-4 py-2.5 text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Key
                    </button>
                </div>
            </div>
        </div>

        <!-- Group Tabs -->
        <div class="flex items-center gap-1 mb-6 bg-dark-800/30 p-1 rounded-xl border border-white/5 overflow-x-auto">
            <button
                v-for="group in groups"
                :key="group"
                @click="activeGroup = group"
                class="px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 capitalize whitespace-nowrap"
                :class="activeGroup === group ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white hover:bg-white/5'"
            >
                {{ group }}
            </button>
        </div>

        <!-- Translations Table -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-dark-800/50">
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5 w-1/3">Key</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(value, key) in currentTranslations"
                        :key="key"
                        class="border-b border-white/5 hover:bg-white/5 transition-colors"
                    >
                        <td class="py-3 px-4">
                            <span class="font-mono text-sm text-dark-300">{{ key }}</span>
                        </td>
                        <td class="py-2 px-4">
                            <input
                                type="text"
                                :value="getEditedValue(key) !== undefined ? getEditedValue(key) : value"
                                @input="editValue(key, $event.target.value)"
                                class="w-full bg-transparent border border-transparent rounded-lg px-3 py-2 text-sm text-white focus:bg-dark-800/50 focus:border-dark-600 focus:outline-none transition-all"
                            />
                        </td>
                    </tr>
                </tbody>
                <tbody v-if="Object.keys(currentTranslations).length === 0">
                    <tr>
                        <td colspan="2" class="py-12 text-center">
                            <p class="text-dark-400 text-sm">No translations for this group</p>
                            <button @click="openAddModal" class="mt-2 text-sm text-primary-400 hover:text-primary-300">Add first translation</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Save Bar -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 translate-y-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-4"
        >
            <div v-if="hasChanges" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-40">
                <div class="bg-dark-800 border border-white/10 rounded-2xl px-6 py-3 shadow-glass flex items-center gap-4">
                    <p class="text-sm text-dark-300">You have unsaved changes</p>
                    <button @click="saveTranslations" class="btn-primary px-6 py-2 text-sm">
                        Save All Changes
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Add Translation Modal -->
        <Modal :show="showAddModal" title="Add Translation Key" @close="showAddModal = false">
            <form @submit.prevent="addTranslation" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Group</label>
                    <select v-model="addForm.group" :class="inputClass">
                        <option v-for="group in groups" :key="group" :value="group">{{ group.charAt(0).toUpperCase() + group.slice(1) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Key</label>
                    <input v-model="addForm.key" type="text" :class="inputClass" placeholder="welcome_message" />
                    <p v-if="addForm.errors.key" class="mt-1 text-sm text-red-400">{{ addForm.errors.key }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Value</label>
                    <input v-model="addForm.value" type="text" :class="inputClass" placeholder="Welcome to TPIX TRADE" />
                    <p v-if="addForm.errors.value" class="mt-1 text-sm text-red-400">{{ addForm.errors.value }}</p>
                </div>
            </form>
            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button @click="showAddModal = false" class="px-4 py-2 rounded-xl text-sm text-dark-300 hover:text-white transition-colors">Cancel</button>
                    <button @click="addTranslation" :disabled="addForm.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ addForm.processing ? 'Adding...' : 'Add Translation' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AdminLayout>
</template>
