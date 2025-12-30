<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

defineProps({
    title: String,
});

const activeTab = ref('blocklist'); // 'blocklist' or 'dmarc'
const loading = ref(false);
const showBlocklistModal = ref(false);
const showDmarcModal = ref(false);
const editingBlocklist = ref(null);
const editingDmarc = ref(null);

// Blocklist monitors
const blocklistMonitors = ref([]);
const blocklistForm = ref({
    type: 'domain',
    target: '',
    active: true,
    check_interval_minutes: 60,
});

// Auto-detect type from target input
const detectType = (target) => {
    if (!target) return 'domain';
    // Simple IP validation regex
    const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
    if (ipRegex.test(target)) {
        // Additional validation: check if each octet is 0-255
        const parts = target.split('.');
        if (parts.length === 4 && parts.every(part => {
            const num = parseInt(part, 10);
            return num >= 0 && num <= 255;
        })) {
            return 'ip';
        }
    }
    return 'domain';
};

// DMARC monitors
const dmarcMonitors = ref([]);
const dmarcForm = ref({
    name: '',
    domain: '',
    report_email: '',
    active: true,
    check_interval_minutes: 1440,
});

const loadBlocklistMonitors = async () => {
    try {
        loading.value = true;
        const response = await window.axios.get('/api/monitors/blocklist', {
            withCredentials: true,
        });
        blocklistMonitors.value = response.data;
    } catch (error) {
        console.error('Failed to load blocklist monitors:', error);
    } finally {
        loading.value = false;
    }
};

const loadDmarcMonitors = async () => {
    try {
        loading.value = true;
        const response = await window.axios.get('/api/monitors/dmarc', {
            withCredentials: true,
        });
        dmarcMonitors.value = response.data;
    } catch (error) {
        console.error('Failed to load DMARC monitors:', error);
    } finally {
        loading.value = false;
    }
};

const openBlocklistModal = (monitor = null) => {
    editingBlocklist.value = monitor;
    if (monitor) {
        blocklistForm.value = {
            type: monitor.type || detectType(monitor.target),
            target: monitor.target,
            active: monitor.active !== undefined ? monitor.active : true,
            check_interval_minutes: monitor.check_interval_minutes || 60,
        };
    } else {
        blocklistForm.value = {
            type: 'domain',
            target: '',
            active: true,
            check_interval_minutes: 60,
        };
    }
    showBlocklistModal.value = true;
};

const openDmarcModal = (monitor = null) => {
    editingDmarc.value = monitor;
    if (monitor) {
        dmarcForm.value = {
            name: monitor.name,
            domain: monitor.domain,
            report_email: monitor.report_email || '',
            active: monitor.active,
            check_interval_minutes: monitor.check_interval_minutes,
        };
    } else {
        dmarcForm.value = {
            name: '',
            domain: '',
            report_email: '',
            active: true,
            check_interval_minutes: 1440,
        };
    }
    showDmarcModal.value = true;
};

const saveBlocklistMonitor = async () => {
    try {
        loading.value = true;
        // Auto-detect type before saving
        const formData = {
            ...blocklistForm.value,
            type: detectType(blocklistForm.value.target),
        };
        
        let monitorId;
        if (editingBlocklist.value) {
            const response = await window.axios.put(`/api/monitors/blocklist/${editingBlocklist.value.id}`, formData, {
                withCredentials: true,
            });
            monitorId = editingBlocklist.value.id;
        } else {
            const response = await window.axios.post('/api/monitors/blocklist', formData, {
                withCredentials: true,
            });
            monitorId = response.data.id;
            
            // Redirect to detail page (check is automatically queued by backend)
            showBlocklistModal.value = false;
            router.visit(`/monitors/blocklist/${monitorId}`);
            return;
        }
        
        showBlocklistModal.value = false;
        await loadBlocklistMonitors();
    } catch (error) {
        console.error('Failed to save blocklist monitor:', error);
        alert('Failed to save monitor: ' + (error.response?.data?.message || error.message));
    } finally {
        loading.value = false;
    }
};

