<script setup>
import { ref, onMounted, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const messages = ref([]);
const input = ref('');
const isProcessing = ref(false);
const fileInput = ref(null);
const messagesEnd = ref(null);

const scrollToBottom = () => {
    nextTick(() => {
        if (messagesEnd.value) {
            messagesEnd.value.scrollIntoView({ behavior: 'smooth' });
        }
    });
};

const addMessage = (content, type = 'assistant', data = null) => {
    messages.value.push({
        id: Date.now() + Math.random(),
        content,
        type, // 'user', 'assistant', 'system'
        data,
        timestamp: new Date(),
    });
    scrollToBottom();
};

// Automatically detect input type
const detectInputType = (text) => {
    if (!text || !text.trim()) return null;
    
    const trimmed = text.trim();
    const lines = trimmed.split(/[\n,;]/).map(l => l.trim()).filter(l => l.length > 0);
    
    // Check if it's a single email
    if (lines.length === 1 && trimmed.includes('@') && trimmed.split('@').length === 2) {
        return 'single';
    }
    
    // Check if it's multiple emails
    const emailCount = lines.filter(line => {
        const parts = line.split('@');
        return parts.length === 2 && parts[0].length > 0 && parts[1].length > 0;
    }).length;
    
    if (emailCount > 1) {
        return 'batch';
    }
    
    // If it looks like email but only one, treat as single
    if (emailCount === 1) {
        return 'single';
    }
    
    return null;
};

const verifyEmail = async () => {
    if (!input.value.trim()) {
        return;
    }

    const userInput = input.value.trim();
    const inputType = detectInputType(userInput);
    
    if (!inputType) {
        addMessage('Please enter a valid email address or multiple emails (one per line or comma-separated)', 'assistant');
        return;
    }
    
    addMessage(userInput, 'user');
    input.value = '';
    
    if (inputType === 'single') {
        await verifySingleEmail(userInput);
    } else {
        await verifyBatchEmails(userInput);
    }
};

const triggerFileUpload = () => {
    if (fileInput.value) {
        fileInput.value.click();
    }
};

const handleFileUpload = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    
    // Validate file type
    const validTypes = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];
    const validExtensions = ['.csv', '.txt'];
    const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
    
    if (!validTypes.includes(file.type) && !validExtensions.includes(fileExtension)) {
        addMessage('Please upload a CSV or TXT file', 'assistant');
        // Reset file input
        if (fileInput.value) {
            fileInput.value.value = '';
        }
        return;
    }
    
    addMessage(`📎 ${file.name}`, 'user');
    verifyFile(file);
    // Reset file input after processing starts
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const verifySingleEmail = async (email) => {
    isProcessing.value = true;
    
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }

        const response = await fetch('/api/ai/verify/stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'text/event-stream',
            },
            credentials: 'include',
            body: JSON.stringify({ email }),
        });

        if (!response.ok) {
            const errorText = await response.text();
            console.error('Response error:', response.status, errorText);
            throw new Error(`Verification failed: ${response.status} ${response.statusText}`);
        }

        if (!response.body) {
            throw new Error('Response body is null');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let currentMessage = null;
        let buffer = '';

        try {
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    if (line.trim() === '') continue;
                    
                    if (line.startsWith('data: ')) {
                        try {
                            const data = JSON.parse(line.slice(6));
                            
                            if (data.type === 'error') {
                                throw new Error(data.message || 'Unknown error');
                            }
                            
                            if (data.type === 'step') {
                                if (!currentMessage) {
                                    currentMessage = {
                                        id: Date.now(),
                                        content: '',
                                        type: 'assistant',
                                        steps: [],
                                        isProcessing: true,
                                        timestamp: new Date(),
                                    };
                                    messages.value.push(currentMessage);
                                }
                                
                                currentMessage.steps.push({
                                    message: data.message,
                                    step: data.step,
                                    data: data.data,
                                });
                                
                                // Update content with latest step
                                currentMessage.content = data.message;
                                scrollToBottom();
                            } else if (data.type === 'result') {
                                if (currentMessage) {
                                    currentMessage.isProcessing = false;
                                    currentMessage.result = data.data;
                                    currentMessage.content = formatResult(data.data);
                                } else {
                                    addMessage(formatResult(data.data), 'assistant', data.data);
                                }
                                scrollToBottom();
                            }
                        } catch (e) {
                            if (e instanceof Error && e.message.includes('error')) {
                                throw e;
                            }
                            console.error('Error parsing SSE data:', e, line);
                        }
                    }
                }
            }
        } finally {
            reader.releaseLock();
        }
    } catch (error) {
        console.error('Verification error:', error);
        addMessage(`Error: ${error.message}`, 'assistant');
    } finally {
        isProcessing.value = false;
        scrollToBottom();
    }
};

