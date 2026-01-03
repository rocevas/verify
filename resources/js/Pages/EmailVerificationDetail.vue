<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    verificationId: String, // UUID, not Number
    title: String,
});

const loading = ref(false);
const verification = ref(null);

const loadVerification = async () => {
    loading.value = true;
    try {
        console.log('Loading verification for UUID:', props.verificationId);
        const response = await axios.get(`/api/dashboard/verifications/${props.verificationId}`, {
            withCredentials: true,
        });
        console.log('Verification response:', response.data);
        verification.value = response.data.verification;
    } catch (error) {
        console.error('Failed to load verification:', error);
        console.error('Error response:', error.response?.data);
        console.error('Error status:', error.response?.status);
        if (error.response?.status === 404) {
            console.error('Verification not found. UUID:', props.verificationId);
        }
    } finally {
        loading.value = false;
    }
};

const getStatusColor = (status) => {
    const colors = {
        // State values
        'deliverable': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'undeliverable': 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'risky': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'unknown': 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
        'error': 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        // Result values (for backward compatibility)
        'valid': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'invalid': 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'catch_all': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'do_not_mail': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    };
    return colors[status] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
};

const getStatusBadge = (status) => {
    return status.charAt(0).toUpperCase() + status.slice(1);
};

onMounted(() => {
    loadVerification();
});
</script>

<template>
    <AppLayout title="Email Verification Detail">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                Email Verification Details
                            </h2>
                            <PrimaryButton @click="router.visit('/verifications')">
                                Back to Verifications
                            </PrimaryButton>
                        </div>

                        <div v-if="loading" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                        </div>

                        <div v-else-if="verification" class="space-y-6">
                            <!-- Email, State and Result -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Email Address
                                    </label>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {{ verification.email }}
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        State
                                    </label>
                                    <span v-if="verification.state" :class="['px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full', getStatusColor(verification.state)]">
                                        {{ getStatusBadge(verification.state) }}
                                    </span>
                                    <span v-else class="text-sm text-gray-500 dark:text-gray-400">N/A</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Result
                                    </label>
                                    <span v-if="verification.result" :class="['px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full', getStatusColor(verification.result)]">
                                        {{ getStatusBadge(verification.result) }}
                                    </span>
                                    <span v-else class="text-sm text-gray-500 dark:text-gray-400">N/A</span>
                                </div>
                            </div>

                            <!-- Score -->
                            <div v-if="verification.score !== null" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Score
                                    </label>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {{ verification.score }}/100
                                    </div>
                                    <div class="mt-2 w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                                        <div
                                            class="h-2.5 rounded-full transition-all"
                                            :class="verification.score >= 70 ? 'bg-green-600' : verification.score >= 40 ? 'bg-yellow-600' : 'bg-red-600'"
                                            :style="{ width: `${verification.score}%` }"
                                        ></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Checks -->
                            <div v-if="verification.checks" class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                    Verification Checks
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div v-for="(value, key) in verification.checks" :key="key" class="flex items-center justify-between">
                                        <span class="text-sm text-gray-700 dark:text-gray-300 capitalize">
                                            {{ key.replace(/_/g, ' ') }}
                                        </span>
                                        <span :class="[
                                            'px-2 py-1 text-xs font-semibold rounded',
                                            value ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                        ]">
                                            {{ value ? 'Pass' : 'Fail' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- AI Analysis -->
                            <div v-if="verification.ai_analysis || verification.ai_confidence !== null || verification.ai_insights || verification.ai_risk_factors" class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                                    <span>🧠</span>
                                    AI Analysis
                                </h3>
                                
                                <div v-if="verification.ai_confidence !== null" class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        AI Confidence
                                    </label>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {{ verification.ai_confidence }}%
                                    </div>
                                    <div class="mt-2 w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                                        <div
                                            class="bg-blue-600 h-2.5 rounded-full transition-all"
                                            :style="{ width: `${verification.ai_confidence}%` }"
                                        ></div>
                                    </div>
                                </div>

                                <div v-if="verification.ai_insights" class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        AI Insights
                                    </label>
                                    <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap bg-white dark:bg-gray-800 rounded p-3 border border-blue-200 dark:border-blue-700">
                                        {{ verification.ai_insights }}
                                    </div>
                                </div>

                                <div v-if="verification.ai_risk_factors && Array.isArray(verification.ai_risk_factors) && verification.ai_risk_factors.length > 0" class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        AI Risk Factors
                                    </label>
                                    <div class="space-y-2">
                                        <div
                                            v-for="(risk, index) in verification.ai_risk_factors"
                                            :key="index"
                                            class="flex items-start gap-2 bg-white dark:bg-gray-800 rounded p-2 border border-orange-200 dark:border-orange-700"
                                        >
                                            <span class="text-orange-600 dark:text-orange-400 mt-0.5">⚠️</span>
                                            <span class="text-sm text-gray-700 dark:text-gray-300 flex-1">{{ risk }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div v-else-if="verification.ai_risk_factors && typeof verification.ai_risk_factors === 'object'" class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        AI Risk Factors
                                    </label>
                                    <div class="space-y-2">
                                        <div
                                            v-for="(value, key) in verification.ai_risk_factors"
                                            :key="key"
                                            class="flex items-center justify-between bg-white dark:bg-gray-800 rounded p-2 border border-orange-200 dark:border-orange-700"
                                        >
                                            <span class="text-sm text-gray-700 dark:text-gray-300 capitalize">{{ key.replace(/_/g, ' ') }}</span>
                                            <span class="text-sm font-semibold" :class="value ? 'text-orange-600 dark:text-orange-400' : 'text-gray-400'">
                                                {{ value ? '⚠️' : '✓' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Metadata -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Source
                                    </label>
                                    <div class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ verification.source || 'N/A' }}
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Verified At
                                    </label>
                                    <div class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ new Date(verification.created_at).toLocaleString() }}
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

