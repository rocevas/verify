<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import Banner from '@/Components/Banner.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';

const props = defineProps({
    title: String,
});

const page = usePage();
const showingNavigationDropdown = ref(false);

// Get sidebar verifications from Inertia shared props
const sidebarVerifications = computed(() => page.props.sidebarVerifications || {
    bulk_jobs: [],
    individual_verifications: [],
});

const bulkJobs = computed(() => sidebarVerifications.value.bulk_jobs || []);
const individualVerifications = computed(() => sidebarVerifications.value.individual_verifications || []);

// Combined and sorted list of all verifications
const allVerifications = computed(() => {
    const combined = [];

    // Add bulk jobs
    bulkJobs.value.forEach(job => {
        combined.push({
            id: job.id,
            type: 'bulk',
            title: job.filename,
            status: job.status,
            stats: job.stats,
            total_emails: job.total_emails,
            processed_emails: job.processed_emails || 0,
            created_at: job.created_at,
            completed_at: job.completed_at,
        });
    });

    // Add individual verifications
    individualVerifications.value.forEach(verification => {
        combined.push({
            id: verification.id,
            type: 'individual',
            title: verification.email,
            email: verification.email,
            status: verification.state || verification.result || 'unknown',
            score: verification.score,
            created_at: verification.created_at,
        });
    });

    // Sort by created_at (newest first)
    return combined.sort((a, b) => {
        const dateA = new Date(a.created_at);
        const dateB = new Date(b.created_at);
        return dateB - dateA;
    });
});

const switchToTeam = (team) => {
    router.put(route('current-team.update'), {
        team_id: team.id,
    }, {
        preserveState: false,
    });
};

const logout = () => {
    router.post(route('logout'));
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;

    return date.toLocaleDateString();
};

const getStatusColor = (status) => {
    const colors = {
        // State values
        'deliverable': 'text-green-600 dark:text-green-400',
        'undeliverable': 'text-red-600 dark:text-red-400',
        'risky': 'text-yellow-600 dark:text-yellow-400',
        'unknown': 'text-gray-600 dark:text-gray-400',
        'error': 'text-red-600 dark:text-red-400',
        // Result values
        'valid': 'text-green-600 dark:text-green-400',
        'invalid': 'text-red-600 dark:text-red-400',
        'catch_all': 'text-yellow-600 dark:text-yellow-400',
        'syntax_error': 'text-red-600 dark:text-red-400',
        'typo': 'text-red-600 dark:text-red-400',
        'disposable': 'text-red-600 dark:text-red-400',
        'blocked': 'text-red-600 dark:text-red-400',
        'mailbox_full': 'text-yellow-600 dark:text-yellow-400',
        'role': 'text-yellow-600 dark:text-yellow-400',
        'mailbox_not_found': 'text-red-600 dark:text-red-400',
        // Legacy status values (for backward compatibility)
        'do_not_mail': 'text-yellow-600 dark:text-yellow-400',
        // Bulk job statuses
        'processing': 'text-blue-600 dark:text-blue-400',
        'pending': 'text-gray-600 dark:text-gray-400',
        'completed': 'text-green-600 dark:text-green-400',
        'failed': 'text-red-600 dark:text-red-400',
    };
    return colors[status] || 'text-gray-600 dark:text-gray-400';
};

// Watch for page updates to refresh sidebar data
router.on('finish', () => {
    // Sidebar data is automatically updated via Inertia shared props
});
</script>