const saveDmarcMonitor = async () => {
    try {
        loading.value = true;
        if (editingDmarc.value) {
            await window.axios.put(`/api/monitors/dmarc/${editingDmarc.value.id}`, dmarcForm.value, {
                withCredentials: true,
            });
        } else {
            await window.axios.post('/api/monitors/dmarc', dmarcForm.value, {
                withCredentials: true,
            });
        }
        showDmarcModal.value = false;
        await loadDmarcMonitors();
    } catch (error) {
        console.error('Failed to save DMARC monitor:', error);
        alert('Failed to save monitor: ' + (error.response?.data?.message || error.message));
    } finally {
        loading.value = false;
    }
};

const deleteBlocklistMonitor = async (id) => {
    if (!confirm('Are you sure you want to delete this monitor?')) return;
    
    try {
        loading.value = true;
        await window.axios.delete(`/api/monitors/blocklist/${id}`, {
            withCredentials: true,
        });
        await loadBlocklistMonitors();
    } catch (error) {
        console.error('Failed to delete blocklist monitor:', error);
        alert('Failed to delete monitor');
    } finally {
        loading.value = false;
    }
};

const deleteDmarcMonitor = async (id) => {
    if (!confirm('Are you sure you want to delete this monitor?')) return;
    
    try {
        loading.value = true;
        await window.axios.delete(`/api/monitors/dmarc/${id}`, {
            withCredentials: true,
        });
        await loadDmarcMonitors();
    } catch (error) {
        console.error('Failed to delete DMARC monitor:', error);
        alert('Failed to delete monitor');
    } finally {
        loading.value = false;
    }
};

const checkBlocklistMonitor = async (id) => {
    try {
        await window.axios.post(`/api/monitors/blocklist/${id}/check`, {}, {
            withCredentials: true,
        });
        alert('Check queued successfully');
        await loadBlocklistMonitors();
    } catch (error) {
        console.error('Failed to check monitor:', error);
        alert('Failed to queue check');
    }
};

const checkDmarcMonitor = async (id) => {
    try {
        await window.axios.post(`/api/monitors/dmarc/${id}/check`, {}, {
            withCredentials: true,
        });
        alert('Check queued successfully');
        await loadDmarcMonitors();
    } catch (error) {
        console.error('Failed to check monitor:', error);
        alert('Failed to queue check');
    }
};

const getStatusBadge = (isBlocklisted) => {
    return isBlocklisted ? 'Blocklisted' : 'OK';
};

const getStatusColor = (isBlocklisted) => {
    return isBlocklisted 
        ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
        : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
};

const getIssueBadge = (hasIssue) => {
    return hasIssue ? 'Has Issue' : 'OK';
};

const getIssueColor = (hasIssue) => {
    return hasIssue 
        ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
        : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
};

onMounted(() => {
    loadBlocklistMonitors();
    loadDmarcMonitors();
});
</script>

