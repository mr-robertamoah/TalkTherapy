<template>
    <div
        v-bind="$attrs"
        class="w-full max-w-[400px] bg-stone-200 p-2 rounded shadow-sm select-none"
        @dblclick="goToPage"
    >
        <div 
            v-if="counsellor.deleted" 
            class="p-2 text-red-700 text-center text-sm"
        >counsellor account has been deleted</div>
        <div 
            v-else-if="useMinimal" 
            class="text-gray-600 flex items-center gap-2"
        >
            <div class="capitalize text-sm">{{ counsellor.name }}</div>
            <div class="text-xs">{{ counsellor.username ? `@${counsellor.username}` : '' }}</div>
        </div>
        <div v-else>
            <div class="flex justify-start items-center mb-3 cursor-pointer space-x-2 overflow-hidden overflow-x-auto p-2">
                <Avatar class="shrink-0" :avatar-text="'...'" :size="40" :src="counsellor?.avatar ?? ''"/>
                <div class="text-gray-600 flex justify-start items-center shrink-0 space-x-2 text-xs sm:text-sm md:text-base">
                    <div class="capitalize">{{ counsellor.name }}</div>
                    <div>{{ counsellor.username ? `(${counsellor.username})` : '' }}</div>
                </div>
            </div>
            <slot></slot>
            
            <div class="mt-3 flex justify-end items-center" v-if="hasView">
                <div
                    @click="() => view = true"
                    class="p-2 bg-blue-700 text-blue-200 cursor-pointer tracking-wide rounded min-w-[80px] text-center hover:bg-blue-400 hover:text-blue-700 transition duration-75">view</div>
            </div>
            <div class="" v-if="forRequest">
                <div class="my-2">
                    <ActivityBadge
                        :name="'number of therapies'"
                        :value="counsellor.allTherapiesCount ?? 0"
                    />
                </div>
                <div 
                    class="flex flex-col justify-center items-center w-full"
                >
                    <div class="my-2 flex justify-start w-full overflow-hidden overflow-x-auto p-2 space-x-2">
                        <div
                            v-for="(item, idx) in ['profession', 'cases', 'languages', 'religions']"
                            :key="idx"
                            @click="() => {
                                selectedItem = item
                            }"
                            class="px-2 py-1 cursor-pointer rounded transition duration-100"
                            :class="[selectedItem == item ? 'bg-slate-300 text-slate-800' : 'bg-slate-200 text-slate-600']"
                        >{{ item }}</div>
                    </div>
                    <div
                        v-if="selectedItem"
                    >
                        <div v-if="selectedItem == 'profession'" class="p-2">
                            <div
                                v-if="counsellor[selectedItem]"
                                :title="counsellor[selectedItem].about ?? ''"
                                class="capitalize mr-3 rounded text-sm p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
                            >{{ counsellor[selectedItem].name }}</div>

                            <div v-else class="text-gray-600 w-full my-2 text-center text-sm">has no {{ selectedItem }} set</div>
                        </div>
                        <div v-else class="p-2 flex justify-start items-center overflow-hidden overflow-x-auto">
                            <template v-if="counsellor[selectedItem]?.length">
                                <div
                                    v-for="(item, idx) in counsellor[selectedItem]"
                                    :title="item.about ?? ''"
                                    :key="idx"
                                    class="capitalize mr-3 rounded shrink-0 text-sm p-2 min-w-[100px] text-gray-700 bg-gray-300 select-none transition duration-75 cursor-pointer hover:bg-gray-600 hover:text-white text-center"
                                >{{ item.name }}</div>

                            </template>

                            <div v-else class="text-gray-600 w-full my-2 text-center text-sm">has no {{ selectedItem }} set</div>
                        </div>
                    </div>
                    <!-- <div v-else class="text-gray-600 w-full my-2 text-center text-sm">nothing selected yet</div> -->
                </div>
            </div>
            <div class="flex justify-end" v-if="online">
                <div 
                    class="mx-2 w-4 h-4 p-1 rounded-full flex justify-center items-center mr-2 bg-green-700"
                >
                    <div 
                        class="w-full h-full rounded-full bg-green-300"
                    ></div>
                </div>
            </div>
        </div>
    </div>

    <CounsellorModal
        :show="view"
        @close="() => view = false"
        :counsellor="counsellor"
    />
</template>

<script setup>
import { ref } from 'vue';
import Avatar from './Avatar.vue';
import CounsellorModal from './CounsellorModal.vue';
import ActivityBadge from './ActivityBadge.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    counsellor: {
        default: null
    },
    hasView: {
        type: Boolean,
        default: true
    },
    useMinimal: {
        type: Boolean,
        default: false
    },
    visitPage: {
        type: Boolean,
        default: true
    },
    online: {
        type: Boolean,
        default: false
    },
    forRequest: {
        type: Boolean,
        default: false
    }
})

const emits = defineEmits(['onResponse', 'dblclick'])

const view = ref(false)
const selectedItem = ref(null)

function clickedResponse(response) {
    emits('onResponse', response)
}

function goToPage() {
    emits('dblclick')
    if (props.visitPage && props.counsellor)
        router.get(route('counsellor.show', { counsellorId: props.counsellor.id}))
}
</script>