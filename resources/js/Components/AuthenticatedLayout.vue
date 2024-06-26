<script setup>
import { ref, watch, computed, watchEffect } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import BecomeCounsellorButton from '@/Components/BecomeCounsellorButton.vue';
import StyledLink from '@/Components/StyledLink.vue';
import useAlert from '@/Composables/useAlert';
import Alert from '@/Components/Alert.vue';
import RequestModal from '@/Components/RequestModal.vue';
import CreateTherapyButton from '@/Components/CreateTherapyButton.vue';

const { alertData, clearAlertData, setAlertData } = useAlert()

watchEffect(
    () => {
        if (usePage().props.message?.length) {
            setTimeout(() => {
                
                setAlertData({
                    show: true,
                    type: 'failed',
                    message: usePage().props.message
                })
            }, 1000);
        }
    }
)

const showRequest = ref(false);
const showingNavigationDropdown = ref(false);
const showBecomeCounsellorButton = computed(() => {
    return !(route().current() == 'profile.show' || route().params?.counsellorId == usePage().props.auth.user?.counsellor?.id)
})
const showCounsellorLink = computed(() => {
    return !(route().params?.counsellorId == usePage().props.auth.user?.counsellor?.id)
})
const showRequestLink = computed(() => {
    return usePage().props.auth.user?.counsellor
})
const showAdministratorLink = computed(() => {
    return usePage().props.auth.user?.isAdmin && route().current() != 'administrator'
})
</script>

<template>
    <div>
        <div class="min-h-screen bg-stone-100">
            <nav class="bg-white border-b border-gray-100">
                <!-- Primary Navigation Menu -->
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="shrink-0 flex items-center">
                                <Link :href="route('home')">
                                    <ApplicationLogo
                                        class="block h-10 w-10"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div v-if="$page.props.auth.user" class="hidden space-x-12 sm:-my-px sm:ms-10 sm:flex">
                                <!-- <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                    Dashboard
                                </NavLink> -->
                                <CreateTherapyButton :text="'become counsellor'" class="flex items-center"/>
                                <BecomeCounsellorButton v-if="showBecomeCounsellorButton" class="flex items-center" :text="'become counsellor'"/>
                            </div>
                        </div>

                        <template v-if="$page.props.auth.user">
                            <div class="hidden sm:flex sm:items-center sm:ms-6">
                                <!-- Settings Dropdown -->
                                <div class="ms-3 relative">
                                    <Dropdown align="right" width="48">
                                        <template #trigger>
                                            <span class="inline-flex rounded-md">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150"
                                                >
                                                    {{ $page.props.auth.user.username }}

                                                    <svg
                                                        class="ms-2 -me-0.5 h-4 w-4"
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                    >
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                            clip-rule="evenodd"
                                                        />
                                                    </svg>
                                                </button>
                                            </span>
                                        </template>

                                        <template #content>
                                            <DropdownLink v-if="route().current() !== 'profile.show'" :href="route('profile.show')"> User Profile </DropdownLink>
                                            <DropdownLink
                                                v-if="showCounsellorLink"
                                                :href="route('counsellor.show', $page.props.auth.user?.counsellor.id)"
                                            > Counsellor Profile </DropdownLink>
                                            <div
                                                v-if="showRequestLink"
                                                @click="() => {
                                                    showRequest = true
                                                }"
                                                class="cursor-pointer block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
                                            >Requests</div>
                                            <DropdownLink
                                                v-if="showAdministratorLink"
                                                :href="route('administrator')"
                                            > Administrator </DropdownLink>
                                            <DropdownLink :href="route('logout')" method="post" as="button">
                                                Log Out
                                            </DropdownLink>
                                        </template>
                                    </Dropdown>
                                </div>
                            </div>

                            <!-- Hamburger -->
                            <div class="-me-2 flex items-center sm:hidden">
                                <button
                                    @click="showingNavigationDropdown = !showingNavigationDropdown"
                                    class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out"
                                >
                                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                        <path
                                            :class="{
                                                hidden: showingNavigationDropdown,
                                                'inline-flex': !showingNavigationDropdown,
                                            }"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M4 6h16M4 12h16M4 18h16"
                                        />
                                        <path
                                            :class="{
                                                hidden: !showingNavigationDropdown,
                                                'inline-flex': showingNavigationDropdown,
                                            }"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </template>
                        <div class="flex justify-end items-center" v-else>
                            <StyledLink
                                :href="route('login')" 
                                class="my-auto mr-2"
                                :text="'login'"
                            />
                            <StyledLink
                                :href="route('register')" 
                                class="my-auto mr-2"
                                :text="'register'"
                            />
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }"
                    class="sm:hidden"
                    v-if="$page.props.auth.user"
                >
                    <div class="pt-2 pb-3 space-y-1">
                        <!-- <ResponsiveNavLink :href="route('dashboard')" :active="route().current('dashboard')">
                            Dashboard
                        </ResponsiveNavLink> -->
                        <BecomeCounsellorButton v-if="showBecomeCounsellorButton" :text="'become counsellor'" class="ml-4"/>
                        <CreateTherapyButton :text="'become counsellor'" class="ml-4"/>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div class="pt-4 pb-1 border-t border-gray-200">
                        <div class="px-4">
                            <div class="font-medium text-base text-gray-800">
                                {{ $page.props.auth.user.username }}
                            </div>
                            <div class="font-medium text-sm text-gray-500">{{ $page.props.auth.user.email }}</div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink v-if="route().current() !== 'profile.show'" :href="route('profile.show')"> User Profile </ResponsiveNavLink>
                            <ResponsiveNavLink
                                v-if="showCounsellorLink"
                                :href="route('counsellor.show', $page.props.auth.user?.counsellor.id)"
                            > Counsellor Profile </ResponsiveNavLink>
                            <div
                                v-if="showRequestLink"
                                @click="() => {
                                    showRequest = true
                                }"
                                class="cursor-pointer"
                                :class="[
                                    showRequest
                                    ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 text-start text-base font-medium text-indigo-700 bg-indigo-50 focus:outline-none focus:text-indigo-800 focus:bg-indigo-100 focus:border-indigo-700 transition duration-150 ease-in-out'
                                    : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out']" 
                            >Requests</div>
                            <ResponsiveNavLink
                                v-if="showAdministratorLink"
                                :href="route('administrator')"
                            > Administrator </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('logout')" method="post" as="button">
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header class="bg-white shadow" v-if="$slots.header">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />

    <RequestModal
        :show="showRequest"
        @close-modal="() => {
            showRequest = false
        }"
    />
</template>
