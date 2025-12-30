<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    title: String,
});

const loading = ref(false);
const checking = ref(false);
const campaigns = ref([]);
const showModal = ref(false);
const editingCampaign = ref(null);
const checkResult = ref(null);

const form = ref({
    name: '',
    subject: '',
    html_content: '',
    text_content: '',
    from_email: '',
    from_name: '',
    reply_to: '',
    to_emails: [],
    headers: {},
});

const loadCampaigns = async () => {
    try {
        loading.value = true;
        const response = await window.axios.get('/api/campaigns', {
            withCredentials: true,
        });
        campaigns.value = response.data;
    } catch (error) {
        console.error('Failed to load campaigns:', error);
    } finally {
        loading.value = false;
    }
};

const openModal = (campaign = null) => {
    editingCampaign.value = campaign;
    if (campaign) {
        form.value = {
            name: campaign.name,
            subject: campaign.subject,
            html_content: campaign.html_content || '',
            text_content: campaign.text_content || '',
            from_email: campaign.from_email,
            from_name: campaign.from_name || '',
            reply_to: campaign.reply_to || '',
            to_emails: campaign.to_emails || [],
            headers: campaign.headers || {},
        };
    } else {
        form.value = {
            name: '',
            subject: '',
            html_content: '',
            text_content: '',
            from_email: '',
            from_name: '',
            reply_to: '',
            to_emails: [],
            headers: {},
        };
    }
    checkResult.value = null;
    showModal.value = true;
};

const saveCampaign = async () => {
    try {
        loading.value = true;
        if (editingCampaign.value) {
            await window.axios.put(`/api/campaigns/${editingCampaign.value.id}`, form.value, {
                withCredentials: true,
            });
            showModal.value = false;
            await loadCampaigns();
        } else {
            try {
                loading.value = true;
                const response = await window.axios.post('/api/campaigns', form.value, {
                    withCredentials: true,
                });
                
                // Always redirect to check page after creation (check is automatic)
                if (response.data && response.data.id) {
                    showModal.value = false;
                    // Use window.location for reliable redirect that works every time
                    window.location.href = `/inbox-insight/campaign/${response.data.id}/check`;
                    return; // Exit early to prevent further execution
                } else {
                    showModal.value = false;
                    loading.value = false;
                    await loadCampaigns();
                }
            } catch (error) {
                console.error('Failed to create campaign:', error);
                loading.value = false;
                
                // Show detailed validation errors
                if (error.response?.status === 422 && error.response?.data?.errors) {
                    const errors = error.response.data.errors;
                    const errorMessages = Object.entries(errors)
                        .map(([field, messages]) => `${field}: ${Array.isArray(messages) ? messages.join(', ') : messages}`)
                        .join('\n');
                    alert('Validation errors:\n' + errorMessages);
                } else {
                    alert('Failed to create campaign: ' + (error.response?.data?.message || error.message));
                }
            }
        }
    } catch (error) {
        console.error('Failed to save campaign:', error);
        alert('Failed to save campaign: ' + (error.response?.data?.message || error.message));
    } finally {
        loading.value = false;
    }
};

const deleteCampaign = async (id) => {
    if (!confirm('Are you sure you want to delete this campaign?')) return;
    
    try {
        loading.value = true;
        await window.axios.delete(`/api/campaigns/${id}`, {
            withCredentials: true,
        });
        await loadCampaigns();
    } catch (error) {
        console.error('Failed to delete campaign:', error);
        alert('Failed to delete campaign');
    } finally {
        loading.value = false;
    }
};

const checkCampaign = async (id) => {
    // Redirect to dedicated check page
    window.location.href = `/inbox-insight/campaign/${id}/check`;
};

