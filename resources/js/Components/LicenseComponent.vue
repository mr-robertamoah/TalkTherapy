<script setup>
import FileComponent from './FileComponent.vue';

defineProps({
    license: {
        default: null,
    },
})

</script>

<template>
    <div class="bg-stone-100 rounded p-2">
        <div class="text-sm font-bold text-center text-stone-700 flex justify-center items-center">
            <div>{{ license.licensingAuthority.name }}</div>
            <div
                class="text-sm text-end ml-2 opacity-80 font-normal"
                :class="[license.licensingAuthority.validated ? 'text-green-600' : 'text-red-600']"
            >..{{ license.licensingAuthority.validated ? 'validated' : 'unvalidated' }}..</div>
        </div>
        <div v-if="license.number" class="flex justify-center items-center mt-3 text-sm text-stone-600">
            <div class="mr-2">ID:</div>
            <div class="font-bold">{{ license.number }}</div>
        </div>
        <div v-if="license.file?.length" class="flex justify-center items-center mt-3 text-sm text-stone-600">
            <FileComponent
                :file="license.file[0]"
                class="w-[90%] mx-auto max-w-[200px]"
            />
        </div>
        <div
            class="text-sm text-end mt-2"
            :class="[license.validated ? 'text-green-600' : 'text-red-600']"
        >{{ license.validated ? 'validated' : 'unvalidated' }}</div>
    </div>
</template>