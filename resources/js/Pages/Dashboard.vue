<script setup>
import { ref, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const stats = ref({
    today: { total: 0, valid: 0, invalid: 0, risky: 0, percentages: {} },
    month: { total: 0, valid: 0, invalid: 0, risky: 0, percentages: {} }
});

const recentVerifications = ref([]);
const bulkJobs = ref([]);
const individualVerifications = ref([]);
const expandedBulkJobs = ref(new Set());
const bulkJobEmails = ref({}); // { bulkJobId: [emails] }
const loadingBulkEmails = ref(new Set());
const loading = ref(false);

// Email verification form
const verificationMode = ref('single'); // 'single' or 'batch'
const singleEmail = ref('');
const batchEmails = ref('');
const verificationResult = ref(null);
const batchResults = ref([]);
const verifying = ref(false);
const error = ref(null);

const loadStats = async () => {
    try {
        const response = await axios.get('/api/dashboard/stats', {
            withCredentials: true,
        });
        stats.value = response.data;
    } catch (error) {
        console.error('Failed to load stats:', error);
    }
};

const loadRecentVerifications = async () => {
    try {
        const response = await axios.get('/api/dashboard/recent', {
            withCredentials: true,
        });
        bulkJobs.value = response.data.bulk_jobs || [];
        individualVerifications.value = response.data.individual_verifications || [];
        // Keep old format for backward compatibility - use all_verifications if available
        recentVerifications.value = response.data.all_verifications || [
            ...bulkJobs.value,
            ...individualVerifications.value,
        ];
    } catch (error) {
        console.error('Failed to load recent verifications:', error);
        console.error('Error response:', error.response?.data);
    }
};

const toggleBulkJob = async (bulkJobId) => {
    if (expandedBulkJobs.value.has(bulkJobId)) {
        expandedBulkJobs.value.delete(bulkJobId);
    } else {
        expandedBulkJobs.value.add(bulkJobId);
        // Load emails if not already loaded
        if (!bulkJobEmails.value[bulkJobId]) {
            await loadBulkJobEmails(bulkJobId);
        }
    }
};

const loadBulkJobEmails = async (bulkJobId) => {
    if (loadingBulkEmails.value.has(bulkJobId)) {
        return;
    }
    
    loadingBulkEmails.value.add(bulkJobId);
    try {
        const response = await axios.get(`/api/dashboard/bulk-jobs/${bulkJobId}/emails`, {
            withCredentials: true,
        });
        bulkJobEmails.value[bulkJobId] = response.data;
    } catch (error) {
        console.error('Failed to load bulk job emails:', error);
    } finally {
        loadingBulkEmails.value.delete(bulkJobId);
    }
};

const getBulkJobStatusColor = (status) => {
    const colors = {
        completed: 'bg-green-100 text-green-800',
        processing: 'bg-blue-100 text-blue-800',
        pending: 'bg-yellow-100 text-yellow-800',
        failed: 'bg-red-100 text-red-800',
    };
    return colors[status] || colors.pending;
};

const getStatusColor = (status) => {
    const colors = {
        valid: 'bg-green-100 text-green-800',
        invalid: 'bg-red-100 text-red-800',
        catch_all: 'bg-yellow-100 text-yellow-800',
        risky: 'bg-orange-100 text-orange-800',
        do_not_mail: 'bg-gray-100 text-gray-800',
        unknown: 'bg-gray-100 text-gray-800',
    };
    return colors[status] || colors.unknown;
};

const verifySingleEmail = async () => {
    if (!singleEmail.value.trim()) {
        error.value = 'Please enter an email address';
        return;
    }

    verifying.value = true;
    error.value = null;
    verificationResult.value = null;

    try {
        // Set timeout to 30 seconds (SMTP check can take time)
        const response = await axios.post('/api/verify', {
            email: singleEmail.value.trim(),
        }, {
            withCredentials: true,
            timeout: 30000, // 30 seconds timeout
        });

        verificationResult.value = response.data;
        singleEmail.value = '';
        
        // Refresh stats and recent verifications
        await loadStats();
        await loadRecentVerifications();
    } catch (err) {
        if (err.code === 'ECONNABORTED' || err.message?.includes('timeout')) {
            error.value = 'Verification timed out. The email server may be slow to respond.';
        } else {
            error.value = err.response?.data?.message || err.response?.data?.error || 'Failed to verify email';
        }
        console.error('Verification error:', err);
    } finally {
        verifying.value = false;
    }
};

const verifyBatchEmails = async () => {
    if (!batchEmails.value.trim()) {
        error.value = 'Please enter email addresses';
        return;
    }

    // Parse emails from textarea (one per line or comma-separated)
    const emails = batchEmails.value
        .split(/[,\n]/)
        .map(email => email.trim())
        .filter(email => email.length > 0);

    if (emails.length === 0) {
        error.value = 'Please enter at least one email address';
        return;
    }

    if (emails.length > 100) {
        error.value = 'Maximum 100 emails allowed per batch';
        return;
    }

    verifying.value = true;
    error.value = null;
    batchResults.value = [];

    try {
        // Set timeout to 60 seconds for batch (more emails = more time)
        const response = await axios.post('/api/verify/batch', {
            emails: emails,
        }, {
            withCredentials: true,
            timeout: 60000, // 60 seconds timeout for batch
        });

        batchResults.value = response.data.results || [];
        
        // If bulk_job_id is returned, we can show it was grouped
        if (response.data.bulk_job_id) {
            console.log('Batch verification completed with bulk_job_id:', response.data.bulk_job_id);
        }
        
        // Refresh stats and recent verifications
        await loadStats();
        await loadRecentVerifications();
    } catch (err) {
        if (err.code === 'ECONNABORTED' || err.message?.includes('timeout')) {
            error.value = 'Batch verification timed out. Some email servers may be slow to respond.';
        } else {
            error.value = err.response?.data?.message || err.response?.data?.error || 'Failed to verify emails';
        }
        console.error('Batch verification error:', err);
    } finally {
        verifying.value = false;
    }
};

const clearResults = () => {
    verificationResult.value = null;
    batchResults.value = [];
    error.value = null;
};

onMounted(() => {
    loadStats();
    loadRecentVerifications();
    
    // Refresh stats every 30 seconds
    setInterval(loadStats, 30000);
});
</script>

<template>
    <AppLayout title="Dashboard">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Email Verification Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <!-- Today's Stats -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4">Today's Stats</h3>
                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm">Total Verified</span>
                                    <span class="text-sm font-semibold">{{ stats.today.total }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm text-green-600">Valid</span>
                                    <span class="text-sm font-semibold">{{ stats.today.valid }} ({{ stats.today.percentages.valid }}%)</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-green-600 h-2 rounded-full" :style="`width: ${stats.today.percentages.valid}%`"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm text-red-600">Invalid</span>
                                    <span class="text-sm font-semibold">{{ stats.today.invalid }} ({{ stats.today.percentages.invalid }}%)</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-red-600 h-2 rounded-full" :style="`width: ${stats.today.percentages.invalid}%`"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm text-orange-600">Risky</span>
                                    <span class="text-sm font-semibold">{{ stats.today.risky }} ({{ stats.today.percentages.risky }}%)</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-orange-600 h-2 rounded-full" :style="`width: ${stats.today.percentages.risky}%`"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Month's Stats -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4">This Month's Stats</h3>
                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm">Total Verified</span>
                                    <span class="text-sm font-semibold">{{ stats.month.total }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm text-green-600">Valid</span>
                                    <span class="text-sm font-semibold">{{ stats.month.valid }} ({{ stats.month.percentages.valid }}%)</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-green-600 h-2 rounded-full" :style="`width: ${stats.month.percentages.valid}%`"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm text-red-600">Invalid</span>
                                    <span class="text-sm font-semibold">{{ stats.month.invalid }} ({{ stats.month.percentages.invalid }}%)</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-red-600 h-2 rounded-full" :style="`width: ${stats.month.percentages.invalid}%`"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm text-orange-600">Risky</span>
                                    <span class="text-sm font-semibold">{{ stats.month.risky }} ({{ stats.month.percentages.risky }}%)</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-orange-600 h-2 rounded-full" :style="`width: ${stats.month.percentages.risky}%`"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Email Verification Form -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold">Verify Email Addresses</h3>
                        <p class="text-sm text-gray-500 mt-1">Check if email addresses are valid and deliverable</p>
                    </div>
                    
                    <div class="p-6">
                        <!-- Mode Toggle -->
                        <div class="mb-6 flex gap-4 border-b border-gray-200 dark:border-gray-700">
                            <button
                                @click="verificationMode = 'single'; clearResults()"
                                :class="[
                                    'pb-3 px-4 font-medium text-sm',
                                    verificationMode === 'single'
                                        ? 'border-b-2 border-indigo-500 text-indigo-600'
                                        : 'text-gray-500 hover:text-gray-700'
                                ]"
                            >
                                Single Email
                            </button>
                            <button
                                @click="verificationMode = 'batch'; clearResults()"
                                :class="[
                                    'pb-3 px-4 font-medium text-sm',
                                    verificationMode === 'batch'
                                        ? 'border-b-2 border-indigo-500 text-indigo-600'
                                        : 'text-gray-500 hover:text-gray-700'
                                ]"
                            >
                                Batch Verification
                            </button>
                        </div>

                        <!-- Error Message -->
                        <div v-if="error" class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md">
                            <p class="text-sm text-red-800 dark:text-red-200">{{ error }}</p>
                        </div>

                        <!-- Single Email Form -->
                        <div v-if="verificationMode === 'single'" class="space-y-4">
                            <div>
                                <InputLabel for="email" value="Email Address" />
                                <TextInput
                                    id="email"
                                    v-model="singleEmail"
                                    type="email"
                                    class="mt-1 block w-full"
                                    placeholder="example@domain.com"
                                    @keyup.enter="verifySingleEmail"
                                />
                            </div>
                            <div class="flex gap-3">
                                <PrimaryButton @click="verifySingleEmail" :disabled="verifying || !singleEmail.trim()">
                                    <span v-if="verifying">Verifying...</span>
                                    <span v-else>Verify Email</span>
                                </PrimaryButton>
                                <SecondaryButton v-if="verificationResult" @click="clearResults">
                                    Clear
                                </SecondaryButton>
                            </div>

                            <!-- Single Result -->
                            <div v-if="verificationResult" class="mt-6 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="font-semibold">Verification Result</h4>
                                    <span :class="['px-3 py-1 text-sm rounded-full', getStatusColor(verificationResult.status)]">
                                        {{ verificationResult.status }}
                                    </span>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4 mb-4">
                                    <div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Email</p>
                                        <p class="font-medium">{{ verificationResult.email }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Score</p>
                                        <p class="font-medium">{{ verificationResult.score }}/100</p>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Checks</p>
                                    <div class="flex flex-wrap gap-3">
                                        <span :class="verificationResult.checks?.syntax ? 'text-green-600' : 'text-red-600'">
                                            ✓ Syntax: {{ verificationResult.checks?.syntax ? 'Pass' : 'Fail' }}
                                        </span>
                                        <span :class="verificationResult.checks?.mx ? 'text-green-600' : 'text-red-600'">
                                            ✓ MX: {{ verificationResult.checks?.mx ? 'Pass' : 'Fail' }}
                                        </span>
                                        <span :class="verificationResult.checks?.smtp ? 'text-green-600' : 'text-red-600'">
                                            ✓ SMTP: {{ verificationResult.checks?.smtp ? 'Pass' : 'Fail' }}
                                        </span>
                                        <span :class="!verificationResult.checks?.disposable ? 'text-green-600' : 'text-red-600'">
                                            ✓ Disposable: {{ verificationResult.checks?.disposable ? 'Yes' : 'No' }}
                                        </span>
                                        <span :class="!verificationResult.checks?.role ? 'text-green-600' : 'text-orange-600'">
                                            ✓ Role-based: {{ verificationResult.checks?.role ? 'Yes' : 'No' }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="verificationResult.error" class="p-3 bg-red-50 dark:bg-red-900/20 rounded">
                                    <p class="text-sm text-red-800 dark:text-red-200">{{ verificationResult.error }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Batch Email Form -->
                        <div v-if="verificationMode === 'batch'" class="space-y-4">
                            <div>
                                <InputLabel for="batch-emails" value="Email Addresses (one per line or comma-separated)" />
                                <textarea
                                    id="batch-emails"
                                    v-model="batchEmails"
                                    rows="8"
                                    class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                                    placeholder="email1@example.com&#10;email2@example.com&#10;email3@example.com"
                                ></textarea>
                                <p class="mt-1 text-xs text-gray-500">Maximum 100 emails per batch</p>
                            </div>
                            <div class="flex gap-3">
                                <PrimaryButton @click="verifyBatchEmails" :disabled="verifying || !batchEmails.trim()">
                                    <span v-if="verifying">Verifying...</span>
                                    <span v-else>Verify Emails</span>
                                </PrimaryButton>
                                <SecondaryButton v-if="batchResults.length > 0" @click="clearResults">
                                    Clear
                                </SecondaryButton>
                            </div>

                            <!-- Batch Results -->
                            <div v-if="batchResults.length > 0" class="mt-6">
                                <h4 class="font-semibold mb-4">Verification Results ({{ batchResults.length }})</h4>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-900">
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Score</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Checks</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            <tr v-for="(result, index) in batchResults" :key="index">
                                                <td class="px-4 py-3 text-sm">{{ result.email }}</td>
                                                <td class="px-4 py-3">
                                                    <span :class="['px-2 py-1 text-xs rounded-full', getStatusColor(result.status)]">
                                                        {{ result.status }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-sm">{{ result.score }}</td>
                                                <td class="px-4 py-3 text-xs">
                                                    <div class="flex gap-1">
                                                        <span :class="result.checks?.syntax ? 'text-green-600' : 'text-red-600'" title="Syntax">S</span>
                                                        <span :class="result.checks?.mx ? 'text-green-600' : 'text-red-600'" title="MX">M</span>
                                                        <span :class="result.checks?.smtp ? 'text-green-600' : 'text-red-600'" title="SMTP">SMTP</span>
                                                        <span :class="!result.checks?.disposable ? 'text-green-600' : 'text-red-600'" title="Disposable">D</span>
                                                        <span :class="!result.checks?.role ? 'text-green-600' : 'text-orange-600'" title="Role">R</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Tokens Info Section -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <div>
                            <h3 class="text-lg font-semibold">API Tokens</h3>
                            <p class="text-sm text-gray-500 mt-1">Manage your API tokens for authentication</p>
                        </div>
                        <a href="/user/api-tokens" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                            Manage Tokens
                        </a>
                    </div>
                </div>

                <!-- Recent Verifications -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold">Recent Verifications</h3>
                    </div>
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <!-- Bulk Jobs (Groups) -->
                        <div v-for="bulkJob in bulkJobs" :key="'bulk-' + bulkJob.id" class="p-6">
                            <div 
                                @click="toggleBulkJob(bulkJob.id)"
                                class="cursor-pointer flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-900 p-3 rounded-lg transition"
                            >
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <svg 
                                            :class="['w-5 h-5 transition-transform', expandedBulkJobs.has(bulkJob.id) ? 'rotate-90' : '']"
                                            fill="none" 
                                            stroke="currentColor" 
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                        <h4 class="font-semibold">{{ bulkJob.filename || `Bulk Job #${bulkJob.id}` }}</h4>
                                        <span :class="['px-2 py-1 text-xs rounded-full', getBulkJobStatusColor(bulkJob.status)]">
                                            {{ bulkJob.status }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-6 text-sm text-gray-600 dark:text-gray-400 ml-8">
                                        <span>Total: <strong>{{ bulkJob.total_emails }}</strong></span>
                                        <span class="text-green-600">Valid: <strong>{{ bulkJob.stats.valid }}</strong></span>
                                        <span class="text-red-600">Invalid: <strong>{{ bulkJob.stats.invalid }}</strong></span>
                                        <span class="text-orange-600">Risky: <strong>{{ bulkJob.stats.risky }}</strong></span>
                                        <span class="text-gray-500">Progress: <strong>{{ Math.round(bulkJob.progress_percentage || 0) }}%</strong></span>
                                        <span class="text-gray-500 text-xs">
                                            {{ new Date(bulkJob.created_at).toLocaleString() }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Expanded Email List -->
                            <div v-if="expandedBulkJobs.has(bulkJob.id)" class="mt-4 ml-8">
                                <div v-if="loadingBulkEmails.has(bulkJob.id)" class="text-center py-4 text-gray-500">
                                    Loading emails...
                                </div>
                                <div v-else-if="bulkJobEmails[bulkJob.id] && bulkJobEmails[bulkJob.id].length > 0" class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-900">
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Score</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Checks</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            <tr v-for="email in bulkJobEmails[bulkJob.id]" :key="email.id">
                                                <td class="px-4 py-3 text-sm">{{ email.email }}</td>
                                                <td class="px-4 py-3">
                                                    <span :class="['px-2 py-1 text-xs rounded-full', getStatusColor(email.status)]">
                                                        {{ email.status }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-sm">{{ email.score }}</td>
                                                <td class="px-4 py-3 text-xs">
                                                    <div class="flex gap-2">
                                                        <span :class="email.checks?.syntax ? 'text-green-600' : 'text-red-600'">S</span>
                                                        <span :class="email.checks?.mx ? 'text-green-600' : 'text-red-600'">M</span>
                                                        <span :class="email.checks?.smtp ? 'text-green-600' : 'text-red-600'">SMTP</span>
                                                        <span :class="email.checks?.disposable ? 'text-red-600' : 'text-green-600'">D</span>
                                                        <span :class="email.checks?.role ? 'text-orange-600' : 'text-green-600'">R</span>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-gray-500">
                                                    {{ new Date(email.created_at).toLocaleString() }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div v-else class="text-center py-4 text-gray-500">
                                    No emails found
                                </div>
                            </div>
                        </div>

                        <!-- Individual Verifications (Not part of bulk jobs) -->
                        <div v-if="individualVerifications.length > 0" class="p-6">
                            <h4 class="font-semibold mb-4">Individual Verifications</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Checks</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="verification in individualVerifications" :key="verification.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ verification.email }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span :class="['px-2 py-1 text-xs rounded-full', getStatusColor(verification.status)]">
                                                    {{ verification.status }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ verification.score }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-xs">
                                                <div class="flex gap-2">
                                                    <span :class="verification.checks?.syntax ? 'text-green-600' : 'text-red-600'">S</span>
                                                    <span :class="verification.checks?.mx ? 'text-green-600' : 'text-red-600'">M</span>
                                                    <span :class="verification.checks?.smtp ? 'text-green-600' : 'text-red-600'">SMTP</span>
                                                    <span :class="verification.checks?.disposable ? 'text-red-600' : 'text-green-600'">D</span>
                                                    <span :class="verification.checks?.role ? 'text-orange-600' : 'text-green-600'">R</span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ new Date(verification.created_at).toLocaleString() }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Empty State -->
                        <div v-if="bulkJobs.length === 0 && individualVerifications.length === 0" class="p-6 text-center text-gray-500">
                            No verifications yet
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </AppLayout>
</template>
