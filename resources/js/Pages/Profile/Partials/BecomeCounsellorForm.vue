<script setup>
import CounsellorCreationSteps from '@/Components/CounsellorCreationSteps.vue';
import BecomeCounsellorButton from '@/Components/BecomeCounsellorButton.vue';

defineProps({
    counsellorCreationStep: {
        type: Number,
    },
});

const emits = defineEmits(['changeStep'])

function onSuccessful() {
    emits('changeStep', 1)
}

</script>

<template>
    <div>
        <section>
            <header>
                <h2 class="text-lg font-medium text-gray-300">Become Counsellor</h2>

                <p class="mt-1 text-sm text-gray-100 text-justify">
                    There are millions of people with several conditions that require <span class="font-bold">Talk Therapy</span>.
                </p>
                <p class="mt-1 text-sm text-gray-100 text-justify">
                    If you are a licensed counsellor from a recognised institution (governmental, medical, religious, etc) and willing to engage individuals on this platform, kindly start the process by clicking the button below to register as a counsellor.
                </p>

                <CounsellorCreationSteps
                    class="mt-8"
                    :current-step="counsellorCreationStep"
                    :light="true"
                />
                <div
                    class="max-w-xl"
                >
                    <BecomeCounsellorButton
                        @successful="onSuccessful"
                        class="mr-2 my-2 float-right"/>
                </div>
            </header>
        </section>

    </div>
</template>