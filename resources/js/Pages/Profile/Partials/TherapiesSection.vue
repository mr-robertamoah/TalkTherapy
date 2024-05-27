<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Therapies</h2>

            <p class="mt-1 text-sm text-gray-600">
                Check your therapies by clicking the button below.
            </p>

            <div class="flex items-center my-4">
                <StyledLink :text="'show therapies'" :href="route('therapies')" />
            </div>

        </header>
    </section>
</template>

<script setup>
import StyledLink from "@/Components/StyledLink.vue"

</script>