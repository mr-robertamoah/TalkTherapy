<script setup>
// SCRUM-214: an organization admin creates a join (self-apply) link to send someone. Each link works once, for one
// person (PerformOrganizationSelfApplyLinkAction deactivates it on use), so the admin creates one per person. Opening
// it files that person's request to join, which lands in the request queue below. The server refuses a link that
// couldn't work (EnsureUserCanCreateOrganizationSelfApplyLinkAction); this card says why up front instead.
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import useAppLink from '@/Composables/useAppLink'

const props = defineProps({
    organization: { type: Object, required: true },
})

const { createLink } = useAppLink()
const page = usePage()

const creating = ref(false)
const linkUrl = ref('')
const copied = ref(false)
const copyFailed = ref(false)

const blockedReason = computed(() => {
    if (!props.organization.selfApplyEnabled) {
        return 'Turn on "Allow members to self-apply" (edit organization) to create join links.'
    }
    if (!props.organization.isVerified) {
        return 'Join links can be created once your organization is verified.'
    }
    return ''
})

async function create() {
    creating.value = true
    copied.value = false
    const link = await createLink({
        type: 'ORGANIZATION_SELF_APPLY',
        addedbyType: 'User',
        addedbyId: page.props.auth.user.id,
        forType: 'Organization',
        forId: props.organization.id,
    })
    creating.value = false
    if (!link) return
    linkUrl.value = `${window.location.origin}${route('links.get', { uuid: link.uuid }, false)}`
}

async function copy() {
    copyFailed.value = false
    try {
        await navigator.clipboard.writeText(linkUrl.value)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    } catch {
        // No clipboard (an insecure context, or permission refused): say so -- the link stays selectable in the box.
        copied.value = false
        copyFailed.value = true
    }
}
</script>

<template>
    <section class="bg-white overflow-hidden shadow-xl border border-gray-100 sm:rounded-xl" aria-labelledby="self-apply-link-title">
        <div class="p-6 sm:p-8 space-y-4">
            <div>
                <h3 id="self-apply-link-title" class="text-xl font-bold text-gray-900">Join links</h3>
                <div class="w-12 h-1 bg-blue-600 mt-2"></div>
            </div>

            <p v-if="blockedReason" class="text-sm text-gray-600">{{ blockedReason }}</p>

            <template v-else>
                <p class="text-sm text-gray-600">
                    Send someone a link to ask to join as a member. Each link works <strong>once, for one person</strong> --
                    create a new one for each person. Their request appears in your request queue for you to accept.
                </p>

                <div v-if="linkUrl" class="space-y-2">
                    <label for="self-apply-link-url" class="block text-sm font-medium text-gray-700">Join link</label>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input
                            id="self-apply-link-url"
                            :value="linkUrl"
                            readonly
                            class="flex-1 rounded-md border-gray-300 bg-gray-50 text-sm text-gray-800 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            @focus="(event) => event.target.select()"
                        />
                        <SecondaryButton type="button" @click="copy">{{ copied ? 'copied' : 'copy' }}</SecondaryButton>
                    </div>
                    <p class="text-xs text-gray-500" role="status" aria-live="polite">
                        {{ copied ? 'Link copied.' : (copyFailed ? "Couldn't copy -- select the link and copy it yourself." : '') }}
                    </p>
                </div>

                <PrimaryButton type="button" :disabled="creating" @click="create">
                    {{ creating ? 'creating…' : (linkUrl ? 'create another join link' : 'create join link') }}
                </PrimaryButton>
            </template>
        </div>
    </section>
</template>
