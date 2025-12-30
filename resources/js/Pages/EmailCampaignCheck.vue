<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    campaign: Object,
    autoCheck: {
        type: Boolean,
        default: false,
    },
});

const loading = ref(false);
const checking = ref(false);
const campaign = ref(props.campaign);
const checkResult = ref(props.campaign.latest_check_result || null);
const pollingInterval = ref(null);
const previewMode = ref('html');

const loadCampaign = async () => {
    try {
        loading.value = true;
        const response = await window.axios.get(`/api/campaigns/${props.campaign.id}`, {
            withCredentials: true,
        });
        campaign.value = response.data;
        checkResult.value = response.data.latest_check_result;
    } catch (error) {
        console.error('Failed to load campaign:', error);
    } finally {
        loading.value = false;
    }
};

const startCheck = async () => {
    try {
        checking.value = true;
        checkResult.value = null;
        
        const response = await window.axios.post(`/api/campaigns/${props.campaign.id}/check`, {}, {
            withCredentials: true,
        });
        
        checkResult.value = response.data;
        checking.value = false;
        
        // Reload campaign to get latest data
        await loadCampaign();
    } catch (error) {
        console.error('Failed to check campaign:', error);
        alert('Failed to check campaign: ' + (error.response?.data?.error || error.message));
        checking.value = false;
    }
};

const startPolling = () => {
    // Stop any existing polling
    stopPolling();
    
    // Poll every 2 seconds, but stop if we have a result and not checking
    pollingInterval.value = setInterval(async () => {
        await loadCampaign();
        
        // Stop polling if we have a result and check is complete
        if (checkResult.value && !checking.value) {
            // Continue polling for a bit to catch any late updates, then stop
            setTimeout(() => {
                if (checkResult.value && !checking.value) {
                    stopPolling();
                }
            }, 5000);
        }
    }, 2000);
};

const stopPolling = () => {
    if (pollingInterval.value) {
        clearInterval(pollingInterval.value);
        pollingInterval.value = null;
    }
    checking.value = false;
};

const getScoreColor = (score, threshold) => {
    if (score >= threshold) return 'text-red-600';
    if (score >= threshold * 0.7) return 'text-orange-600';
    return 'text-green-600';
};

const getGradeColor = (grade) => {
    const colors = {
        'A': 'text-green-600',
        'B': 'text-blue-600',
        'C': 'text-yellow-600',
        'D': 'text-orange-600',
        'F': 'text-red-600',
    };
    return colors[grade] || 'text-gray-600';
};

const getRawEmail = () => {
    const headers = [];
    headers.push(`From: ${campaign.value.from_name ? `${campaign.value.from_name} <${campaign.value.from_email}>` : campaign.value.from_email}`);
    if (campaign.value.reply_to) {
        headers.push(`Reply-To: ${campaign.value.reply_to}`);
    }
    headers.push(`Subject: ${campaign.value.subject}`);
    headers.push(`Date: ${new Date().toUTCString()}`);
    headers.push(`MIME-Version: 1.0`);
    
    if (campaign.value.html_content && campaign.value.text_content) {
        const boundary = `----=_Part_${Date.now()}`;
        headers.push(`Content-Type: multipart/alternative; boundary="${boundary}"`);
        headers.push('');
        headers.push(`--${boundary}`);
        headers.push('Content-Type: text/plain; charset=UTF-8');
        headers.push('Content-Transfer-Encoding: 8bit');
        headers.push('');
        headers.push(campaign.value.text_content);
        headers.push(`--${boundary}`);
        headers.push('Content-Type: text/html; charset=UTF-8');
        headers.push('Content-Transfer-Encoding: 8bit');
        headers.push('');
        headers.push(campaign.value.html_content);
        headers.push(`--${boundary}--`);
    } else if (campaign.value.html_content) {
        headers.push(`Content-Type: text/html; charset=UTF-8`);
        headers.push(`Content-Transfer-Encoding: 8bit`);
        headers.push('');
        headers.push(campaign.value.html_content);
    } else {
        headers.push(`Content-Type: text/plain; charset=UTF-8`);
        headers.push(`Content-Transfer-Encoding: 8bit`);
        headers.push('');
        headers.push(campaign.value.text_content || '');
    }
    
    return headers.join('\n');
};