<template>
    <div>
        <Head :title="title" />

        <div class="flex min-h-screen max-h-screen overflow-hidden">
            <aside class="flex flex-col gap-6 w-64 p-5  border-r border-white/10">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <Link :href="route('dashboard')">
                        <ApplicationMark class="block h-6 w-auto text-white" />
                    </Link>
                </div>

                <div class="flex flex-col gap-2 mt-2">
                    <span class="text-sm opacity-70">Chats</span>
                    <Link :href="route('dashboard')" class="flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 px-2.5 py-2 text-sm">
                        <svg class="w-4 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5 18.89H6.41421L15.7279 9.57627L14.3137 8.16206L5 17.4758V18.89ZM21 20.89H3V16.6473L16.435 3.21231C16.8256 2.82179 17.4587 2.82179 17.8492 3.21231L20.6777 6.04074C21.0682 6.43126 21.0682 7.06443 20.6777 7.45495L9.24264 18.89H21V20.89ZM15.7279 6.74785L17.1421 8.16206L18.5563 6.74785L17.1421 5.33363L15.7279 6.74785Z"></path></svg>
                        New Chat
                    </Link>
                </div>

                <!-- Email and batches list -->
                <div class="flex flex-col gap-2 overflow-hidden h-full -mx-5">
                    <span class="text-sm opacity-70 px-5">Recent Verifications</span>
                    <div class="space-y-1 overflow-y-auto px-5">

                        <!-- Combined list of all verifications -->
                        <div v-for="item in allVerifications" :key="`${item.type}-${item.id}`" class="mb-2">
                            <!-- Bulk Job -->
                            <Link
                                v-if="item.type === 'bulk'"
                                :href="route('verifications.bulk', item.id)"
                                class="block p-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-800 transition-colors border border-gray-200 dark:border-gray-700"
                                :class="{ 'bg-blue-50 dark:bg-blue-900/20 border-blue-300 dark:border-blue-700': route().current('verifications.bulk') && $page.url.includes(`/verifications/bulk/${item.id}`) }"
                            >
                                <div class="flex items-start justify-between mb-1">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs">📦</span>
                                            <div class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate" :title="item.title">
                                                {{ item.title.length > 22 ? item.title.substring(0, 22) + '...' : item.title }}
                                            </div>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            {{ formatDate(item.created_at) }}
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 mt-1">
                                    <span :class="getStatusColor(item.status)" class="text-xs font-medium">
                                        {{ item.status }}
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ item.stats?.total || item.processed_emails || 0 }}/{{ item.total_emails }}
                                    </span>
                                </div>
                                <div v-if="item.stats" class="flex items-center gap-2 mt-1 text-xs">
                                    <span class="text-green-600 dark:text-green-400">✓ {{ item.stats.valid }}</span>
                                    <span class="text-red-600 dark:text-red-400">✗ {{ item.stats.invalid }}</span>
                                    <span class="text-yellow-600 dark:text-yellow-400">⚠ {{ item.stats.risky }}</span>
                                </div>
                            </Link>

                            <!-- Individual Email -->
                            <Link
                                v-else
                                :href="route('verifications.email', item.id)"
                                class="block p-2 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-800 transition-colors"
                                :class="{ 'bg-blue-50 dark:bg-blue-900/20 border-blue-300 dark:border-blue-700': route().current('verifications.email') && $page.url.includes(`/verifications/email/${item.id}`) }"
                            >
                                <div class="flex items-start justify-between mb-1">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs">📧</span>
                                            <div class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate" :title="item.email">
                                                {{ item.email.length > 20 ? item.email.substring(0, 20) + '...' : item.email }}
                                            </div>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            {{ formatDate(item.created_at) }}
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 mt-1">
                                    <span :class="getStatusColor(item.status)" class="text-xs font-medium">
                                        {{ item.status }}
                                    </span>
                                        <span v-if="item.score !== null" class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ item.score }}/100
                                        </span>
                                    </div>
                            </Link>
                        </div>

                        <!-- Empty state -->
                        <div v-if="!loading && allVerifications.length === 0" class="text-xs text-gray-500 dark:text-gray-400 text-center py-4">
                            No verifications yet
                        </div>
                    </div>
                </div>

                <!-- View all verifications link -->
                <div>
                    <Link
                        :href="route('verifications')"
                        class="flex items-center gap-2 rounded-lg hover:bg-white/10 px-2.5 py-2 text-sm"
                        :class="{ 'bg-white/10' : route().current('verifications') || route().current('verifications.bulk') }"
                    >
                        View all verifications →
                    </Link>
                </div>
            </aside>

            <main class="w-full flex-1 min-h-screen">
                <nav class="sticky top-0 z-50 bg-white dark:bg-gray-900 border-b  border-gray-100 dark:border-white/10">
                    <!-- Primary Navigation Menu -->
                    <div  class="px-4 sm:px-6 lg:px-8">
                        <div class="flex justify-between h-14">
                            <div class="flex">

                                <div class="flex items-center">Email verification</div>

                                <!-- Navigation Links -->
                                <div v-if="false" class="hidden space-x-8 sm:flex">
                                    <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                        Dashboard
                                    </NavLink>
