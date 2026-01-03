<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

defineProps({
    title: String,
});

const activeTab = ref('manual'); // 'manual', 'csv', 'mailerlite', 'mailchimp'

// Manual tab
const batchEmails = ref('');
const verifying = ref(false);
const batchResults = ref([]);
const error = ref(null);
const bulkJobId = ref(null);

// CSV Upload tab
const csvFile = ref(null);
const csvUploading = ref(false);
const csvError = ref(null);
const csvSuccess = ref(null);

const verifyBatchEmails = async () => {
    if (!batchEmails.value.trim()) {
        error.value = 'Please enter at least one email address';
        return;
    }

    const emails = batchEmails.value
        .split('\n')
        .map(email => email.trim())
        .filter(email => email.length > 0);

    if (emails.length === 0) {
        error.value = 'Please enter at least one valid email address';
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
        const response = await axios.post('/api/verify/batch', {
            emails: emails,
            async: false, // Synchronous for now
        }, {
            withCredentials: true,
        });

        if (response.data.bulk_job_id) {
            bulkJobId.value = response.data.bulk_job_id;
            // Redirect to bulk job detail page
            router.visit(`/verifications/bulk/${response.data.bulk_job_id}`);
            return;
        }

        batchResults.value = response.data.results || [];
    } catch (err) {
        if (err.response?.data?.message) {
            error.value = err.response.data.message;
        } else if (err.response?.data?.error) {
            error.value = err.response.data.error;
        } else {
            error.value = 'Failed to verify emails. Please try again.';
        }
        console.error('Batch verification error:', err);
    } finally {
        verifying.value = false;
    }
};

const handleCsvFileChange = (event) => {
    const file = event.target.files[0];
    if (file) {
        if (file.size > 10 * 1024 * 1024) { // 10MB
            csvError.value = 'File size must be less than 10MB';
            csvFile.value = null;
            return;
        }
        csvFile.value = file;
        csvError.value = null;
    }
};

const uploadCsv = async () => {
    if (!csvFile.value) {
        csvError.value = 'Please select a CSV file';
        return;
    }

    csvUploading.value = true;
    csvError.value = null;
    csvSuccess.value = null;

    try {
        const formData = new FormData();
        formData.append('file', csvFile.value);

        const response = await axios.post('/api/bulk/upload', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
            withCredentials: true,
        });

        csvSuccess.value = `File uploaded successfully! Job ID: ${response.data.job_id}. Processing ${response.data.total_emails} emails...`;
        
        // Redirect to bulk job detail page (job_id is now UUID)
        if (response.data.job_id) {
            setTimeout(() => {
                router.visit(`/verifications/bulk/${response.data.job_id}`);
            }, 1000);
        }
    } catch (err) {
        if (err.response?.data?.message) {
            csvError.value = err.response.data.message;
        } else if (err.response?.data?.error) {
            csvError.value = err.response.data.error;
        } else {
            csvError.value = 'Failed to upload file. Please try again.';
        }
        console.error('CSV upload error:', err);
    } finally {
        csvUploading.value = false;
    }
};

const clearManualForm = () => {
    batchEmails.value = '';
    batchResults.value = [];
    error.value = null;
    bulkJobId.value = null;
};

const clearCsvForm = () => {
    csvFile.value = null;
    csvError.value = null;
    csvSuccess.value = null;
    // Reset file input
    const fileInput = document.getElementById('csv-file-input');
    if (fileInput) {
        fileInput.value = '';
    }
};
</script>

