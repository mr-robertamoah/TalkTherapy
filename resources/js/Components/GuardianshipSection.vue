<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Guardianship</h2>

            <FormLoader :text="'sending guardianship request'" :show="loading && !modalData.type"/>

            <div>
                <div class="mt-4 text-sm text-gray-600">Wards</div>
                <div 
                    v-if="computedWards?.length"
                    class="flex justify-start overflow-hidden overflow-x-auto space-x-2 items-start w-[90%] p-2 mx-auto mt-4"
                >
                    <GuardianshipComponent
                        :is-ward="true"
                        v-for="(ward, idx) in computedWards"
                        :data="ward"
                        :key="idx"
                        class="w-[70%] sm:w-[60%] shrink-0"
                        @removed="remvoveGuardianship"
                    />
                </div>

                <div v-else class="text-sm text-gray-600 mt-4 w-[90%] sm:w-[80%] mx-auto">
                    You have no wards at this time. If you believe a ward has an account, have him/her send you a guardianship request or get a guardianship link from him/her
                </div>
            </div>

            <div>
                <div class="mt-4 text-sm text-gray-600">Guardians</div>
                <div 
                    v-if="computedGuardians?.length"
                    class="flex justify-start overflow-hidden space-x-2 overflow-x-auto items-start w-[90%] p-2 mx-auto mt-4"
                >
                    <GuardianshipComponent
                        :is-ward="false"
                        v-for="(guardian, idx) in computedGuardians"
                        :data="guardian"
                        :key="idx"
                        class="w-[70%] sm:w-[60%] shrink-0"
                    />
                </div>

                <div v-else class="text-sm text-gray-600 mt-4 w-[90%] sm:w-[80%] mx-auto">
                    You have no guardians at this time. If you believe an account holder can be your guardian, click the button below to either send a request to that user or create a guardianship link.
                </div>
            </div>

            <div v-if="!loading" class="mt-4 flex w-[90%] sm:w-[80%] mx-auto space-x-3 justify-start items-center overflow-hidden overflow-x-auto p-2">
                <PrimaryButton class="shrink-0" @click="() => showModal('add')">add guardian</PrimaryButton>
            </div>
        </header>

        <Alert
            :show="alertData.show"
            :type="alertData.type"
            :message="alertData.message"
            :time="alertData.time"
            @close="clearAlertData"
        />

        <AddGuardianModal
            :show="modalData.show && modalData.type == 'add'"
            @close="closeModal"
        />
    </section>
</template>

<script setup>
import useAlert from '@/Composables/useAlert';
import useAuth from '@/Composables/useAuth';
import { onBeforeMount, ref } from 'vue';
import Alert from './Alert.vue';
import FormLoader from './FormLoader.vue';
import useModal from '@/Composables/useModal';
import AddGuardianModal from './AddGuardianModal.vue';
import GuardianshipComponent from './GuardianshipComponent.vue';
import { computed } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import { usePage } from '@inertiajs/vue3';

const { goToLogin } = useAuth()
const { modalData, showModal, closeModal } = useModal()
const { alertData, clearAlertData, setSuccessAlertData, setFailedAlertData } = useAlert ()

const user = usePage().props.auth.user

const props = defineProps({
    
})

onBeforeMount(() => {
    getGuardians()
})

const loading = ref(false)
const guardianship = ref([])

const computedWards = computed(() => {
    return guardianship.value.filter((data) => data.isWard ? true : false)
})
const computedGuardians = computed(() => {
    return guardianship.value.filter((data) => !data.isWard ? true : false)
})

async function getGuardians() {
    loading.value = true

    await axios.get(route(`api.users.guardianship`))
        .then((res) => {
            console.log(res)
            guardianship.value = [...res.data.data]
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                })
                return
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
            })
        })

    loading.value = false
}

function remvoveGuardianship(data) {
    guardianship.value.splice(guardianship.value.findIndex((g) => g.id == data.id), 1)
}

</script>