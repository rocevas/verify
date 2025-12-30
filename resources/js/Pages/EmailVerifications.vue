<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    title: String,
});

const activeTab = ref('history'); // 'history' or 'lists'
const loading = ref(false);

// Stats
const stats = ref({
    total: 0,
    valid: 0,
    invalid: 0,
    risky: 0,
    percentages: { valid: 0, invalid: 0, risky: 0 }
});

// Chart data
const chartData = ref([]);

// History tab
const historyData = ref([]);
const historyPagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0
});

// Lists tab
const listsData = ref([]);
const listsPagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0
});

const loadStats = async () => {
    try {
        const response = await axios.get('/api/verifications/stats', {
            withCredentials: true,
        });
        stats.value = response.data;
    } catch (error) {
        console.error('Failed to load stats:', error);
    }
};

const loadChart = async () => {
    try {
        const response = await axios.get('/api/verifications/chart', {
            withCredentials: true,
        });
        chartData.value = response.data.data || [];
    } catch (error) {
        console.error('Failed to load chart data:', error);
    }
};

const loadHistory = async (page = 1) => {
    loading.value = true;
    try {
        const response = await axios.get('/api/verifications/history', {
            params: { page, per_page: 50 },
            withCredentials: true,
        });
        historyData.value = response.data.data || [];
        historyPagination.value = response.data.pagination || {};
    } catch (error) {
        console.error('Failed to load history:', error);
    } finally {
        loading.value = false;
    }
};

const loadLists = async (page = 1) => {
    loading.value = true;
    try {
        const response = await axios.get('/api/verifications/lists', {
            params: { page, per_page: 20 },
            withCredentials: true,
        });
        listsData.value = response.data.data || [];
        listsPagination.value = response.data.pagination || {};
    } catch (error) {
        console.error('Failed to load lists:', error);
    } finally {
        loading.value = false;
    }
};

