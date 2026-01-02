<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    bulkJobId: Number,
    title: String,
});

const loading = ref(false);
const bulkJob = ref(null);
const stats = ref({
    total: 0,
    valid: 0,
    invalid: 0,
    risky: 0,
    percentages: { valid: 0, invalid: 0, risky: 0 }
});

const emailsData = ref([]);
const emailsPagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0
});
const expandedRows = ref(new Set());

const loadBulkJob = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/api/bulk-jobs/${props.bulkJobId}`, {
            withCredentials: true,
        });
        bulkJob.value = response.data.bulk_job;
        stats.value = response.data.stats;
    } catch (error) {
        console.error('Failed to load bulk job:', error);
    } finally {
        loading.value = false;
    }
};

const loadEmails = async (page = 1) => {
    loading.value = true;
    try {
        const response = await axios.get(`/api/bulk-jobs/${props.bulkJobId}/emails`, {
            params: { page, per_page: 50 },
            withCredentials: true,
        });
        emailsData.value = response.data.data || [];
        emailsPagination.value = response.data.pagination || {};
    } catch (error) {
        console.error('Failed to load emails:', error);
    } finally {
        loading.value = false;
    }
};

const getStatusColor = (status) => {
    const colors = {
        valid: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        invalid: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        risky: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        catch_all: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        do_not_mail: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        unknown: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
    };
    return colors[status] || colors.unknown;
};

const getStatusBadge = (status) => {
    return status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ');
};

const toggleRow = (emailId) => {
    if (expandedRows.value.has(emailId)) {
        expandedRows.value.delete(emailId);
    } else {
        expandedRows.value.add(emailId);
    }
};

let refreshInterval = null;

const startAutoRefresh = () => {
    // Clear existing interval if any
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }

    // Only auto-refresh if job is not completed
    if (bulkJob.value && bulkJob.value.status !== 'completed' && bulkJob.value.status !== 'failed') {
        refreshInterval = setInterval(() => {
            loadBulkJob();
            // Only reload emails if we're on first page
            if (emailsPagination.value.current_page === 1) {
                loadEmails(1);
            }
        }, 3000); // Refresh every 3 seconds
    }
};

const stopAutoRefresh = () => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
};

// Watch for bulkJob changes to start/stop auto-refresh
watch(() => bulkJob.value?.status, (newStatus) => {
    if (newStatus === 'completed' || newStatus === 'failed') {
        stopAutoRefresh();
    } else {
        startAutoRefresh();
    }
});

onMounted(() => {
    loadBulkJob().then(() => {
        loadEmails();
        startAutoRefresh();
    });
});

onUnmounted(() => {
    stopAutoRefresh();
});

onUnmounted(() => {
    stopAutoRefresh();
});
</script>

<template>
    <AppLayout title="Bulk Verification Detail">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                        Bulk Verification Detail
                    </h2>
                    <p v-if="bulkJob" class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ bulkJob.filename }}
                    </p>
                </div>
                <PrimaryButton @click="router.visit('/verifications')">
                    Back to Verifications
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div v-if="loading && !bulkJob" class="text-center py-8">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                </div>

                <div v-else-if="bulkJob">
                    <!-- Bulk Job Info -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-8">
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</div>
                                    <div class="mt-1">
                                        <span :class="[
                                            'px-3 py-1 inline-flex text-sm font-semibold rounded-full',
                                            bulkJob.status === 'completed' 
                                                ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                                                : bulkJob.status === 'processing'
                                                ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
                                                : 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'
                                        ]">
                                            {{ bulkJob.status.charAt(0).toUpperCase() + bulkJob.status.slice(1) }}
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Progress</div>
                                    <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {{ bulkJob.processed_emails }} / {{ bulkJob.total_emails }} emails
                                    </div>
                                    <div class="mt-2 w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                                        <div
                                            class="bg-blue-600 h-2.5 rounded-full transition-all"
                                            :style="{ width: `${bulkJob.progress_percentage}%` }"
                                        ></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Source</div>
                                    <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {{ bulkJob.source || 'N/A' }}
                                    </div>
                                </div>
                            </div>
                            <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-500 dark:text-gray-400">
                                <div>
                                    <span class="font-medium">Created:</span>
                                    {{ new Date(bulkJob.created_at).toLocaleString() }}
                                </div>
                                <div v-if="bulkJob.completed_at">
                                    <span class="font-medium">Completed:</span>
                                    {{ new Date(bulkJob.completed_at).toLocaleString() }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Verifications</div>
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">
                                    {{ stats.total.toLocaleString() }}
                                </div>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Valid</div>
                                <div class="text-2xl font-bold text-green-600 dark:text-green-400 mt-2">
                                    {{ stats.valid.toLocaleString() }}
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    {{ stats.percentages.valid }}%
                                </div>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Invalid</div>
                                <div class="text-2xl font-bold text-red-600 dark:text-red-400 mt-2">
                                    {{ stats.invalid.toLocaleString() }}
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    {{ stats.percentages.invalid }}%
                                </div>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Risky</div>
                                <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mt-2">
                                    {{ stats.risky.toLocaleString() }}
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    {{ stats.percentages.risky }}%
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Emails List -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                Verified Emails
                            </h3>
                            <div v-if="loading && emailsData.length === 0" class="text-center py-8">
                                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                            </div>
                            <div v-else>
                                <div v-if="emailsData.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                    No emails verified yet
                                </div>
                                <div v-else>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                            <thead class="bg-gray-50 dark:bg-gray-700">
                                                <tr>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Score</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">AI Confidence</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">AI Insights</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                                <template v-for="email in emailsData" :key="email.id">
                                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                                            {{ email.email }}
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <span :class="['px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusColor(email.status)]">
                                                                {{ getStatusBadge(email.status) }}
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                            {{ email.score ?? '-' }}
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                            <span v-if="email.ai_confidence !== null" class="flex items-center gap-1">
                                                                <span class="text-purple-600 dark:text-purple-400">🤖</span>
                                                                <span class="font-semibold">{{ email.ai_confidence }}%</span>
                                                            </span>
                                                            <span v-else class="text-gray-400">-</span>
                                                        </td>
                                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                                            <div v-if="email.ai_insights" class="max-w-md">
                                                                <div 
                                                                    v-if="!expandedRows.has(email.id)"
                                                                    class="truncate cursor-pointer hover:text-purple-600 dark:hover:text-purple-400"
                                                                    @click="toggleRow(email.id)"
                                                                    :title="email.ai_insights"
                                                                >
                                                                    {{ email.ai_insights }}
                                                                </div>
                                                                <div 
                                                                    v-else
                                                                    class="cursor-pointer hover:text-purple-600 dark:hover:text-purple-400"
                                                                    @click="toggleRow(email.id)"
                                                                >
                                                                    {{ email.ai_insights }}
                                                                </div>
                                                            </div>
                                                            <span v-else class="text-gray-400">-</span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                            {{ new Date(email.created_at).toLocaleString() }}
                                                        </td>
                                                    </tr>
                                                    <!-- Expanded row with full AI insights -->
                                                    <tr v-if="expandedRows.has(email.id) && email.ai_insights" class="bg-purple-50 dark:bg-purple-900/20">
                                                        <td colspan="6" class="px-6 py-4">
                                                            <div class="flex items-start gap-2">
                                                                <span class="text-purple-600 dark:text-purple-400 text-lg">🤖</span>
                                                                <div class="flex-1">
                                                                    <div class="text-xs font-semibold text-purple-700 dark:text-purple-300 mb-1">AI Analysis:</div>
                                                                    <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ email.ai_insights }}</div>
                                                                </div>
                                                                <button 
                                                                    @click="toggleRow(email.id)"
                                                                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                                                    title="Collapse"
                                                                >
                                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                                                    </svg>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Pagination -->
                                    <div v-if="emailsPagination.last_page > 1" class="mt-4 flex items-center justify-between">
                                        <div class="text-sm text-gray-700 dark:text-gray-300">
                                            Showing {{ (emailsPagination.current_page - 1) * emailsPagination.per_page + 1 }} to
                                            {{ Math.min(emailsPagination.current_page * emailsPagination.per_page, emailsPagination.total) }} of
                                            {{ emailsPagination.total }} results
                                        </div>
                                        <div class="flex gap-2">
                                            <PrimaryButton
                                                @click="loadEmails(emailsPagination.current_page - 1)"
                                                :disabled="emailsPagination.current_page === 1"
                                                class="text-sm"
                                            >
                                                Previous
                                            </PrimaryButton>
                                            <PrimaryButton
                                                @click="loadEmails(emailsPagination.current_page + 1)"
                                                :disabled="emailsPagination.current_page === emailsPagination.last_page"
                                                class="text-sm"
                                            >
                                                Next
                                            </PrimaryButton>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

