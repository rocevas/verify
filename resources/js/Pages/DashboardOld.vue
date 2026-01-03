<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
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

const loading = ref(false);

// Chart data
const chartData = ref([]);

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

const loadChart = async () => {
    try {
        const response = await axios.get('/api/dashboard/chart', {
            withCredentials: true,
        });
        chartData.value = response.data.data || [];
    } catch (error) {
        console.error('Failed to load chart data:', error);
    }
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

        // Refresh stats
        await loadStats();
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

        // If bulk_job_id is returned, redirect to bulk job detail page
        if (response.data.bulk_job_id) {
            // Redirect to bulk job detail page
            router.visit(`/verifications/bulk/${response.data.bulk_job_id}`);
            return;
        }

        // Refresh stats
        await loadStats();
    } catch (err) {
        if (err.code === 'ECONNABORTED' || err.message?.includes('timeout')) {
            error.value = 'Batch verification timed out. Some email servers may be slow to respond.';
        } else {
            const errorMessage = err.response?.data?.error || err.response?.data?.message || err.message || 'Failed to verify emails';
            error.value = errorMessage;
            console.error('Batch verification error:', {
                message: errorMessage,
                response: err.response?.data,
                status: err.response?.status,
                fullError: err
            });
        }
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
    loadChart();
    
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
                                        <span :class="verificationResult.checks?.mx_record ? 'text-green-600' : 'text-red-600'">
                                            ✓ MX: {{ verificationResult.checks?.mx_record ? 'Pass' : 'Fail' }}
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
                                                        <span :class="result.checks?.mx_record ? 'text-green-600' : 'text-red-600'" title="MX">M</span>
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

                <!-- Chart -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-8">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Verifications Over Time (Last 30 Days)
                        </h3>
                        <div v-if="chartData.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                            No data available yet
                        </div>
                        <div v-else class="h-64 flex items-end justify-between gap-2">
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
            </div>
        </div>

    </AppLayout>
</template>
