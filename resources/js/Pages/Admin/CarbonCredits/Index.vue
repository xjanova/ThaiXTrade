<script setup>
/**
 * TPIX TRADE - Admin Carbon Credits Management
 * จัดการระบบ Carbon Credit — โปรเจกต์, สถิติ, CRUD
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    projects: Object,
    stats: Object,
    currentStatus: String,
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editProject = ref(null);

const form = ref({
    name: '',
    description: '',
    location: '',
    country: 'TH',
    project_type: 'reforestation',
    standard: 'VCS',
    registry_id: '',
    total_credits: '',
    price_per_credit_usd: '',
    price_per_credit_tpix: '',
    vintage_year: new Date().getFullYear(),
    status: 'draft',
    is_featured: false,
});

const projectTypes = [
    { value: 'reforestation', label: 'Reforestation' },
    { value: 'renewable_energy', label: 'Renewable Energy' },
    { value: 'methane_capture', label: 'Methane Capture' },
    { value: 'ocean_cleanup', label: 'Ocean Cleanup' },
    { value: 'carbon_capture', label: 'Carbon Capture' },
    { value: 'biodiversity', label: 'Biodiversity' },
    { value: 'other', label: 'Other' },
];

function resetForm() {
    form.value = {
        name: '', description: '', location: '', country: 'TH',
        project_type: 'reforestation', standard: 'VCS', registry_id: '',
        total_credits: '', price_per_credit_usd: '', price_per_credit_tpix: '',
        vintage_year: new Date().getFullYear(), status: 'draft', is_featured: false,
    };
}

function openCreate() {
    resetForm();
    showCreateModal.value = true;
}

function openEdit(project) {
    editProject.value = project;
    form.value = { ...project };
    showEditModal.value = true;
}

function submitCreate() {
    router.post('/admin/carbon-credits', form.value, {
        onSuccess: () => { showCreateModal.value = false; },
    });
}

function submitEdit() {
    router.put(`/admin/carbon-credits/${editProject.value.id}`, form.value, {
        onSuccess: () => { showEditModal.value = false; },
    });
}

function deleteProject(id) {
    if (confirm('Are you sure?')) {
        router.delete(`/admin/carbon-credits/${id}`);
    }
}

function filterByStatus(status) {
    router.get('/admin/carbon-credits', status ? { status } : {}, { preserveState: true });
}

function getStatusBadge(status) {
    const map = {
        draft: 'text-gray-400 bg-gray-500/10',
        active: 'text-green-400 bg-green-500/10',
        sold_out: 'text-yellow-400 bg-yellow-500/10',
        expired: 'text-red-400 bg-red-500/10',
        suspended: 'text-red-400 bg-red-500/10',
    };
    return map[status] || 'text-gray-400 bg-gray-500/10';
}
</script>

<template>
    <AdminLayout title="Carbon Credits">
        <!-- Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-white">{{ stats?.total_projects || 0 }}</p>
                <p class="text-xs text-gray-400">Total Projects</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-green-400">{{ Number(stats?.total_credits_retired || 0).toLocaleString() }}</p>
                <p class="text-xs text-gray-400">tCO2 Retired</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-accent-400">${{ Number(stats?.total_revenue_usd || 0).toLocaleString() }}</p>
                <p class="text-xs text-gray-400">Revenue (USD)</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-warm-400">{{ stats?.unique_buyers || 0 }}</p>
                <p class="text-xs text-gray-400">Unique Buyers</p>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex gap-2">
                <button
                    v-for="f in [
                        { value: null, label: 'All' },
                        { value: 'draft', label: 'Draft' },
                        { value: 'active', label: 'Active' },
                        { value: 'sold_out', label: 'Sold Out' },
                    ]"
                    :key="f.value"
                    @click="filterByStatus(f.value)"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all"
                    :class="currentStatus === f.value ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white hover:bg-white/5'"
                >
                    {{ f.label }}
                </button>
            </div>
            <button @click="openCreate" class="btn-primary px-4 py-2 text-sm">
                + New Project
            </button>
        </div>

        <!-- Table -->
        <div class="glass-dark rounded-xl border border-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left p-4 text-gray-400 font-medium">Project</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Type</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Credits</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Price</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Status</th>
                            <th class="text-right p-4 text-gray-400 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="project in projects?.data || []"
                            :key="project.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors"
                        >
                            <td class="p-4">
                                <p class="font-medium text-white">{{ project.name }}</p>
                                <p class="text-xs text-gray-400">{{ project.location }} ({{ project.country }})</p>
                            </td>
                            <td class="p-4 text-gray-300 capitalize">{{ project.project_type?.replace(/_/g, ' ') }}</td>
                            <td class="p-4">
                                <p class="text-white">{{ Number(project.available_credits).toLocaleString() }} / {{ Number(project.total_credits).toLocaleString() }}</p>
                                <p class="text-xs text-gray-400">{{ Number(project.retired_credits).toLocaleString() }} retired</p>
                            </td>
                            <td class="p-4 text-green-400 font-mono">${{ project.price_per_credit_usd }}</td>
                            <td class="p-4">
                                <span :class="['text-xs px-2.5 py-1 rounded-full font-medium', getStatusBadge(project.status)]">
                                    {{ project.status }}
                                </span>
                            </td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button @click="openEdit(project)" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 text-xs font-medium hover:bg-white/10">Edit</button>
                                    <button @click="deleteProject(project.id)" class="px-3 py-1.5 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!projects?.data?.length">
                            <td colspan="6" class="p-8 text-center text-gray-400">No projects found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showCreateModal || showEditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="showCreateModal = false; showEditModal = false"></div>
                    <div class="relative glass-dark p-6 rounded-2xl border border-white/10 max-w-2xl w-full max-h-[85vh] overflow-y-auto">
                        <h3 class="text-lg font-bold text-white mb-6">{{ showEditModal ? 'Edit Project' : 'New Carbon Project' }}</h3>

                        <form @submit.prevent="showEditModal ? submitEdit() : submitCreate()" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Name *</label>
                                <input v-model="form.name" type="text" class="trading-input w-full" required />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Description *</label>
                                <textarea v-model="form.description" rows="3" class="trading-input w-full resize-none" required></textarea>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Location *</label>
                                    <input v-model="form.location" type="text" class="trading-input w-full" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Country Code *</label>
                                    <input v-model="form.country" type="text" maxlength="2" class="trading-input w-full uppercase" required />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Project Type</label>
                                    <select v-model="form.project_type" class="trading-input w-full">
                                        <option v-for="pt in projectTypes" :key="pt.value" :value="pt.value">{{ pt.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Standard</label>
                                    <input v-model="form.standard" type="text" class="trading-input w-full" />
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Total Credits *</label>
                                    <input v-model.number="form.total_credits" type="number" min="1" class="trading-input w-full" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Price (USD) *</label>
                                    <input v-model.number="form.price_per_credit_usd" type="number" step="0.01" min="0.01" class="trading-input w-full" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Vintage Year</label>
                                    <input v-model.number="form.vintage_year" type="number" min="2000" max="2050" class="trading-input w-full" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1">Status</label>
                                    <select v-model="form.status" class="trading-input w-full">
                                        <option value="draft">Draft</option>
                                        <option value="active">Active</option>
                                        <option value="suspended">Suspended</option>
                                    </select>
                                </div>
                                <div class="flex items-end pb-1">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input v-model="form.is_featured" type="checkbox" class="w-4 h-4 rounded bg-dark-800 border-white/20" />
                                        <span class="text-sm text-gray-300">Featured Project</span>
                                    </label>
                                </div>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <button type="button" @click="showCreateModal = false; showEditModal = false" class="flex-1 btn-secondary py-2.5">Cancel</button>
                                <button type="submit" class="flex-1 btn-primary py-2.5">{{ showEditModal ? 'Update' : 'Create' }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AdminLayout>
</template>