onMounted(async () => {
    // Always start polling to get updates
    startPolling();
    
    // If autoCheck prop is true or no check result exists, start check automatically
    if (props.autoCheck || !checkResult.value) {
        // Small delay to ensure component is fully mounted and data is loaded
        setTimeout(() => {
            if (!checkResult.value && !checking.value) {
                startCheck();
            }
        }, 1000);
    } else {
        // If we have a result, stop checking flag
        checking.value = false;
    }
});

onUnmounted(() => {
    stopPolling();
});
</script>

<template>
    <AppLayout title="Email Campaign Check">
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Checking: {{ campaign.name }}
                </h2>
                <PrimaryButton @click="router.visit('/inbox-insight')">
                    Back to Campaigns
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Campaign Info -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Campaign Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Name</p>
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ campaign.name }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Subject</p>
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ campaign.subject }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">From</p>
                                <p class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ campaign.from_name ? `${campaign.from_name} <${campaign.from_email}>` : campaign.from_email }}
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Created</p>
                                <p class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ new Date(campaign.created_at).toLocaleString() }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Email Preview -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Email Preview</h3>
                            <div class="flex gap-2">
                                <button
                                    @click="previewMode = 'html'"
                                    :class="[
                                        'px-3 py-1 text-sm rounded',
                                        previewMode === 'html' 
                                            ? 'bg-indigo-600 text-white' 
                                            : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'
                                    ]"
                                >
                                    HTML
                                </button>
                                <button
                                    @click="previewMode = 'text'"
                                    :class="[
                                        'px-3 py-1 text-sm rounded',
                                        previewMode === 'text' 
                                            ? 'bg-indigo-600 text-white' 
                                            : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'
                                    ]"
                                >
                                    Text
                                </button>
                                <button
                                    @click="previewMode = 'raw'"
                                    :class="[
                                        'px-3 py-1 text-sm rounded',
                                        previewMode === 'raw' 
                                            ? 'bg-indigo-600 text-white' 
                                            : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'
                                    ]"
                                >
                                    Raw
                                </button>
                            </div>
                        </div>

                        <!-- HTML Preview -->
                        <div v-if="previewMode === 'html' && campaign.html_content" class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <div class="bg-gray-50 dark:bg-gray-900 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="font-semibold">From:</span>
                                    <span>{{ campaign.from_name ? `${campaign.from_name} <${campaign.from_email}>` : campaign.from_email }}</span>
                                </div>
                                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    <span class="font-semibold">Subject:</span>
                                    <span>{{ campaign.subject }}</span>
                                </div>
                            </div>
                            <div class="bg-white dark:bg-gray-800 p-6">
                                <div 
                                    class="email-preview-html"
                                    v-html="campaign.html_content"
                                ></div>
                            </div>
                        </div>

                        <!-- Text Preview -->
                        <div v-if="previewMode === 'text' || (previewMode === 'html' && !campaign.html_content)" class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <div class="bg-gray-50 dark:bg-gray-900 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="font-semibold">From:</span>
                                    <span>{{ campaign.from_name ? `${campaign.from_name} <${campaign.from_email}>` : campaign.from_email }}</span>
                                </div>
                                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    <span class="font-semibold">Subject:</span>
                                    <span>{{ campaign.subject }}</span>
                                </div>
                            </div>
                            <div class="bg-white dark:bg-gray-800 p-6">
                                <pre class="whitespace-pre-wrap text-gray-900 dark:text-gray-100 font-sans">{{ campaign.text_content || campaign.html_content?.replace(/<[^>]*>/g, '') || 'No text content available' }}</pre>
                            </div>
                        </div>

                        <!-- Raw Preview -->
                        <div v-if="previewMode === 'raw'" class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <div class="bg-gray-50 dark:bg-gray-900 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                                <div class="text-sm text-gray-600 dark:text-gray-400">Raw Email Source</div>
                            </div>
                            <div class="bg-gray-900 p-6 overflow-x-auto">
                                <pre class="text-xs text-gray-300 font-mono whitespace-pre">{{ getRawEmail() }}</pre>
                            </div>
                        </div>

                        <!-- No content message -->
                        <div v-if="!campaign.html_content && !campaign.text_content" class="text-center py-8 text-gray-500 dark:text-gray-400">
                            No email content available for preview
                        </div>
                    </div>
                </div>

                <!-- Checking Status -->
                <div v-if="checking || !checkResult" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6 text-center">
                        <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mb-4"></div>
                        <p class="text-lg font-medium text-gray-900 dark:text-gray-100">Checking email campaign...</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">This may take a few moments</p>
                    </div>
                </div>

                <!-- Check Result -->
                <div v-if="checkResult && !checking" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Check Results</h3>
                            <PrimaryButton @click="startCheck" :disabled="checking">
                                {{ checking ? 'Checking...' : 'Re-check' }}
                            </PrimaryButton>
                        </div>

                        <!-- Summary Statistics -->
                        <div v-if="checkResult.check_details && checkResult.check_details.content_analysis && checkResult.check_details.content_analysis.summary" class="mb-6">
                            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-lg p-6 border border-blue-200 dark:border-blue-800">
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Analysis Summary</h4>
                                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                            {{ checkResult.check_details.content_analysis.summary.total_checks }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Total Checks</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                                            {{ checkResult.check_details.content_analysis.summary.passed }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Passed</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-red-600 dark:text-red-400">
                                            {{ checkResult.check_details.content_analysis.summary.failed }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Failed</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">
                                            {{ checkResult.check_details.content_analysis.summary.warnings }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Warnings</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                            {{ checkResult.check_details.content_analysis.summary.total_rules }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Rules Checked</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- All Checks Performed -->
                        <div v-if="checkResult.check_details && checkResult.check_details.content_analysis && checkResult.check_details.content_analysis.checks_performed" class="mb-6">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                                <span class="text-xl">📋</span>
                                All Checks Performed ({{ checkResult.check_details.content_analysis.checks_performed.length }})
                            </h4>
                            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <div
                                        v-for="(check, index) in checkResult.check_details.content_analysis.checks_performed"
                                        :key="index"
                                        :class="[
                                            'rounded-lg p-3 border',
                                            check.status === 'pass' ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' :
                                            check.status === 'fail' ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' :
                                            check.status === 'warning' ? 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-800' :
                                            'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800'
                                        ]"
                                    >
                                        <div class="flex items-start gap-2">
                                            <span class="text-xl">{{ check.icon }}</span>
                                            <div class="flex-1">
                                                <div class="font-semibold text-sm text-gray-900 dark:text-gray-100">{{ check.name }}</div>
                                                <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ check.message }}</div>
                                                <span :class="[
                                                    'inline-block mt-2 px-2 py-0.5 text-xs rounded',
                                                    check.status === 'pass' ? 'bg-green-200 text-green-800 dark:bg-green-800 dark:text-green-200' :
                                                    check.status === 'fail' ? 'bg-red-200 text-red-800 dark:bg-red-800 dark:text-red-200' :
                                                    check.status === 'warning' ? 'bg-orange-200 text-orange-800 dark:bg-orange-800 dark:text-orange-200' :
                                                    'bg-blue-200 text-blue-800 dark:bg-blue-800 dark:text-blue-200'
                                                ]">
                                                    {{ check.status }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Positive Results -->
                        <div v-if="checkResult.check_details && checkResult.check_details.content_analysis && checkResult.check_details.content_analysis.positive_results && checkResult.check_details.content_analysis.positive_results.length > 0" class="mb-6">
                            <h4 class="font-semibold text-green-600 dark:text-green-400 mb-3 flex items-center gap-2">
                                <span class="text-2xl">✅</span>
                                Positive Results ({{ checkResult.check_details.content_analysis.positive_results.length }})
                            </h4>
                            <div class="space-y-3">
                                <div
                                    v-for="(result, index) in checkResult.check_details.content_analysis.positive_results"
                                    :key="index"
                                    class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="text-2xl">{{ result.icon || '✅' }}</span>
                                        <div class="flex-1">
                                            <h5 class="font-semibold text-green-900 dark:text-green-100">{{ result.title }}</h5>
                                            <p class="text-sm text-green-700 dark:text-green-300 mt-1">{{ result.message }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="text-sm text-gray-500 dark:text-gray-400">Spam Score</div>
                                <div :class="['text-2xl font-bold', getScoreColor(checkResult.spam_score, checkResult.spam_threshold)]">
                                    {{ checkResult.spam_score }}/{{ checkResult.spam_threshold }}
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ checkResult.is_spam ? 'Marked as SPAM' : 'Not spam' }}
                                </div>
                            </div>
                            
                            <div v-if="checkResult.deliverability_score" class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="text-sm text-gray-500 dark:text-gray-400">Deliverability Score</div>
                                <div :class="['text-2xl font-bold', getGradeColor(checkResult.deliverability_score.grade)]">
                                    {{ checkResult.deliverability_score.overall }}%
                                </div>
                                <div :class="['text-lg font-semibold', getGradeColor(checkResult.deliverability_score.grade)]">
                                    Grade: {{ checkResult.deliverability_score.grade }}
                                </div>
                            </div>
                            
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="text-sm text-gray-500 dark:text-gray-400">Status</div>
                                <div
                                    :class="[
                                        'text-xl font-bold',
                                        checkResult.is_spam ? 'text-red-600' : 'text-green-600'
                                    ]"
                                >
                                    {{ checkResult.is_spam ? 'SPAM' : 'OK' }}
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    Checked: {{ new Date(checkResult.checked_at).toLocaleString() }}
                                </div>
                            </div>
                        </div>

                        <!-- Issues, Warnings, and Suggestions -->
                        <div v-if="checkResult.check_details && checkResult.check_details.content_analysis" class="mb-6">
                            <!-- Critical Issues -->
                            <div v-if="checkResult.check_details.content_analysis.issues && checkResult.check_details.content_analysis.issues.length > 0" class="mb-4">
                                <h4 class="font-semibold text-red-600 dark:text-red-400 mb-3 flex items-center gap-2">
                                    <span class="text-2xl">🔴</span>
                                    Critical Issues ({{ checkResult.check_details.content_analysis.issues.length }})
                                </h4>
                                <div class="space-y-3">
                                    <div
                                        v-for="(issue, index) in checkResult.check_details.content_analysis.issues"
                                        :key="index"
                                        class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4"
                                    >
                                        <div class="flex items-start gap-3">
                                            <span class="text-2xl">{{ issue.icon || '🔴' }}</span>
                                            <div class="flex-1">
                                                <h5 class="font-semibold text-red-900 dark:text-red-100">{{ issue.title }}</h5>
                                                <p class="text-sm text-red-700 dark:text-red-300 mt-1">{{ issue.message }}</p>
                                                <span v-if="issue.score" class="inline-block mt-2 px-2 py-1 text-xs bg-red-200 dark:bg-red-800 text-red-900 dark:text-red-100 rounded">
                                                    Score: {{ issue.score }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Warnings -->
                            <div v-if="checkResult.check_details.content_analysis.warnings && checkResult.check_details.content_analysis.warnings.length > 0" class="mb-4">
                                <h4 class="font-semibold text-orange-600 dark:text-orange-400 mb-3 flex items-center gap-2">
                                    <span class="text-2xl">⚠️</span>
                                    Warnings ({{ checkResult.check_details.content_analysis.warnings.length }})
                                </h4>
                                <div class="space-y-3">
                                    <div
                                        v-for="(warning, index) in checkResult.check_details.content_analysis.warnings"
                                        :key="index"
                                        class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4"
                                    >
                                        <div class="flex items-start gap-3">
                                            <span class="text-2xl">{{ warning.icon || '⚠️' }}</span>
                                            <div class="flex-1">
                                                <h5 class="font-semibold text-orange-900 dark:text-orange-100">{{ warning.title }}</h5>
                                                <p class="text-sm text-orange-700 dark:text-orange-300 mt-1">{{ warning.message }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Suggestions -->
                            <div v-if="checkResult.check_details.content_analysis.suggestions && checkResult.check_details.content_analysis.suggestions.length > 0" class="mb-4">
                                <h4 class="font-semibold text-blue-600 dark:text-blue-400 mb-3 flex items-center gap-2">
                                    <span class="text-2xl">💡</span>
                                    Suggestions ({{ checkResult.check_details.content_analysis.suggestions.length }})
                                </h4>
                                <div class="space-y-3">
                                    <div
                                        v-for="(suggestion, index) in checkResult.check_details.content_analysis.suggestions"
                                        :key="index"
                                        class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4"
                                    >
                                        <div class="flex items-start gap-3">
                                            <span class="text-2xl">{{ suggestion.icon || '💡' }}</span>
                                            <div class="flex-1">
                                                <h5 class="font-semibold text-blue-900 dark:text-blue-100">{{ suggestion.title }}</h5>
                                                <p class="text-sm text-blue-700 dark:text-blue-300 mt-1">{{ suggestion.message }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Spam Rules by Category -->
                        <div v-if="checkResult.check_details && checkResult.check_details.content_analysis && checkResult.check_details.content_analysis.rules_by_category" class="mb-6">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Spam Rules by Category</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div
                                    v-for="(rules, category) in checkResult.check_details.content_analysis.rules_by_category"
                                    :key="category"
                                    class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4"
                                >
                                    <h5 class="font-semibold text-gray-900 dark:text-gray-100 mb-3 capitalize">
                                        {{ category.replace('_', ' ') }} ({{ rules.length }})
                                    </h5>
                                    <div class="space-y-2">
                                        <div
                                            v-for="(rule, index) in rules"
                                            :key="index"
                                            class="bg-white dark:bg-gray-800 rounded p-3 border border-gray-200 dark:border-gray-700"
                                        >
                                            <div class="flex justify-between items-start mb-2">
                                                <span class="font-mono text-sm text-gray-900 dark:text-gray-100">{{ rule.name }}</span>
                                                <span :class="[
                                                    'px-2 py-1 text-xs rounded font-semibold',
                                                    rule.score >= 2 ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                                    rule.score >= 1 ? 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200' :
                                                    rule.score >= 0.5 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                                                    'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
                                                ]">
                                                    +{{ rule.score }}
                                                </span>
                                            </div>
                                            <p class="text-xs text-gray-600 dark:text-gray-400">{{ rule.description || 'No description available' }}</p>
                                            <div class="mt-2 flex items-center gap-2">
                                                <span :class="[
                                                    'px-2 py-0.5 text-xs rounded',
                                                    rule.severity === 'critical' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                                    rule.severity === 'high' ? 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200' :
                                                    rule.severity === 'medium' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                                                    'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
                                                ]">
                                                    {{ rule.severity }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Body Analysis -->
                        <div v-if="checkResult.check_details && checkResult.check_details.body_analysis" class="mb-6">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Email Body Analysis</h4>
                            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Length</div>
                                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ checkResult.check_details.body_analysis.length }} chars</div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Has HTML</div>
                                        <div :class="['font-semibold', checkResult.check_details.body_analysis.has_html ? 'text-green-600' : 'text-gray-400']">
                                            {{ checkResult.check_details.body_analysis.has_html ? 'Yes' : 'No' }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Links</div>
                                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ checkResult.check_details.body_analysis.link_count || 0 }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Images</div>
                                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ checkResult.check_details.body_analysis.image_count || 0 }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="checkResult.recommendations" class="mb-6">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Recommendations</h4>
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                                <pre class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ typeof checkResult.recommendations === 'string' ? checkResult.recommendations : JSON.stringify(checkResult.recommendations, null, 2) }}</pre>
                            </div>
                        </div>

                        <div v-if="checkResult.check_details && checkResult.check_details.raw" class="mb-6">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Raw Response</h4>
                            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                                <pre class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap overflow-x-auto">{{ checkResult.check_details.raw }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.email-preview-html {
    max-width: 100%;
    word-wrap: break-word;
}

.email-preview-html :deep(img) {
    max-width: 100%;
    height: auto;
}

.email-preview-html :deep(table) {
    max-width: 100%;
    border-collapse: collapse;
}

.email-preview-html :deep(a) {
    color: #3b82f6;
    text-decoration: underline;
}

.email-preview-html :deep(style) {
    display: none;
}
</style>

