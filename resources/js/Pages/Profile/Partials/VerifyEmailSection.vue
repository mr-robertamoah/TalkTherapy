<script setup>
import StyledLink from '@/Components/StyledLink.vue';

defineProps({
    status: {
        type: String,
    },
    email: {
        type: String,
    },
});

</script>

<template>
    <section>
        <h2 class="text-lg font-medium text-gray-900">Email Verification</h2>

        <p v-if="!email" class="mt-1 text-sm text-gray-600">
            You need to provide you email address before you can verify it.
            Your email has to be verified before you can continue to receive email notifications regarding things like therapies/requests/etc.
            Most importantly, you cannot create a therapy without a verified email.
        </p>

        <template v-else>

            <p class="mt-1 text-sm text-gray-600">
                Your email has to be verified before you can continue to receive email notifications regarding things like therapies/requests/etc.
                Most importantly, you cannot create a therapy without a verified email.
            </p>

            <div class="text-sm text-gray-600">
                <p class="text-sm mt-2 text-gray-800">
                    Your email address is unverified. Click the 'VERIFY EMAIL' button to have the verification email sent to you. 
                    Check you mail box for our verification email and click 'Verify Email Address' button in the email to have your email verified.
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 font-medium text-sm text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>
            <StyledLink 
                :text="'verify email'" 
                :as="'button'" 
                :method="'post'" 
                :href="route('verification.send')" class="mr-2 mt-2 float-right"/>
        </template>
    </section>
</template>
