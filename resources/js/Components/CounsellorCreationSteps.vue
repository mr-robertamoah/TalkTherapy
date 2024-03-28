<template>
    <div class="select-none">
        <div
            v-for="(step, idx) in computedSteps"
            :key="idx"
            class="cursor-pointer"
            title="double click to show more information about step"
            @dblclick="() => clickedStep(step)"
        >
            <div class="flex justify-start items-center">
                <div
                    :class="[currentStep >= (idx + 1) ? (light ? 'bg-green-400' : 'bg-green-950') : (light ? 'bg-slate-400' : 'bg-slate-950')]"
                    class="w-5 h-5 rounded-full flex justify-center items-center mr-2 shrink-0">
                    <div
                        :class="[currentStep >= (idx + 1) ? (light ? 'bg-green-300' : 'bg-green-700') : (light ? 'bg-slate-300' : 'bg-slate-700')]"
                        class="w-3 h-3 rounded-full">
                    </div>
                </div>
                <div
                    :class="[currentStep >= (idx + 1) ? (light ? 'bg-green-400' : 'text-green-900') : (light ? 'bg-slate-400' : 'text-slate-900')]"
                    class="text-sm shrink-0"
                >{{ step.name }} . step {{ idx + 1 }}</div>
                <div v-if="showingStepName == step.name && step.title"
                    class="ml-2 shrink text-xs text-pretty"
                    :class="[light ? 'text-slate-300' : 'text-slate-900']"
                >
                    {{ step.title }}
                </div>
            </div>
            <div
                :class="[currentStep >= (idx + 1) ? 'bg-green-700' : 'bg-slate-700']"
                class="w-2 h-6 translate-x-[6px] -z-10 translate-y-1"
                v-if="computedSteps.length - 1 !== idx"
            ></div>
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { ref } from "vue"


const props = defineProps({
    currentStep: {
        type: Number,
        required: true,
    },
    light: {
        type: Boolean,
        default: false,
    },
    steps: {
        default: [],
    },
})

const showingStepName = ref('')
const defaultSteps = ref([
    {name: 'Register', title: 'This is where you register as a counsellor.'},
    {name: 'Be verified', title: 'Here, you submit appropriate and verifiable documentation as proof.'},
    {name: 'Accept to assist', title: 'Once you are verified, you can start to receive requests to assist or you can request to assist someone.'},
    {name: 'Talk therapy', title: 'Get into sessions and talk.'},
])

const computedSteps = computed(() => {
    return props.steps.length ? props.steps : defaultSteps.value
})

function clickedStep(step) {
    showingStepName.value = step.name
}
</script>