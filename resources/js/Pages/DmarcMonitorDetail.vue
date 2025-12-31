<script setup>
import { ref, onMounted, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    monitorId: Number,
});

const loading = ref(false);
const monitor = ref(null);
const reports = ref([]);
const stats = ref({
    total_reports: 0,
    total_messages: 0,
    passed: 0,
    failed: 0,
    quarantined: 0,
    rejected: 0,
    unique_ips: 0,
    reports_by_date: {},
});

const selectedReport = ref(null);
const showReportModal = ref(false);

const loadMonitor = async () => {
    try {
        loading.value = true;
        const response = await window.axios.get(`/api/monitors/dmarc/${props.monitorId}/detail`, {
            withCredentials: true,
        });
        monitor.value = response.data.monitor;
        reports.value = response.data.reports || [];
        stats.value = response.data.stats || stats.value;
    } catch (error) {
        console.error('Failed to load monitor:', error);
        alert('Failed to load monitor details');
    } finally {
        loading.value = false;
    }
};

const checkNow = async () => {
    try {
        loading.value = true;
        await window.axios.post(`/api/monitors/dmarc/${props.monitorId}/check`, {}, {
            withCredentials: true,
        });
        alert('Check queued successfully. Results will appear shortly.');
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

const viewReport = (report) => {
    selectedReport.value = report;
    showReportModal.value = true;
};

const getStatusColor = (hasIssue) => {
    return hasIssue 
        ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
        : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
};

const getStatusBadge = (hasIssue) => {
    return hasIssue ? 'Has Issues' : 'OK';
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleString();
};

const formatDateRange = (begin, end) => {
    if (!begin || !end) return 'N/A';
    const start = new Date(begin * 1000);
    const endDate = new Date(end * 1000);
    return `${start.toLocaleDateString()} - ${endDate.toLocaleDateString()}`;
};

const getDispositionColor = (disposition) => {
    switch (disposition) {
        case 'none':
            return 'text-green-600 dark:text-green-400';
        case 'quarantine':
            return 'text-yellow-600 dark:text-yellow-400';
        case 'reject':
            return 'text-red-600 dark:text-red-400';
        default:
            return 'text-gray-600 dark:text-gray-400';
    }
};

const getAuthResultColor = (result) => {
    switch (result?.toLowerCase()) {
        case 'pass':
            return 'text-green-600 dark:text-green-400';
        case 'fail':
            return 'text-red-600 dark:text-red-400';
        default:
            return 'text-gray-600 dark:text-gray-400';
    }
};

onMounted(() => {
    loadMonitor();
});
</script>

<template>
    <AppLayout title="DMARC Monitor Detail">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                        DMARC Monitor
                    </h2>
                    <p v-if="monitor" class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ monitor.domain }}
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
                <div v-if="loading && !monitor" class="text-center py-12">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                </div>

                <div v-else-if="monitor" class="space-y-6">
                    <!-- Monitor Info Card -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Monitor Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Domain</label>
                                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ monitor.domain }}</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Report Email</label>
                                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ monitor.report_email }}</p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</label>
                                    <p class="mt-1">
                                        <span :class="['px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusColor(monitor.has_issue)]">
                                            {{ getStatusBadge(monitor.has_issue) }}
                                        </span>
                                    </p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Last Checked</label>
                                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                        {{ monitor.last_checked_at ? formatDate(monitor.last_checked_at) : 'Never' }}
                                    </p>
                                </div>
                                <div v-if="monitor.dmarc_record_string" class="md:col-span-2">
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">DMARC Record</label>
                                    <p class="mt-1 text-sm font-mono text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-900 p-2 rounded">
                                        {{ monitor.dmarc_record_string }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Card -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Statistics</h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Total Reports</p>
                                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ stats.total_reports }}</p>
                                </div>
                                <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Total Messages</p>
                                    <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ stats.total_messages.toLocaleString() }}</p>
                                </div>
                                <div class="bg-yellow-50 dark:bg-yellow-900/20 p-4 rounded">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Quarantined</p>
                                    <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ stats.quarantined.toLocaleString() }}</p>
                                </div>
                                <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Rejected</p>
                                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ stats.rejected.toLocaleString() }}</p>
                                </div>
                                <div class="bg-purple-50 dark:bg-purple-900/20 p-4 rounded">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Passed</p>
                                    <p class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ stats.passed.toLocaleString() }}</p>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-900/20 p-4 rounded">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Unique IPs</p>
                                    <p class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ stats.unique_ips }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reports List -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">DMARC Reports</h3>
                            
                            <div v-if="reports.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                No reports received yet. Reports will appear here once they are received from ISPs.
                            </div>
                            
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Report ID</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Period</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Messages</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="report in reports" :key="report.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                                {{ formatDate(report.checked_at) }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ report.check_details?.report_id || 'N/A' }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ formatDateRange(report.check_details?.date_range?.begin, report.check_details?.date_range?.end) }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ report.check_details?.summary?.total_messages || 0 }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span :class="['px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusColor(report.has_issue)]">
                                                    {{ getStatusBadge(report.has_issue) }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <button 
                                                    @click="viewReport(report)" 
                                                    class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400"
                                                >
                                                    View Details
                                                </button>
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

        <!-- Report Detail Modal -->
        <div v-if="showReportModal && selectedReport" 
             class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50"
             @click.self="showReportModal = false">
            <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white dark:bg-gray-800">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Report Details
                    </h3>
                    <button @click="showReportModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 max-h-96 overflow-y-auto">
                    <div>
                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Report ID</label>
                        <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ selectedReport.check_details?.report_id || 'N/A' }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Organization</label>
                        <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ selectedReport.check_details?.org_name || 'N/A' }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Report Period</label>
                        <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ formatDateRange(selectedReport.check_details?.date_range?.begin, selectedReport.check_details?.date_range?.end) }}
                        </p>
                    </div>

                    <div v-if="selectedReport.check_details?.summary">
                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Summary</label>
                        <div class="mt-1 grid grid-cols-2 gap-2 text-sm">
                            <div>Total Messages: <strong>{{ selectedReport.check_details.summary.total_messages || 0 }}</strong></div>
                            <div>Passed: <strong :class="getAuthResultColor('pass')">{{ selectedReport.check_details.summary.passed || 0 }}</strong></div>
                            <div>Quarantined: <strong :class="getDispositionColor('quarantine')">{{ selectedReport.check_details.summary.quarantined || 0 }}</strong></div>
                            <div>Rejected: <strong :class="getDispositionColor('reject')">{{ selectedReport.check_details.summary.rejected || 0 }}</strong></div>
                        </div>
                    </div>

                    <div v-if="selectedReport.check_details?.records && selectedReport.check_details.records.length > 0">
                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Records</label>
                        <div class="mt-1 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Source IP</th>
                                        <th class="px-3 py-2 text-left">Count</th>
                                        <th class="px-3 py-2 text-left">Disposition</th>
                                        <th class="px-3 py-2 text-left">SPF</th>
                                        <th class="px-3 py-2 text-left">DKIM</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="(record, index) in selectedReport.check_details.records.slice(0, 20)" :key="index">
                                        <td class="px-3 py-2">{{ record.source_ip }}</td>
                                        <td class="px-3 py-2">{{ record.count }}</td>
                                        <td class="px-3 py-2" :class="getDispositionColor(record.policy_evaluated?.disposition)">
                                            {{ record.policy_evaluated?.disposition || 'N/A' }}
                                        </td>
                                        <td class="px-3 py-2" :class="getAuthResultColor(record.auth_results?.spf?.result)">
                                            {{ record.auth_results?.spf?.result || 'N/A' }}
                                        </td>
                                        <td class="px-3 py-2" :class="getAuthResultColor(record.auth_results?.dkim?.result)">
                                            {{ record.auth_results?.dkim?.result || 'N/A' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-if="selectedReport.check_details.records.length > 20" class="mt-2 text-xs text-gray-500">
                                Showing first 20 of {{ selectedReport.check_details.records.length }} records
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button
                        @click="showReportModal = false"
                        class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-400 dark:hover:bg-gray-600"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