const verifyBatchEmails = async (emailsText) => {
    const emails = emailsText
        .split(/[,\n;]/)
        .map(e => e.trim())
        .filter(e => e.length > 0 && e.includes('@'));

    if (emails.length === 0) {
        addMessage('No valid emails found', 'assistant');
        return;
    }

    if (emails.length > 100) {
        addMessage('Maximum 100 emails allowed per batch', 'assistant');
        return;
    }

    isProcessing.value = true;
    
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }

        const response = await fetch('/api/ai/verify/batch/stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'text/event-stream',
            },
            credentials: 'include',
            body: JSON.stringify({ emails }),
        });

        if (!response.ok) {
            const errorText = await response.text();
            console.error('Response error:', response.status, errorText);
            throw new Error(`Batch verification failed: ${response.status} ${response.statusText}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let currentBatchMessage = null;
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() || '';

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    try {
                        const data = JSON.parse(line.slice(6));
                        
                        if (data.type === 'email_start') {
                            if (!currentBatchMessage) {
                                currentBatchMessage = {
                                    id: Date.now(),
                                    content: `Processing ${data.total} emails...`,
                                    type: 'assistant',
                                    emails: [],
                                    isProcessing: true,
                                    timestamp: new Date(),
                                };
                                messages.value.push(currentBatchMessage);
                            }
                            
                            // Check if email already exists, if not add it
                            let emailItem = currentBatchMessage.emails.find(e => e.email === data.email);
                            if (!emailItem) {
                                emailItem = {
                                    email: data.email,
                                    index: data.index,
                                    status: 'processing',
                                    currentStep: 'Starting...',
                                    steps: [],
                                };
                                currentBatchMessage.emails.push(emailItem);
                            } else {
                                emailItem.status = 'processing';
                                emailItem.currentStep = 'Starting...';
                            }
                            
                            currentBatchMessage.content = `Processing email ${data.index}/${data.total}: ${data.email}`;
                            scrollToBottom();
                        } else if (data.type === 'step') {
                            const emailItem = currentBatchMessage?.emails?.find(e => e.email === data.email);
                            if (emailItem) {
                                if (!emailItem.steps) emailItem.steps = [];
                                emailItem.steps.push({
                                    message: data.message,
                                    step: data.step,
                                });
                                // Update current step for real-time feedback
                                emailItem.currentStep = data.message;
                                // Update batch message with current progress
                                if (currentBatchMessage) {
                                    const processingCount = currentBatchMessage.emails.filter(e => e.status === 'processing').length;
                                    const completeCount = currentBatchMessage.emails.filter(e => e.status === 'complete').length;
                                    currentBatchMessage.content = `Processing ${data.index}/${data.total} emails (${completeCount} completed, ${processingCount} in progress)`;
                                }
                            }
                            scrollToBottom();
                        } else if (data.type === 'email_complete') {
                            const emailItem = currentBatchMessage?.emails?.find(e => e.email === data.email);
                            if (emailItem) {
                                emailItem.status = 'complete';
                                emailItem.result = data.result;
                                emailItem.currentStep = `Completed: ${data.result.status}`;
                            }
                            
                            if (currentBatchMessage && data.progress) {
                                const processingCount = currentBatchMessage.emails.filter(e => e.status === 'processing').length;
                                currentBatchMessage.content = `Processed ${data.progress.current}/${data.progress.total} emails (${data.progress.valid} valid, ${data.progress.invalid} invalid, ${data.progress.risky} risky)`;
                            }
                            scrollToBottom();
                        } else if (data.type === 'batch_complete') {
                            if (currentBatchMessage) {
                                currentBatchMessage.isProcessing = false;
                                // Hide individual emails and show summary instead
                                currentBatchMessage.showSummary = true;
                                currentBatchMessage.content = formatBatchResult(data.summary);
                                currentBatchMessage.bulkJobId = data.bulk_job_id;
                                currentBatchMessage.summary = data.summary;
                            }
                            scrollToBottom();
                        }
                    } catch (e) {
                        console.error('Error parsing SSE data:', e);
                    }
                }
            }
        }
    } catch (error) {
        console.error('Batch verification error:', error);
        addMessage(`Error: ${error.message}`, 'assistant');
    } finally {
        isProcessing.value = false;
        scrollToBottom();
    }
};

const verifyFile = async (file) => {
    isProcessing.value = true;
    
    const formData = new FormData();
    formData.append('file', file);
    
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }

        const response = await fetch('/api/ai/verify/upload/stream', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'text/event-stream',
            },
            credentials: 'include',
            body: formData,
        });

        if (!response.ok) {
            const errorText = await response.text();
            console.error('Response error:', response.status, errorText);
            throw new Error(`File verification failed: ${response.status} ${response.statusText}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let currentBatchMessage = null;
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() || '';

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    try {
                        const data = JSON.parse(line.slice(6));
                        
                        if (data.type === 'email_start') {
                            if (!currentBatchMessage) {
                                currentBatchMessage = {
                                    id: Date.now(),
                                    content: `Processing ${data.total} emails from file...`,
                                    type: 'assistant',
                                    emails: [],
                                    isProcessing: true,
                                    timestamp: new Date(),
                                };
                                messages.value.push(currentBatchMessage);
                            }
                            
                            // Check if email already exists
                            let emailItem = currentBatchMessage.emails.find(e => e.email === data.email);
                            if (!emailItem) {
                                emailItem = {
                                    email: data.email,
                                    index: data.index,
                                    status: 'processing',
                                    currentStep: 'Starting...',
                                    steps: [],
                                };
                                currentBatchMessage.emails.push(emailItem);
                            } else {
                                emailItem.status = 'processing';
                                emailItem.currentStep = 'Starting...';
                            }
                            
                            currentBatchMessage.content = `Processing email ${data.index}/${data.total}: ${data.email}`;
                            scrollToBottom();
                        } else if (data.type === 'step') {
                            const emailItem = currentBatchMessage?.emails?.find(e => e.email === data.email);
                            if (emailItem) {
                                if (!emailItem.steps) emailItem.steps = [];
                                emailItem.steps.push({
                                    message: data.message,
                                    step: data.step,
                                });
                                emailItem.currentStep = data.message;
                                if (currentBatchMessage) {
                                    const processingCount = currentBatchMessage.emails.filter(e => e.status === 'processing').length;
                                    const completeCount = currentBatchMessage.emails.filter(e => e.status === 'complete').length;
                                    currentBatchMessage.content = `Processing ${data.index}/${data.total} emails (${completeCount} completed, ${processingCount} in progress)`;
                                }
                            }
                            scrollToBottom();
                        } else if (data.type === 'email_complete') {
                            const emailItem = currentBatchMessage?.emails?.find(e => e.email === data.email);
                            if (emailItem) {
                                emailItem.status = 'complete';
                                emailItem.result = data.result;
                                emailItem.currentStep = `Completed: ${data.result.status}`;
                            }
                            
                            if (currentBatchMessage && data.progress) {
                                const processingCount = currentBatchMessage.emails.filter(e => e.status === 'processing').length;
                                currentBatchMessage.content = `Processed ${data.progress.current}/${data.progress.total} emails (${data.progress.valid} valid, ${data.progress.invalid} invalid, ${data.progress.risky} risky)`;
                            }
                            scrollToBottom();
                        } else if (data.type === 'batch_complete') {
                            if (currentBatchMessage) {
                                currentBatchMessage.isProcessing = false;
                                currentBatchMessage.showSummary = true;
                                currentBatchMessage.content = formatBatchResult(data.summary);
                                currentBatchMessage.bulkJobId = data.bulk_job_id;
                                currentBatchMessage.summary = data.summary;
                            }
                            scrollToBottom();
                        }
                    } catch (e) {
                        console.error('Error parsing SSE data:', e);
                    }
                }
            }
        }
    } catch (error) {
        console.error('File verification error:', error);
        addMessage(`Error: ${error.message}`, 'assistant');
    } finally {
        isProcessing.value = false;
        scrollToBottom();
    }
};