<template>
    <AppLayout title="Import Verifications">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Import Email Verifications
                </h2>
                <SecondaryButton @click="router.visit('/verifications')">
                    Back to Verifications
                </SecondaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <!-- Tabs -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <nav class="-mb-px flex space-x-8 px-6" aria-label="Tabs">
                            <button
                                @click="activeTab = 'manual'"
                                :class="[
                                    activeTab === 'manual'
                                        ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                                ]"
                            >
                                Manual Entry
                            </button>
                            <button
                                @click="activeTab = 'csv'"
                                :class="[
                                    activeTab === 'csv'
                                        ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                                ]"
                            >
                                CSV Upload
                            </button>
                            <button
                                @click="activeTab = 'mailerlite'"
                                :class="[
                                    activeTab === 'mailerlite'
                                        ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm opacity-50 cursor-not-allowed'
                                ]"
                                disabled
                            >
                                MailerLite
                                <span class="ml-2 text-xs bg-gray-200 dark:bg-gray-700 px-2 py-0.5 rounded">Coming Soon</span>
                            </button>
                            <button
                                @click="activeTab = 'mailchimp'"
                                :class="[
                                    activeTab === 'mailchimp'
                                        ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300',
                                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm opacity-50 cursor-not-allowed'
                                ]"
                                disabled
                            >
                                Mailchimp
                                <span class="ml-2 text-xs bg-gray-200 dark:bg-gray-700 px-2 py-0.5 rounded">Coming Soon</span>
                            </button>
                        </nav>
                    </div>

                    <div class="p-6">
                        <!-- Manual Entry Tab -->
                        <div v-if="activeTab === 'manual'">
                            <div class="mb-4">
                                <InputLabel for="emails" value="Email Addresses (one per line, max 100)" />
                                <textarea
                                    id="emails"
                                    v-model="batchEmails"
                                    rows="10"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 shadow-sm"
                                    placeholder="email1@example.com&#10;email2@example.com&#10;email3@example.com"
                                ></textarea>
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    Enter up to 100 email addresses, one per line.
                                </p>
                            </div>

                            <div v-if="error" class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md">
                                <p class="text-sm text-red-800 dark:text-red-200">{{ error }}</p>
                            </div>

                            <div v-if="csvSuccess" class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-md">
                                <p class="text-sm text-green-800 dark:text-green-200">{{ csvSuccess }}</p>
                            </div>

                            <div class="flex gap-3">
                                <PrimaryButton @click="verifyBatchEmails" :disabled="verifying || !batchEmails.trim()">
                                    <span v-if="verifying">Verifying...</span>
                                    <span v-else>Verify Emails</span>
                                </PrimaryButton>
                                <SecondaryButton @click="clearManualForm" :disabled="verifying">
                                    Clear
                                </SecondaryButton>
                            </div>

                            <!-- Results -->
                            <div v-if="batchResults.length > 0" class="mt-6">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Verification Results ({{ batchResults.length }} emails)
                                </h3>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Score</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            <tr v-for="(result, index) in batchResults" :key="index">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                                    {{ result.email }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span :class="[
                                                        'px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full',
                                                        result.status === 'valid' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                                        result.status === 'invalid' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
                                                    ]">
                                                        {{ result.status.charAt(0).toUpperCase() + result.status.slice(1) }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                    {{ result.score ?? '-' }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- CSV Upload Tab -->
                        <div v-if="activeTab === 'csv'">
                            <div class="mb-4">
                                <InputLabel for="csv-file-input" value="CSV File" />
                                <input
                                    id="csv-file-input"
                                    type="file"
                                    accept=".csv,.txt"
                                    @change="handleCsvFileChange"
                                    class="mt-1 block w-full text-sm text-gray-500 dark:text-gray-400
                                        file:mr-4 file:py-2 file:px-4
                                        file:rounded-md file:border-0
                                        file:text-sm file:font-semibold
                                        file:bg-indigo-50 file:text-indigo-700
                                        hover:file:bg-indigo-100
                                        dark:file:bg-indigo-900 dark:file:text-indigo-300
                                        dark:hover:file:bg-indigo-800"
                                />
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    Upload a CSV or TXT file with email addresses (one per line or comma-separated). Maximum file size: 10MB.
                                </p>
                            </div>

                            <div v-if="csvError" class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md">
                                <p class="text-sm text-red-800 dark:text-red-200">{{ csvError }}</p>
                            </div>

                            <div v-if="csvSuccess" class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-md">
                                <p class="text-sm text-green-800 dark:text-green-200">{{ csvSuccess }}</p>
                            </div>

                            <div class="flex gap-3">
                                <PrimaryButton @click="uploadCsv" :disabled="csvUploading || !csvFile">
                                    <span v-if="csvUploading">Uploading...</span>
                                    <span v-else>Upload & Verify</span>
                                </PrimaryButton>
                                <SecondaryButton @click="clearCsvForm" :disabled="csvUploading">
                                    Clear
                                </SecondaryButton>
                            </div>
                        </div>

                        <!-- MailerLite Tab -->
                        <div v-if="activeTab === 'mailerlite'" class="text-center py-12">
                            <p class="text-gray-500 dark:text-gray-400 mb-4">
                                MailerLite integration coming soon!
                            </p>
                            <p class="text-sm text-gray-400 dark:text-gray-500">
                                Connect your MailerLite account to import and verify your subscriber lists.
                            </p>
                        </div>

                        <!-- Mailchimp Tab -->
                        <div v-if="activeTab === 'mailchimp'" class="text-center py-12">
                            <p class="text-gray-500 dark:text-gray-400 mb-4">
                                Mailchimp integration coming soon!
                            </p>
                            <p class="text-sm text-gray-400 dark:text-gray-500">
                                Connect your Mailchimp account to import and verify your subscriber lists.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