const openBulkJob = (bulkJobId) => {
    router.visit(`/verifications/bulk/${bulkJobId}`);
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

onMounted(() => {
    loadStats();
    loadChart();
    loadHistory();
    loadLists();
});
</script>

<template>
    <AppLayout title="Email Verifications">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Email Verifications
                </h2>
                <PrimaryButton @click="router.visit('/verifications/import')">
                    Import Verifications
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
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

                <!-- Chart -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-8">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Verifications Over Time (Last 30 Days)
                        </h3>
                        <div class="h-64 flex items-end justify-between gap-2">
                            <div
                                v-for="(item, index) in chartData"
                                :key="index"
                                class="flex-1 flex flex-col items-center"
                            >
                                <div class="w-full flex flex-col items-center justify-end h-full">
                                    <div
                                        class="w-full bg-blue-500 rounded-t hover:bg-blue-600 transition-colors cursor-pointer"
                                        :style="{ height: chartData.length > 0 ? `${(item.total / Math.max(...chartData.map(d => d.total))) * 100}%` : '0%' }"
                                        :title="`${item.date}: ${item.total} total (${item.valid} valid, ${item.invalid} invalid, ${item.risky} risky)`"
                                    ></div>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-2 transform -rotate-45 origin-top-left whitespace-nowrap">
                                    {{ new Date(item.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <nav class="-mb-px flex space-x-8 px-6" aria-label="Tabs">
                            <button
                                @click="activeTab = 'history'; loadHistory()"
                                :class="[
                                    activeTab === 'history'
                                        ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                                ]"
                            >
                                History
                            </button>
                            <button
                                @click="activeTab = 'lists'; loadLists()"
                                :class="[
                                    activeTab === 'lists'
                                        ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                                ]"
                            >
                                Lists
                            </button>
                        </nav>
                    </div>

                    <div class="p-6">
                        <!-- History Tab -->
                        <div v-if="activeTab === 'history'">
                            <div v-if="loading" class="text-center py-8">
                                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                            </div>
                            <div v-else>
                                <div v-if="historyData.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                    No verifications yet
                                </div>
                                <div v-else>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                            <thead class="bg-gray-50 dark:bg-gray-700">
                                                <tr>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Score</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Source</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                                <tr v-for="item in historyData" :key="item.id">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                                        {{ item.email }}
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <span :class="['px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusColor(item.status)]">
                                                            {{ getStatusBadge(item.status) }}
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                        {{ item.score ?? '-' }}
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                        <span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded">{{ item.source || 'N/A' }}</span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                        {{ new Date(item.created_at).toLocaleString() }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Pagination -->
                                    <div v-if="historyPagination.last_page > 1" class="mt-4 flex items-center justify-between">
                                        <div class="text-sm text-gray-700 dark:text-gray-300">
                                            Showing {{ (historyPagination.current_page - 1) * historyPagination.per_page + 1 }} to
                                            {{ Math.min(historyPagination.current_page * historyPagination.per_page, historyPagination.total) }} of
                                            {{ historyPagination.total }} results
                                        </div>
                                        <div class="flex gap-2">
                                            <PrimaryButton
                                                @click="loadHistory(historyPagination.current_page - 1)"
                                                :disabled="historyPagination.current_page === 1"
                                                class="text-sm"
                                            >
                                                Previous
                                            </PrimaryButton>
                                            <PrimaryButton
                                                @click="loadHistory(historyPagination.current_page + 1)"
                                                :disabled="historyPagination.current_page === historyPagination.last_page"
                                                class="text-sm"
                                            >
                                                Next
                                            </PrimaryButton>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Lists Tab -->
                        <div v-if="activeTab === 'lists'">
                            <div v-if="loading" class="text-center py-8">
                                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                            </div>
                            <div v-else>
                                <div v-if="listsData.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                    No bulk verification lists yet
                                </div>
                                <div v-else class="space-y-4">
                                    <div
                                        v-for="list in listsData"
                                        :key="list.id"
                                        class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer transition-colors"
                                        @click="openBulkJob(list.id)"
                                    >
                                        <div class="flex items-center justify-between">
                                            <div class="flex-1">
                                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                                    {{ list.filename }}
                                                </h3>
                                                <div class="mt-2 flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                                                    <span>Source: <span class="font-medium">{{ list.source || 'N/A' }}</span></span>
                                                    <span>Status: <span :class="['font-medium', list.status === 'completed' ? 'text-green-600 dark:text-green-400' : 'text-yellow-600 dark:text-yellow-400']">{{ list.status }}</span></span>
                                                    <span>{{ list.processed_emails }} / {{ list.total_emails }} emails</span>
                                                </div>
                                                <div class="mt-2 flex items-center gap-4">
                                                    <div class="text-sm">
                                                        <span class="text-green-600 dark:text-green-400 font-medium">{{ list.stats.valid }}</span>
                                                        <span class="text-gray-500 dark:text-gray-400"> valid</span>
                                                    </div>
                                                    <div class="text-sm">
                                                        <span class="text-red-600 dark:text-red-400 font-medium">{{ list.stats.invalid }}</span>
                                                        <span class="text-gray-500 dark:text-gray-400"> invalid</span>
                                                    </div>
                                                    <div class="text-sm">
                                                        <span class="text-yellow-600 dark:text-yellow-400 font-medium">{{ list.stats.risky }}</span>
                                                        <span class="text-gray-500 dark:text-gray-400"> risky</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                </svg>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Pagination -->
                                    <div v-if="listsPagination.last_page > 1" class="mt-4 flex items-center justify-between">
                                        <div class="text-sm text-gray-700 dark:text-gray-300">
                                            Showing {{ (listsPagination.current_page - 1) * listsPagination.per_page + 1 }} to
                                            {{ Math.min(listsPagination.current_page * listsPagination.per_page, listsPagination.total) }} of
                                            {{ listsPagination.total }} results
                                        </div>
                                        <div class="flex gap-2">
                                            <PrimaryButton
                                                @click="loadLists(listsPagination.current_page - 1)"
                                                :disabled="listsPagination.current_page === 1"
                                                class="text-sm"
                                            >
                                                Previous
                                            </PrimaryButton>
                                            <PrimaryButton
                                                @click="loadLists(listsPagination.current_page + 1)"
                                                :disabled="listsPagination.current_page === listsPagination.last_page"
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