const formatResult = (result) => {
    if (!result) return 'Verification completed';
    
    const statusEmoji = {
        'valid': '✅',
        'invalid': '❌',
        'risky': '⚠️',
        'catch_all': '🔶',
        'do_not_mail': '🚫',
    };
    
    let text = `${statusEmoji[result.status] || '📧'} **${result.email}**\n\n`;
    text += `**Status:** ${result.status}\n`;
    text += `**Score:** ${result.score}/100\n\n`;
    
    text += `**Checks:**\n`;
    text += `- Syntax: ${result.checks?.syntax ? '✅' : '❌'}\n`;
    text += `- MX Records: ${result.checks?.mx ? '✅' : '❌'}\n`;
    text += `- SMTP: ${result.checks?.smtp ? '✅' : '❌'}\n`;
    text += `- Disposable: ${result.checks?.disposable ? '❌ Yes' : '✅ No'}\n`;
    text += `- Role-based: ${result.checks?.role ? '⚠️ Yes' : '✅ No'}\n`;
    
    if (result.checks?.ai_analysis) {
        text += `- AI Analysis: ✅\n`;
    }
    
    if (result.ai_insights) {
        text += `\n**AI Insights:**\n${result.ai_insights}\n`;
    }
    
    if (result.ai_confidence !== null) {
        text += `\n**AI Confidence:** ${result.ai_confidence}%\n`;
    }
    
    if (result.error) {
        text += `\n**Error:** ${result.error}\n`;
    }
    
    return text;
};

