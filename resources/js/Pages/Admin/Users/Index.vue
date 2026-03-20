<script setup>
/**
 * TPIX TRADE - Admin User Management
 * CRUD for admin user accounts (super_admin only)
 * Developed by Xman Studio
 */

import { ref, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/Admin/DataTable.vue';
import Modal from '@/Components/Admin/Modal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';

const props = defineProps({
    users: {
        type: Object,
        default: () => ({ data: [], links: [] }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'role', label: 'Role' },
    { key: 'is_active', label: 'Status' },
    { key: 'last_login_at', label: 'Last Login', sortable: true },
    { key: 'actions', label: 'Actions', align: 'right' },
];

const roleColors = {
    super_admin: 'bg-red-500/10 text-red-400 border-red-500/20',
    admin: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
    moderator: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
    support: 'bg-green-500/10 text-green-400 border-green-500/20',
};

const roleLabels = {
    super_admin: 'Super Admin',
    admin: 'Admin',
    moderator: 'Moderator',
    support: 'Support',
};

// Filters
const filterRole = ref(props.filters.role || '');
const filterSearch = ref(props.filters.search || '');

const applyFilters = () => {
    router.get('/admin/users', {
        role: filterRole.value || undefined,
        search: filterSearch.value || undefined,
    }, { preserveState: true, preserveScroll: true });
};

const resetFilters = () => {
    filterRole.value = '';
    filterSearch.value = '';
    router.get('/admin/users', {}, { preserveState: true });
};

// Create/Edit Modal
const showModal = ref(false);
const editingUser = ref(null);
const form = reactive({
    name: '',
    email: '',
    password: '',
    role: 'support',
    is_active: true,
});
const formErrors = ref({});
const formProcessing = ref(false);

const openCreateModal = () => {
    editingUser.value = null;
    form.name = '';
    form.email = '';
    form.password = '';
    form.role = 'support';
    form.is_active = true;
    formErrors.value = {};
    showModal.value = true;
};

const openEditModal = (user) => {
    editingUser.value = user;
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.role = user.role;
    form.is_active = user.is_active;
    formErrors.value = {};
    showModal.value = true;
};

const submitForm = () => {
    formProcessing.value = true;
    formErrors.value = {};

    if (editingUser.value) {
        router.put(`/admin/users/${editingUser.value.id}`, {
            name: form.name,
            email: form.email,
            role: form.role,
            is_active: form.is_active,
        }, {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
            onError: (errors) => { formErrors.value = errors; },
            onFinish: () => { formProcessing.value = false; },
        });
    } else {
        router.post('/admin/users', {
            name: form.name,
            email: form.email,
            password: form.password,
            role: form.role,
            is_active: form.is_active,
        }, {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
            onError: (errors) => { formErrors.value = errors; },
            onFinish: () => { formProcessing.value = false; },
        });
    }
};

// Delete
const showDeleteDialog = ref(false);
const deletingUser = ref(null);
const deleteProcessing = ref(false);

const confirmDelete = (user) => {
    deletingUser.value = user;
    showDeleteDialog.value = true;
};

const handleDelete = () => {
    deleteProcessing.value = true;
    router.delete(`/admin/users/${deletingUser.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { showDeleteDialog.value = false; },
        onFinish: () => { deleteProcessing.value = false; },
    });
};

// Reset password
const showResetDialog = ref(false);
const resetUser = ref(null);
const newPassword = ref('');
const resetProcessing = ref(false);

const openResetPassword = (user) => {
    resetUser.value = user;
    newPassword.value = '';
    showResetDialog.value = true;
};

const handleResetPassword = () => {
    resetProcessing.value = true;
    router.patch(`/admin/users/${resetUser.value.id}/reset-password`, {
        password: newPassword.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { showResetDialog.value = false; },
        onFinish: () => { resetProcessing.value = false; },
    });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 transition-colors';
const selectClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-primary-500';

// Decode HTML entities from Laravel pagination labels (ป้องกัน XSS)
function decodeLabel(html) {
    const txt = document.createElement("textarea");
    txt.innerHTML = html;
    return txt.value;
}
</script>

<template>
    <Head title="User Management" />

    <AdminLayout title="User Management">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Admin Users</h2>
                <p class="text-sm text-dark-400 mt-1">Manage admin panel accounts and permissions</p>
            </div>
            <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add User
            </button>
        </div>

        <!-- Filters -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4 mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Search</label>
                    <input
                        v-model="filterSearch"
                        type="text"
                        placeholder="Name or email..."
                        :class="inputClass"
                        class="!w-64"
                        @keyup.enter="applyFilters"
                    />
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Role</label>
                    <select v-model="filterRole" :class="selectClass" class="!w-44">
                        <option value="">All Roles</option>
                        <option value="super_admin">Super Admin</option>
                        <option value="admin">Admin</option>
                        <option value="moderator">Moderator</option>
                        <option value="support">Support</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="applyFilters" class="btn-primary px-4 py-2.5 text-sm">Filter</button>
                    <button @click="resetFilters" class="px-4 py-2.5 text-sm text-dark-400 hover:text-white transition-colors">Reset</button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <DataTable :columns="columns" :data="users.data" :hoverable="false">
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent-500 via-primary-500 to-warm-500 flex items-center justify-center flex-shrink-0">
                        <span class="text-white font-semibold text-xs">{{ (row.name || 'A').charAt(0).toUpperCase() }}</span>
                    </div>
                    <span class="font-medium text-white">{{ row.name }}</span>
                </div>
            </template>
            <template #cell-email="{ value }">
                <span class="text-dark-300 font-mono text-xs">{{ value }}</span>
            </template>
            <template #cell-role="{ value }">
                <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
                    :class="roleColors[value] || roleColors.support"
                >
                    {{ roleLabels[value] || value }}
                </span>
            </template>
            <template #cell-is_active="{ value }">
                <span
                    class="inline-flex items-center gap-1.5 text-xs font-medium"
                    :class="value ? 'text-green-400' : 'text-red-400'"
                >
                    <span class="w-1.5 h-1.5 rounded-full" :class="value ? 'bg-green-400' : 'bg-red-400'"></span>
                    {{ value ? 'Active' : 'Inactive' }}
                </span>
            </template>
            <template #cell-last_login_at="{ value }">
                <span class="text-dark-400 text-xs">{{ value || 'Never' }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1">
                    <button
                        @click.stop="openEditModal(row)"
                        class="p-1.5 rounded-lg text-dark-400 hover:text-white hover:bg-white/10 transition-colors"
                        title="Edit"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                    <button
                        @click.stop="openResetPassword(row)"
                        class="p-1.5 rounded-lg text-dark-400 hover:text-yellow-400 hover:bg-yellow-500/10 transition-colors"
                        title="Reset Password"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </button>
                    <button
                        @click.stop="confirmDelete(row)"
                        class="p-1.5 rounded-lg text-dark-400 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                        title="Delete"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </template>
        </DataTable>

        <!-- Pagination -->
        <div v-if="users.links && users.links.length > 3" class="flex items-center justify-center gap-1 mt-6">
            <template v-for="link in users.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="px-3 py-2 rounded-lg text-sm transition-colors"
                    :class="link.active ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white hover:bg-white/5'"
                    v-text="decodeLabel(link.label)"
                    preserve-scroll
                />
                <span v-else class="px-3 py-2 text-sm text-dark-600" v-text="decodeLabel(link.label)" />
            </template>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" :title="editingUser ? 'Edit User' : 'Create User'" max-width="md" @close="showModal = false">
            <form @submit.prevent="submitForm" class="space-y-4">
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Name</label>
                    <input v-model="form.name" type="text" :class="inputClass" placeholder="Full name" />
                    <p v-if="formErrors.name" class="text-red-400 text-xs mt-1">{{ formErrors.name }}</p>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Email</label>
                    <input v-model="form.email" type="email" :class="inputClass" placeholder="email@example.com" />
                    <p v-if="formErrors.email" class="text-red-400 text-xs mt-1">{{ formErrors.email }}</p>
                </div>
                <div v-if="!editingUser">
                    <label class="block text-xs text-dark-400 mb-1.5">Password</label>
                    <input v-model="form.password" type="password" :class="inputClass" placeholder="Min 8 characters" />
                    <p v-if="formErrors.password" class="text-red-400 text-xs mt-1">{{ formErrors.password }}</p>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Role</label>
                    <select v-model="form.role" :class="selectClass">
                        <option value="super_admin">Super Admin</option>
                        <option value="admin">Admin</option>
                        <option value="moderator">Moderator</option>
                        <option value="support">Support</option>
                    </select>
                    <p v-if="formErrors.role" class="text-red-400 text-xs mt-1">{{ formErrors.role }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" v-model="form.is_active" class="sr-only peer" />
                        <div class="w-9 h-5 bg-dark-700 peer-focus:ring-2 peer-focus:ring-primary-500/30 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary-500"></div>
                    </label>
                    <span class="text-sm text-dark-300">Active</span>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        @click="showModal = false"
                        class="px-4 py-2 rounded-xl text-sm text-dark-400 hover:text-white transition-colors"
                    >Cancel</button>
                    <button
                        @click="submitForm"
                        :disabled="formProcessing"
                        class="btn-primary px-6 py-2 text-sm disabled:opacity-50"
                    >
                        {{ formProcessing ? 'Saving...' : (editingUser ? 'Update' : 'Create') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirm -->
        <ConfirmDialog
            :show="showDeleteDialog"
            title="Delete Admin User"
            :message="`Are you sure you want to delete ${deletingUser?.name}? This action can be reversed by a database admin.`"
            confirm-text="Delete"
            :danger="true"
            :loading="deleteProcessing"
            @confirm="handleDelete"
            @cancel="showDeleteDialog = false"
        />

        <!-- Reset Password Modal -->
        <Modal :show="showResetDialog" title="Reset Password" max-width="sm" @close="showResetDialog = false">
            <div class="space-y-4">
                <p class="text-sm text-dark-400">Set a new password for <strong class="text-white">{{ resetUser?.name }}</strong></p>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">New Password</label>
                    <input v-model="newPassword" type="password" :class="inputClass" placeholder="Min 8 characters" />
                </div>
            </div>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button @click="showResetDialog = false" class="px-4 py-2 rounded-xl text-sm text-dark-400 hover:text-white transition-colors">Cancel</button>
                    <button
                        @click="handleResetPassword"
                        :disabled="resetProcessing || newPassword.length < 8"
                        class="btn-primary px-6 py-2 text-sm disabled:opacity-50"
                    >
                        {{ resetProcessing ? 'Resetting...' : 'Reset Password' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AdminLayout>
</template>
