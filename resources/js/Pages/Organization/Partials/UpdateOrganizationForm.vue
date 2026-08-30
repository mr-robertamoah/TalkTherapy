<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import FormLoader from '@/Components/FormLoader.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    organization: {
        type: Object,
        required: true,
    },
    show: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['closeModal', 'updated'])

const form = useForm(formDataFrom(props.organization))

function formDataFrom(organization) {
    return {
        name: organization.name,
        legalName: organization.legalName,
        registrationNumber: organization.registrationNumber,
        description: organization.description,
        email: organization.email,
        phone: organization.phone,
        selfApplyEnabled: organization.selfApplyEnabled,
    }
}

// useForm() only captures its initial argument once -- without re-syncing here, reopening this
// modal after a previous save would keep showing the pre-save values, not the freshly-updated
// organization prop (same class of staleness Show.vue's organization ref had).
watch(() => props.show, () => {
    if (props.show) {
        form.defaults(formDataFrom(props.organization))
        form.reset()
    }
})

function submit() {
    form.patch(route('organizations.update', { organizationId: props.organization.id }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('updated')
            emit('closeModal')
        },
    })
}
</script>

<template>
    <Modal :show="show" @close="() => emit('closeModal')">
        <div class="p-6">
            <div class="text-lg font-bold text-gray-900 mb-4">Edit Organization Profile</div>

            <FormLoader :show="form.processing" text="saving" />

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="legalName" value="Legal Name" />
                    <TextInput id="legalName" type="text" class="mt-1 block w-full" v-model="form.legalName" />
                    <InputError class="mt-2" :message="form.errors.legalName" />
                </div>

                <div>
                    <InputLabel for="registrationNumber" value="Registration Number" />
                    <TextInput id="registrationNumber" type="text" class="mt-1 block w-full" v-model="form.registrationNumber" />
                    <InputError class="mt-2" :message="form.errors.registrationNumber" />
                </div>

                <div>
                    <InputLabel for="description" value="Description" />
                    <textarea id="description" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" v-model="form.description" rows="3"></textarea>
                    <InputError class="mt-2" :message="form.errors.description" />
                </div>

                <div>
                    <InputLabel for="email" value="Email" />
                    <TextInput id="email" type="email" maxlength="255" class="mt-1 block w-full" v-model="form.email" />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <InputLabel for="phone" value="Phone" />
                    <TextInput
                        id="phone"
                        type="tel"
                        maxlength="255"
                        pattern="[0-9+\s\(\)\-\.]{7,}"
                        title="Use digits, spaces, and + - . ( ) only"
                        class="mt-1 block w-full"
                        v-model="form.phone"
                    />
                    <InputError class="mt-2" :message="form.errors.phone" />
                </div>

                <div v-if="organization.isConsumer" class="flex items-center">
                    <Checkbox id="selfApplyEnabled" :checked="form.selfApplyEnabled" @update:checked="(val) => form.selfApplyEnabled = val" />
                    <InputLabel for="selfApplyEnabled" value="Allow members to self-apply" class="ml-2" />
                </div>

                <div class="flex items-center justify-end">
                    <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                        save
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