const formatBatchResult = (summary) => {
    return `✅ **Batch verification completed!**\n\n` +
           `**Summary:**\n` +
           `- Total: ${summary.total}\n` +
           `- Valid: ${summary.valid}\n` +
           `- Invalid: ${summary.invalid}\n` +
           `- Risky: ${summary.risky}\n\n` +
           `Click "View Full Report" to see detailed results for all emails.`;
};

const handleKeyPress = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        verifyEmail();
    }
};

onMounted(() => {
    addMessage('Hello! I can help you verify email addresses.\n\nYou can:\n- Enter a single email\n- Paste multiple emails (one per line, comma, or semicolon separated)\n- Upload a CSV/TXT file using the 📎 button', 'assistant');
});
</script>

<template>
    <AppLayout title="AI Email Verification">
        <div class="flex flex-col h-screen bg-gray-50 dark:bg-gray-900">
            <!-- Header -->
            <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    AI Email Verification
                </h1>
                    </div>

            <!-- Messages -->
            <div class="flex-1 overflow-y-auto px-4 py-6 space-y-4">
                <div v-for="message in messages" :key="message.id" class="flex gap-4">
                    <div v-if="message.type === 'user'" class="flex-1"></div>
                    
                    <div
                                :class="[
                            'max-w-3xl rounded-lg px-4 py-3',
                            message.type === 'user'
                                ? 'bg-indigo-600 text-white ml-auto'
                                : 'bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700'
                        ]"
                    >
                        <!-- User message -->
                        <div v-if="message.type === 'user'" class="whitespace-pre-wrap">
                            {{ message.content }}
                        </div>

                        <!-- Assistant message -->
                        <div v-else class="space-y-2">
                            <div class="flex items-start gap-2">
                                <div class="flex-1">
                                    <div class="whitespace-pre-wrap">{{ message.content }}</div>
                                    <!-- Progress indicator for batch -->
                                    <div v-if="message.isProcessing && message.emails && message.emails.length > 0" class="mt-2">
                                        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span>Processing...</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Spinner for processing (single email) -->
                                <div v-if="message.isProcessing && !message.emails" class="flex-shrink-0">
                                    <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </div>
                            </div>
                            
                            <!-- Steps (for single email) - show all steps with icons -->
                            <div v-if="message.steps && message.steps.length > 0" class="mt-3 space-y-2 text-sm">
                                <div 
                                    v-for="(step, idx) in message.steps" 
                                    :key="idx" 
                                    class="flex items-start gap-2 p-2 bg-gray-50 dark:bg-gray-900/50 rounded border border-gray-200 dark:border-gray-700"
                                >
                                    <div class="flex-shrink-0 mt-0.5">
                                        <span v-if="step.message.includes('✅')" class="text-green-600">✅</span>
                                        <span v-else-if="step.message.includes('❌')" class="text-red-600">❌</span>
                                        <span v-else-if="step.message.includes('⚠️')" class="text-orange-600">⚠️</span>
                                        <span v-else-if="step.message.includes('🔍')" class="text-blue-600">🔍</span>
                                        <span v-else-if="step.message.includes('📧')" class="text-indigo-600">📧</span>
                                        <span v-else-if="step.message.includes('🤖')" class="text-purple-600">🤖</span>
                                        <span v-else-if="step.message.includes('🧠')" class="text-purple-600">🧠</span>
                                        <span v-else-if="step.message.includes('⏳')" class="text-yellow-600">⏳</span>
                                        <span v-else class="text-gray-400">•</span>
                                    </div>
                                    <div class="flex-1 text-gray-700 dark:text-gray-300">
                                        {{ step.message }}
                                    </div>
                                </div>
                            </div>

                            <!-- Result (for single email) - Detailed view -->
                            <div v-if="message.result" class="mt-3 space-y-3">
                                <!-- Main Info Card -->
                                <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="font-semibold text-base">{{ message.result.email }}</h4>
                                        <span 
                                            :class="[
                                                'px-3 py-1 text-sm font-semibold rounded-full',
                                                message.result.status === 'valid' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                                message.result.status === 'invalid' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                                message.result.status === 'risky' ? 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200' :
                                                'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
                                            ]"
                                        >
                                            {{ message.result.status }}
                                        </span>
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-3 mb-3">
                                        <div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">Score</div>
                                            <div class="text-lg font-semibold">{{ message.result.score }}/100</div>
                                        </div>
                                        <div v-if="message.result.ai_confidence !== null">
                                            <div class="text-xs text-gray-500 dark:text-gray-400">AI Confidence</div>
                                            <div class="text-lg font-semibold">{{ message.result.ai_confidence }}%</div>
                                        </div>
                                    </div>

                                    <!-- Checks -->
                                    <div class="border-t border-gray-200 dark:border-gray-700 pt-3 mt-3">
                                        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Verification Checks:</div>
                                        <div class="grid grid-cols-2 gap-2 text-sm">
                                            <div class="flex items-center gap-2">
                                                <span :class="message.result.checks?.syntax ? 'text-green-600' : 'text-red-600'">
                                                    {{ message.result.checks?.syntax ? '✅' : '❌' }}
                                                </span>
                                                <span>Syntax</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span :class="message.result.checks?.mx ? 'text-green-600' : 'text-red-600'">
                                                    {{ message.result.checks?.mx ? '✅' : '❌' }}
                                                </span>
                                                <span>MX Records</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span :class="message.result.checks?.smtp ? 'text-green-600' : 'text-yellow-600'">
                                                    {{ message.result.checks?.smtp ? '✅' : '⏳' }}
                                                </span>
                                                <span>SMTP</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span :class="!message.result.checks?.disposable ? 'text-green-600' : 'text-red-600'">
                                                    {{ !message.result.checks?.disposable ? '✅' : '❌' }}
                                                </span>
                                                <span>Disposable</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span :class="!message.result.checks?.role ? 'text-green-600' : 'text-orange-600'">
                                                    {{ !message.result.checks?.role ? '✅' : '⚠️' }}
                                                </span>
                                                <span>Role-based</span>
                                            </div>
                                            <div class="flex items-center gap-2" v-if="message.result.checks?.ai_analysis">
                                                <span class="text-purple-600">✅</span>
                                                <span>AI Analysis</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- AI Insights Card -->
                                <div v-if="message.result.ai_insights || message.result.ai_confidence !== null" class="p-4 bg-purple-50 dark:bg-purple-900/20 rounded-lg border border-purple-200 dark:border-purple-800">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-purple-600">🤖</span>
                                        <h5 class="font-semibold text-sm text-purple-900 dark:text-purple-200">AI Analysis</h5>
                                    </div>
                                    <div v-if="message.result.ai_insights" class="text-sm text-purple-800 dark:text-purple-300 mb-2">
                                        {{ message.result.ai_insights }}
                                    </div>
                                    <div v-if="message.result.ai_confidence !== null" class="text-xs text-purple-600 dark:text-purple-400">
                                        Confidence: {{ message.result.ai_confidence }}%
                                    </div>
                                </div>

                                <!-- Error if any -->
                                <div v-if="message.result.error" class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                                    <div class="text-sm text-red-800 dark:text-red-200">
                                        <strong>Error:</strong> {{ message.result.error }}
                                    </div>
                                </div>
                            </div>

                            <!-- Batch results - Real-time progress view -->
                            <div v-if="message.emails && message.emails.length > 0 && !message.showSummary" class="mt-3 space-y-2">
                                <!-- Progress bar -->
                                <div class="mb-3">
                                    <div class="flex justify-between text-xs text-gray-600 dark:text-gray-400 mb-1">
                                        <span>Progress</span>
                                        <span>
                                            {{ message.emails.filter(e => e.status === 'complete').length }} / {{ message.emails.length }} completed
                                        </span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                        <div 
                                            class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                                            :style="`width: ${(message.emails.filter(e => e.status === 'complete').length / message.emails.length) * 100}%`"
                                        ></div>
                                    </div>
                                </div>

                                <!-- Email list with real-time status -->
                                <div class="max-h-96 overflow-y-auto space-y-2">
                                    <div
                                        v-for="emailItem in message.emails"
                                        :key="emailItem.email"
                                        class="p-3 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex-1 min-w-0">
                                                <div class="font-medium text-sm truncate">{{ emailItem.email }}</div>
                                                <!-- Current step indicator -->
                                                <div v-if="emailItem.status === 'processing'" class="mt-1 text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                                    <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                    <span class="truncate">{{ emailItem.currentStep || 'Processing...' }}</span>
                                                </div>
                                                <!-- Quick result preview -->
                                                <div v-else-if="emailItem.result" class="mt-1 flex items-center gap-2 text-xs">
                                                    <span 
                                                        :class="[
                                                            'px-2 py-0.5 rounded font-semibold',
                                                            emailItem.result.status === 'valid' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                                            emailItem.result.status === 'invalid' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                                            'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200'
                                                        ]"
                                                    >
                                                        {{ emailItem.result.status }}
                                                    </span>
                                                    <span class="text-gray-500">Score: {{ emailItem.result.score }}/100</span>
                                                    <span v-if="emailItem.result.ai_confidence !== null" class="text-purple-600">
                                                        AI: {{ emailItem.result.ai_confidence }}%
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Batch summary (after completion) -->
                            <div v-if="message.showSummary && message.summary" class="mt-3 p-4 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg border border-indigo-200 dark:border-indigo-800">
                                <div class="flex items-center justify-between mb-3">
                                    <h4 class="font-semibold text-indigo-900 dark:text-indigo-200">Verification Summary</h4>
                                    <span class="text-2xl">✅</span>
                                </div>
                                
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ message.summary.total }}</div>
                                        <div class="text-xs text-gray-600 dark:text-gray-400">Total</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-green-600">{{ message.summary.valid }}</div>
                                        <div class="text-xs text-gray-600 dark:text-gray-400">Valid</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-red-600">{{ message.summary.invalid }}</div>
                                        <div class="text-xs text-gray-600 dark:text-gray-400">Invalid</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-orange-600">{{ message.summary.risky }}</div>
                                        <div class="text-xs text-gray-600 dark:text-gray-400">Risky</div>
                                    </div>
                                </div>

                                <div v-if="message.bulkJobId" class="pt-3 border-t border-indigo-200 dark:border-indigo-800">
                                    <button
                                        @click="router.visit(`/verifications/bulk/${message.bulkJobId}`)"
                                        class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium"
                                    >
                                        View Full Report →
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div v-if="message.type !== 'user'" class="flex-1"></div>
                </div>

                <div ref="messagesEnd"></div>
                        </div>

            <!-- Input area -->
            <div class="border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-4">
                <div class="max-w-4xl mx-auto">
                    <div class="flex gap-2 items-end">
                        <!-- File upload button -->
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".csv,.txt"
                            @change="handleFileUpload"
                            class="hidden"
                        />
                        <button
                            type="button"
                            @click="triggerFileUpload"
                            :disabled="isProcessing"
                            class="px-4 py-3 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed transition"
                            title="Upload CSV/TXT file"
                        >
                            📎
                        </button>
                        
                        <!-- Text input -->
                        <textarea
                            v-model="input"
                            @keypress="handleKeyPress"
                            placeholder="Enter email address(es) or upload a file..."
                            rows="1"
                            class="flex-1 px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 resize-none focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            :disabled="isProcessing"
                        ></textarea>
                        
                        <!-- Send button -->
                        <button
                            @click="verifyEmail"
                            :disabled="isProcessing || !input.trim()"
                            class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            <span v-if="isProcessing">Processing...</span>
                            <span v-else>Send</span>
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400 text-center">
                        Enter one email, multiple emails (separated by line/comma/semicolon), or upload a CSV/TXT file
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Custom scrollbar */
.overflow-y-auto::-webkit-scrollbar {
    width: 8px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>