<template>
    <AppLayout title="Monitors">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Monitors
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Tabs -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <nav class="-mb-px flex space-x-8 px-6" aria-label="Tabs">
                            <button
                                @click="activeTab = 'blocklist'; loadBlocklistMonitors()"
                                :class="[
                                    activeTab === 'blocklist'
                                        ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                                ]"
                            >
                                Blocklist Monitors
                            </button>
                            <button
                                @click="activeTab = 'dmarc'; loadDmarcMonitors()"
                                :class="[
                                    activeTab === 'dmarc'
                                        ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                                ]"
                            >
                                DMARC Monitors
                            </button>
                        </nav>
                    </div>
                </div>

                <!-- Blocklist Tab -->
                <div v-if="activeTab === 'blocklist'" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Blocklist Monitors</h3>
                            <PrimaryButton @click="openBlocklistModal()">Add Monitor</PrimaryButton>
                        </div>

                        <div v-if="loading" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                        </div>
                        <div v-else>
                            <div v-if="blocklistMonitors.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                No blocklist monitors yet. Create one to get started.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Domain / IP</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Checked</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="monitor in blocklistMonitors" :key="monitor.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ monitor.target }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900 rounded">{{ monitor.type }}</span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span :class="['px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusColor(monitor.is_blocklisted)]">
                                                    {{ getStatusBadge(monitor.is_blocklisted) }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ monitor.last_checked_at ? new Date(monitor.last_checked_at).toLocaleString() : 'Never' }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                                <button @click="router.visit(`/monitors/blocklist/${monitor.id}`)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">View</button>
                                                <button @click="openBlocklistModal(monitor)" class="text-blue-600 hover:text-blue-900 dark:text-blue-400">Edit</button>
                                                <button @click="deleteBlocklistMonitor(monitor.id)" class="text-red-600 hover:text-red-900 dark:text-red-400">Delete</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DMARC Tab -->
                <div v-if="activeTab === 'dmarc'" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">DMARC Monitors</h3>
                            <PrimaryButton @click="openDmarcModal()">Add Monitor</PrimaryButton>
                        </div>

                        <div v-if="loading" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                        </div>
                        <div v-else>
                            <div v-if="dmarcMonitors.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                No DMARC monitors yet. Create one to get started.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Name</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Domain</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Checked</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="monitor in dmarcMonitors" :key="monitor.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ monitor.name }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ monitor.domain }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span :class="['px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full', getIssueColor(monitor.has_issue)]">
                                                    {{ getIssueBadge(monitor.has_issue) }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ monitor.last_checked_at ? new Date(monitor.last_checked_at).toLocaleString() : 'Never' }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                                <button @click="checkDmarcMonitor(monitor.id)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Check</button>
                                                <button @click="openDmarcModal(monitor)" class="text-blue-600 hover:text-blue-900 dark:text-blue-400">Edit</button>
                                                <button @click="deleteDmarcMonitor(monitor.id)" class="text-red-600 hover:text-red-900 dark:text-red-400">Delete</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Blocklist Modal -->
        <Modal :show="showBlocklistModal" @close="showBlocklistModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    {{ editingBlocklist ? 'Edit' : 'Create' }} Blocklist Monitor
                </h2>

                <div class="space-y-4">
                    <div>
                        <InputLabel for="blocklist-target" value="Domain or IP Address" />
                        <TextInput
                            id="blocklist-target"
                            v-model="blocklistForm.target"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="example.com or 192.168.1.1"
                            @input="blocklistForm.type = detectType(blocklistForm.target)"
                            required
                        />
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Type will be detected automatically ({{ blocklistForm.type === 'ip' ? 'IP Address' : 'Domain' }})
                        </p>
                    </div>

                    <div class="flex items-center">
                        <input
                            id="blocklist-active"
                            v-model="blocklistForm.active"
                            type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                        />
                        <label for="blocklist-active" class="ml-2 text-sm text-gray-600 dark:text-gray-400">
                            Active
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <PrimaryButton @click="saveBlocklistMonitor" :disabled="loading">
                        {{ editingBlocklist ? 'Update' : 'Create' }}
                    </PrimaryButton>
                    <button
                        @click="showBlocklistModal = false"
                        class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-400 dark:hover:bg-gray-600"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </Modal>

        <!-- DMARC Modal -->
        <Modal :show="showDmarcModal" @close="showDmarcModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    {{ editingDmarc ? 'Edit' : 'Create' }} DMARC Monitor
                </h2>

                <div class="space-y-4">
                    <div>
                        <InputLabel for="dmarc-name" value="Name" />
                        <TextInput
                            id="dmarc-name"
                            v-model="dmarcForm.name"
                            type="text"
                            class="mt-1 block w-full"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel for="dmarc-domain" value="Domain" />
                        <TextInput
                            id="dmarc-domain"
                            v-model="dmarcForm.domain"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="example.com"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel for="dmarc-email" value="Report Email (optional)" />
                        <TextInput
                            id="dmarc-email"
                            v-model="dmarcForm.report_email"
                            type="email"
                            class="mt-1 block w-full"
                            placeholder="reports@example.com"
                        />
                    </div>

                    <div>
                        <InputLabel for="dmarc-interval" value="Check Interval (minutes)" />
                        <TextInput
                            id="dmarc-interval"
                            v-model.number="dmarcForm.check_interval_minutes"
                            type="number"
                            min="60"
                            max="10080"
                            class="mt-1 block w-full"
                            required
                        />
                    </div>

                    <div class="flex items-center">
                        <input
                            id="dmarc-active"
                            v-model="dmarcForm.active"
                            type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                        />
                        <label for="dmarc-active" class="ml-2 text-sm text-gray-600 dark:text-gray-400">
                            Active
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <PrimaryButton @click="saveDmarcMonitor" :disabled="loading">
                        {{ editingDmarc ? 'Update' : 'Create' }}
                    </PrimaryButton>
                    <button
                        @click="showDmarcModal = false"
                        class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-400 dark:hover:bg-gray-600"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