<!--                                    <NavLink :href="route('dashboard-old')" :active="route().current('dashboard-old')">-->
<!--                                        Dashboard Old-->
<!--                                    </NavLink>-->
                                    <NavLink :href="route('verifications')" :active="route().current('verifications') || route().current('verifications.bulk')">
                                        Verifications
                                    </NavLink>
<!--                                    <NavLink :href="route('monitors')" :active="route().current('monitors')">-->
<!--                                        Monitors-->
<!--                                    </NavLink>-->
<!--                                    <NavLink :href="route('inbox-insight')" :active="route().current('inbox-insight')">-->
<!--                                        Inbox Insight-->
<!--                                    </NavLink>-->
                                </div>
                            </div>

                            <div class="hidden sm:flex sm:items-center sm:ms-6">
                                <div v-if="false" class="ms-3 relative">
                                    <!-- Teams Dropdown -->
                                    <Dropdown v-if="$page.props.jetstream.hasTeamFeatures" align="right" width="60">
                                        <template #trigger>
                                            <span class="inline-flex rounded-md">
                                                <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none focus:bg-gray-50 dark:focus:bg-gray-700 active:bg-gray-50 dark:active:bg-gray-700 transition ease-in-out duration-150">
                                                    {{ $page.props.auth.user.current_team.name }}

                                                    <svg class="ms-2 -me-0.5 size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                                    </svg>
                                                </button>
                                            </span>
                                        </template>

                                        <template #content>
                                            <div class="w-60">
                                                <!-- Team Management -->
                                                <div class="block px-4 py-2 text-xs text-gray-400">
                                                    Manage Team
                                                </div>

                                                <!-- Team Settings -->
                                                <DropdownLink :href="route('teams.show', $page.props.auth.user.current_team)">
                                                    Team Settings
                                                </DropdownLink>

                                                <DropdownLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')">
                                                    Create New Team
                                                </DropdownLink>

                                                <!-- Team Switcher -->
                                                <template v-if="$page.props.auth.user.all_teams.length > 1">
                                                    <div class="border-t border-gray-200 dark:border-gray-600" />

                                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                                        Switch Teams
                                                    </div>

                                                    <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                                        <form @submit.prevent="switchToTeam(team)">
                                                            <DropdownLink as="button">
                                                                <div class="flex items-center">
                                                                    <svg v-if="team.id == $page.props.auth.user.current_team_id" class="me-2 size-5 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                    </svg>

                                                                    <div>{{ team.name }}</div>
                                                                </div>
                                                            </DropdownLink>
                                                        </form>
                                                    </template>
                                                </template>
                                            </div>
                                        </template>
                                    </Dropdown>
                                </div>

                                <!-- Settings Dropdown -->
                                <div class="ms-3 relative">
                                    <Dropdown align="right" width="48">
                                        <template #trigger>
                                            <div v-if="$page.props.jetstream.managesProfilePhotos" class="flex items-center gap-2">
                                                {{ $page.props.auth.user.name }}

                                                <button  class="flex text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-blue-400 transition">
                                                    <img class="size-7 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                                                </button>
                                            </div>

                                            <span v-else class="inline-flex rounded-md">
                                                <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none focus:bg-gray-50 dark:focus:bg-gray-700 active:bg-gray-50 dark:active:bg-gray-700 transition ease-in-out duration-150">
                                                    {{ $page.props.auth.user.name }}

                                                    <svg class="ms-2 -me-0.5 size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                    </svg>
                                                </button>
                                            </span>
                                        </template>

                                        <template #content>
                                            <!-- Account Management -->
                                            <div class="block px-4 py-2 text-xs text-gray-400">
                                                Manage Account
                                            </div>

                                            <DropdownLink :href="route('profile.show')">
                                                Profile
                                            </DropdownLink>

                                            <DropdownLink v-if="$page.props.jetstream.hasTeamFeatures" :href="route('teams.show', $page.props.auth.user.current_team)">
                                                Team Settings
                                            </DropdownLink>

                                            <DropdownLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')">
                                                API Tokens
                                            </DropdownLink>

                                            <div class="border-t border-gray-200 dark:border-gray-600" />

                                            <!-- Authentication -->
                                            <form @submit.prevent="logout">
                                                <DropdownLink as="button">
                                                    Log Out
                                                </DropdownLink>
                                            </form>
                                        </template>
                                    </Dropdown>
                                </div>
                            </div>

                            <!-- Hamburger -->
                            <div class="-me-2 flex items-center sm:hidden">
                                <button class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-900 focus:text-gray-500 dark:focus:text-gray-400 transition duration-150 ease-in-out" @click="showingNavigationDropdown = ! showingNavigationDropdown">
                                    <svg
                                        class="size-6"
                                        stroke="currentColor"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            :class="{'hidden': showingNavigationDropdown, 'inline-flex': ! showingNavigationDropdown }"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M4 6h16M4 12h16M4 18h16"
                                        />
                                        <path
                                            :class="{'hidden': ! showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Responsive Navigation Menu -->
                    <div :class="{'block': showingNavigationDropdown, 'hidden': ! showingNavigationDropdown}" class="sm:hidden">
                        <div class="pt-2 pb-3 space-y-1">
                            <ResponsiveNavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                Dashboard
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('verifications')" :active="route().current('verifications') || route().current('verifications.bulk')">
                                Verifications
                            </ResponsiveNavLink>
                        </div>

                        <!-- Responsive Settings Options -->
                        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
                            <div class="flex items-center px-4">
                                <div v-if="$page.props.jetstream.managesProfilePhotos" class="shrink-0 me-3">
                                    <img class="size-10 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                                </div>

                                <div>
                                    <div class="font-medium text-base text-gray-800 dark:text-gray-200">
                                        {{ $page.props.auth.user.name }}
                                    </div>
                                    <div class="font-medium text-sm text-gray-500">
                                        {{ $page.props.auth.user.email }}
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 space-y-1">
                                <ResponsiveNavLink :href="route('profile.show')" :active="route().current('profile.show')">
                                    Profile
                                </ResponsiveNavLink>

                                <ResponsiveNavLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')" :active="route().current('api-tokens.index')">
                                    API Tokens
                                </ResponsiveNavLink>

                                <!-- Authentication -->
                                <form method="POST" @submit.prevent="logout">
                                    <ResponsiveNavLink as="button">
                                        Log Out
                                    </ResponsiveNavLink>
                                </form>

                                <!-- Team Management -->
                                <template v-if="$page.props.jetstream.hasTeamFeatures">
                                    <div class="border-t border-gray-200 dark:border-gray-600" />

                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                        Manage Team
                                    </div>

                                    <!-- Team Settings -->
                                    <ResponsiveNavLink :href="route('teams.show', $page.props.auth.user.current_team)" :active="route().current('teams.show')">
                                        Team Settings
                                    </ResponsiveNavLink>

                                    <ResponsiveNavLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')" :active="route().current('teams.create')">
                                        Create New Team
                                    </ResponsiveNavLink>

                                    <!-- Team Switcher -->
                                    <template v-if="$page.props.auth.user.all_teams.length > 1">
                                        <div class="border-t border-gray-200 dark:border-gray-600" />

                                        <div class="block px-4 py-2 text-xs text-gray-400">
                                            Switch Teams
                                        </div>

                                        <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                            <form @submit.prevent="switchToTeam(team)">
                                                <ResponsiveNavLink as="button">
                                                    <div class="flex items-center">
                                                        <svg v-if="team.id == $page.props.auth.user.current_team_id" class="me-2 size-5 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                        <div>{{ team.name }}</div>
                                                    </div>
                                                </ResponsiveNavLink>
                                            </form>
                                        </template>
                                    </template>
                                </template>
                            </div>
                        </div>
                    </div>
                </nav>

                <!-- Page Content -->
                <div class="h-[calc(100vh-3.5rem)]" :class="{
                    'overflow-y-auto' : !route().current('dashboard')
                }">
                    <Banner />
                    <!-- Page Heading -->
                    <header v-if="$slots.header" class="">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            <slot name="header" />
                        </div>
                    </header>
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
