<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    monitorId: Number,
});

const loading = ref(false);
const monitor = ref(null);
const checkResults = ref([]);

const loadMonitor = async () => {
    try {
        loading.value = true;
        const response = await window.axios.get(`/api/monitors/blocklist`, {
            withCredentials: true,
        });
        const found = response.data.find(m => m.id === props.monitorId);
        if (found) {
            monitor.value = found;
            updateCheckResults(found);
        }
    } catch (error) {
        console.error('Failed to load monitor:', error);
    } finally {
        loading.value = false;
    }
};

const updateCheckResults = (monitorData) => {
    if (monitorData.last_check_details && monitorData.last_check_details.details) {
        checkResults.value = Object.entries(monitorData.last_check_details.details)
            .filter(([key, value]) => key !== 'resolved_ip' && typeof value === 'object' && value !== null)
            .map(([name, details]) => ({
                name: name,
                listed: details.listed || false,
                blocklist: details.blocklist || '',
                error: details.error || null,
            }))
            .sort((a, b) => {
                // Sort: listed first, then by name
                if (a.listed !== b.listed) {
                    return b.listed ? 1 : -1;
                }
                return a.name.localeCompare(b.name);
            });
    } else {
        checkResults.value = [];
    }
};

const checkNow = async () => {
    try {
        loading.value = true;
        await window.axios.post(`/api/monitors/blocklist/${props.monitorId}/check`, {}, {
            withCredentials: true,
        });
        alert('Check queued successfully. Results will appear shortly.');
        // Reload monitor after a short delay
        setTimeout(() => {
            loadMonitor();
        }, 2000);
    } catch (error) {
        console.error('Failed to queue check:', error);
        alert('Failed to queue check: ' + (error.response?.data?.message || error.message));
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    loadMonitor();
});
</script>

<template>
    <AppLayout title="Blocklist Monitor Detail">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                        Blocklist Monitor
                    </h2>
                    <p v-if="monitor" class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ monitor.target }} ({{ monitor.type }})
                    </p>
                </div>
                <div class="flex space-x-3">
                    <PrimaryButton 
                        @click="checkNow" 
                        :disabled="loading"
                        class="bg-indigo-600 hover:bg-indigo-700"
                    >
                        Check Now
                    </PrimaryButton>
                    <PrimaryButton @click="router.visit('/monitors')">
                        Back to Monitors
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Monitor Info -->
                <div v-if="monitor" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Monitor Information</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Domain / IP</p>
                                <p class="text-base font-medium text-gray-900 dark:text-gray-100">{{ monitor.target }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Type</p>
                                <p class="text-base font-medium text-gray-900 dark:text-gray-100">
                                    <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900 rounded">{{ monitor.type }}</span>
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
                                <p class="text-base font-medium">
                                    <span :class="['px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full', monitor.is_blocklisted ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200']">
                                        {{ monitor.is_blocklisted ? 'Blocklisted' : 'OK' }}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Last Checked</p>
                                <p class="text-base font-medium text-gray-900 dark:text-gray-100">
                                    {{ monitor.last_checked_at ? new Date(monitor.last_checked_at).toLocaleString() : 'Never' }}
                                </p>
                            </div>
                            <div v-if="monitor.is_blocklisted && monitor.last_check_details?.blocklists" class="col-span-2">
                                <p class="text-sm text-gray-500 dark:text-gray-400">Found In</p>
                                <p class="text-base font-medium text-gray-900 dark:text-gray-100">
                                    {{ monitor.last_check_details.blocklists.join(', ') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Blocklist Check Results -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Blocklist Check Results</h3>
                        
                        <div v-if="loading && !monitor" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                        </div>
                        
                        <div v-else-if="!monitor || !monitor.last_checked_at" class="text-center py-8 text-gray-500 dark:text-gray-400">
                            <p class="mb-2">Monitor has not been checked yet.</p>
                            <p class="text-sm">The check will run automatically after creation or you can wait for the scheduled check.</p>
                        </div>
                        
                        <div v-else-if="checkResults.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                            <p>No blocklist check results available.</p>
                        </div>
                        
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Blocklist Service</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">DNSBL Server</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="(result, index) in checkResults" :key="index">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ result.name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span v-if="result.error" class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                                Error
                                            </span>
                                            <span v-else-if="result.listed" class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                                Listed
                                            </span>
                                            <span v-else class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                Not Listed
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="result.error" class="text-yellow-600 dark:text-yellow-400">{{ result.error }}</span>
                                            <span v-else-if="result.blocklist">{{ result.blocklist }}</span>
                                            <span v-else>-</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <div v-if="monitor.last_check_details?.resolved_ip" class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                                <p><strong>Resolved IP:</strong> {{ monitor.last_check_details.resolved_ip }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