const viewCheckResults = (id) => {
    // Navigate to check results page
    window.location.href = `/inbox-insight/campaign/${id}/check`;
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

onMounted(() => {
    loadCampaigns();
});
</script>

<template>
    <AppLayout title="Inbox Insight">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Inbox Insight
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Email Campaigns</h3>
                            <PrimaryButton @click="openModal()">Create Campaign</PrimaryButton>
                        </div>

                        <div v-if="loading" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                        </div>
                        <div v-else>
                            <div v-if="campaigns.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                No campaigns yet. Create one to get started.
                            </div>
                            <div v-else class="space-y-4">
                                <div
                                    v-for="campaign in campaigns"
                                    :key="campaign.id"
                                    class="border border-gray-200 dark:border-gray-700 rounded-lg p-4"
                                >
                                    <div class="flex justify-between items-start">
                                        <div class="flex-1">
                                            <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ campaign.name }}</h4>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ campaign.subject }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-2">
                                                From: {{ campaign.from_email }}
                                                <span v-if="campaign.latest_check_result">
                                                    | Last checked: {{ new Date(campaign.latest_check_result.checked_at).toLocaleString() }}
                                                </span>
                                            </p>
                                            <div v-if="campaign.latest_check_result" class="mt-2">
                                                <div class="flex items-center gap-4">
                                                    <div>
                                                        <span class="text-xs text-gray-500">Spam Score:</span>
                                                        <span :class="['font-bold', getScoreColor(campaign.latest_check_result.spam_score, campaign.latest_check_result.spam_threshold)]">
                                                            {{ campaign.latest_check_result.spam_score }}/{{ campaign.latest_check_result.spam_threshold }}
                                                        </span>
                                                    </div>
                                                    <div v-if="campaign.latest_check_result.deliverability_score">
                                                        <span class="text-xs text-gray-500">Deliverability:</span>
                                                        <span :class="['font-bold', getGradeColor(campaign.latest_check_result.deliverability_score.grade)]">
                                                            {{ campaign.latest_check_result.deliverability_score.overall }}% ({{ campaign.latest_check_result.deliverability_score.grade }})
                                                        </span>
                                                    </div>
                                                    <span
                                                        :class="[
                                                            'px-2 py-1 text-xs rounded-full',
                                                            campaign.latest_check_result.is_spam
                                                                ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                                                : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                                                        ]"
                                                    >
                                                        {{ campaign.latest_check_result.is_spam ? 'SPAM' : 'OK' }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex gap-2 ml-4">
                                            <button
                                                @click="checkCampaign(campaign.id)"
                                                class="px-3 py-1 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700"
                                            >
                                                Check
                                            </button>
                                            <button
                                                v-if="campaign.latest_check_result"
                                                @click="viewCheckResults(campaign.id)"
                                                class="px-3 py-1 text-sm bg-green-600 text-white rounded hover:bg-green-700"
                                            >
                                                View Results
                                            </button>
                                            <button
                                                @click="openModal(campaign)"
                                                class="px-3 py-1 text-sm bg-blue-600 text-white rounded hover:bg-blue-700"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                @click="deleteCampaign(campaign.id)"
                                                class="px-3 py-1 text-sm bg-red-600 text-white rounded hover:bg-red-700"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Check Result -->
                <div v-if="checkResult" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Check Results</h3>
                        
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
                            </div>
                        </div>

                        <div v-if="checkResult.spam_rules && checkResult.spam_rules.length > 0" class="mb-6">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Spam Rules Matched</h4>
                            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                                <div class="space-y-2">
                                    <div
                                        v-for="(rule, index) in checkResult.spam_rules"
                                        :key="index"
                                        class="flex justify-between items-center text-sm"
                                    >
                                        <span class="text-gray-700 dark:text-gray-300">{{ rule.name }}</span>
                                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ rule.score }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="checkResult.recommendations" class="mb-6">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Recommendations</h4>
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                                <pre class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ checkResult.recommendations }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Campaign Modal -->
        <Modal :show="showModal" @close="showModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    {{ editingCampaign ? 'Edit' : 'Create' }} Email Campaign
                </h2>

                <div class="space-y-4 max-h-[70vh] overflow-y-auto">
                    <div>
                        <InputLabel for="name" value="Campaign Name" />
                        <TextInput
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="mt-1 block w-full"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel for="subject" value="Subject" />
                        <TextInput
                            id="subject"
                            v-model="form.subject"
                            type="text"
                            class="mt-1 block w-full"
                            required
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="from_email" value="From Email" />
                            <TextInput
                                id="from_email"
                                v-model="form.from_email"
                                type="email"
                                class="mt-1 block w-full"
                                required
                            />
                        </div>
                        <div>
                            <InputLabel for="from_name" value="From Name (optional)" />
                            <TextInput
                                id="from_name"
                                v-model="form.from_name"
                                type="text"
                                class="mt-1 block w-full"
                            />
                        </div>
                    </div>

                    <div>
                        <InputLabel for="reply_to" value="Reply-To (optional)" />
                        <TextInput
                            id="reply_to"
                            v-model="form.reply_to"
                            type="email"
                            class="mt-1 block w-full"
                        />
                    </div>

                    <div>
                        <InputLabel for="html_content" value="HTML Content" />
                        <textarea
                            id="html_content"
                            v-model="form.html_content"
                            rows="8"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                            placeholder="<html>...</html>"
                        ></textarea>
                    </div>

                    <div>
                        <InputLabel for="text_content" value="Plain Text Content (recommended)" />
                        <textarea
                            id="text_content"
                            v-model="form.text_content"
                            rows="6"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                            placeholder="Plain text version of your email"
                        ></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <PrimaryButton @click="saveCampaign" :disabled="loading">
                        {{ editingCampaign ? 'Update' : 'Create' }}
                    </PrimaryButton>
                    <button
                        @click="showModal = false"
                        class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-400 dark:hover:bg-gray-600"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

