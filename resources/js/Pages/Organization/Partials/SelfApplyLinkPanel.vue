<script setup>
// SCRUM-214/415: an organization's admins create join (self-apply) links to send people, see every link
// made for the organization (any admin's), and revoke one still unused -- say, one sent to the wrong
// person. Each link works once, for one person (it is marked used when someone requests to join with it),
// so they create one per person. Opening a link shows the organization and a "Request to join" button
// (Links/OrganizationJoin); confirming files the request, which lands in the request queue below. The
// server refuses a link that couldn't work (EnsureUserCanCreateOrganizationSelfApplyLinkAction); this card
// says why up front instead.
import { computed, onMounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import CopyLinkField from '@/Components/CopyLinkField.vue'
import useAppLink from '@/Composables/useAppLink'
import { refusalMessage } from '@/Composables/useOfferToHelp'

const props = defineProps({
    organization: { type: Object, required: true },
})

const STATUS_LABELS = { ACTIVE: 'Active', USED: 'Used', REVOKED: 'Revoked' }

const { createLink } = useAppLink()
const page = usePage()

const creating = ref(false)
const newLinkId = ref(null)

const links = ref([])
const nextPageUrl = ref(null)
const loading = ref(false)
const loadError = ref('')
const confirmingId = ref(null)
const revokingId = ref(null)
const rowError = ref({})

const blockedReason = computed(() => {
    if (!props.organization.selfApplyEnabled) {
        return 'Turn on "Allow members to self-apply" (edit organization) to create join links.'
    }
    if (!props.organization.isVerified) {
        return 'Join links can be created once your organization is verified.'
    }
    return ''
})

const urlFor = (uuid) => `${window.location.origin}${route('links.get', { uuid }, false)}`

// From the first page (after creating one, too), or the next page added below what's shown.
async function loadLinks(append = false) {
    const url = append ? nextPageUrl.value : route('organizations.join_links.index', { organizationId: props.organization.id })
    if (!url) return
    loading.value = true
    loadError.value = ''
    await axios.get(url)
        .then((res) => {
            links.value = append ? [...links.value, ...res.data.data] : res.data.data
            nextPageUrl.value = res.data.links?.next ?? null
        })
        .catch((err) => { loadError.value = refusalMessage(err, "Couldn't load your join links.") })
        .finally(() => { loading.value = false })
}

async function create() {
    creating.value = true
    const link = await createLink({
        type: 'ORGANIZATION_SELF_APPLY',
        addedbyType: 'User',
        addedbyId: page.props.auth.user.id,
        forType: 'Organization',
        forId: props.organization.id,
    })
    creating.value = false
    if (!link) return
    newLinkId.value = link.id
    await loadLinks()
}

async function revoke(link) {
    revokingId.value = link.id
    rowError.value = { ...rowError.value, [link.id]: '' }
    await axios.post(route('organizations.join_links.revoke', { organizationId: props.organization.id, linkId: link.id }))
        .then((res) => {
            links.value = links.value.map((item) => item.id === link.id ? res.data.link : item)
            confirmingId.value = null
        })
        .catch((err) => {
            rowError.value = { ...rowError.value, [link.id]: refusalMessage(err, "Couldn't revoke this link.") }
        })
        .finally(() => { revokingId.value = null })
}

onMounted(() => {
    if (!blockedReason.value) loadLinks()
})
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
                    create a new one for each person. They open it and confirm, and their request appears in your request
                    queue for you to accept.
                </p>

                <PrimaryButton type="button" :disabled="creating" @click="create">
                    {{ creating ? 'creating…' : (newLinkId ? 'create another join link' : 'create join link') }}
                </PrimaryButton>

                <div class="pt-2">
                    <h4 class="text-sm font-semibold text-gray-900">Links sent</h4>
                    <p v-if="loadError" class="mt-2 text-sm text-red-700">{{ loadError }}</p>
                    <p v-else-if="!loading && !links.length" class="mt-2 text-sm text-gray-500">No join links yet.</p>

                    <ul v-else class="mt-2 divide-y divide-gray-100 border border-gray-200 rounded-lg">
                        <li v-for="link in links" :key="link.id" class="p-3 text-sm space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="link.status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'"
                                    >{{ STATUS_LABELS[link.status] }}</span>
                                    <span v-if="link.id === newLinkId" class="ml-1 inline-block rounded-full bg-blue-100 text-blue-800 px-2 py-0.5 text-xs font-medium">New</span>
                                    <span class="ml-2 text-gray-600">Created {{ link.createdAt }}<template v-if="link.createdBy"> by {{ link.createdBy }}</template></span>
                                </div>
                                <template v-if="link.status === 'ACTIVE'">
                                    <div v-if="confirmingId === link.id" class="flex items-center gap-2">
                                        <span class="text-gray-700">Revoke this link?</span>
                                        <DangerButton type="button" :disabled="revokingId === link.id" @click="revoke(link)">
                                            {{ revokingId === link.id ? 'revoking…' : 'revoke' }}
                                        </DangerButton>
                                        <SecondaryButton type="button" @click="confirmingId = null">keep</SecondaryButton>
                                    </div>
                                    <SecondaryButton v-else type="button" @click="confirmingId = link.id">revoke</SecondaryButton>
                                </template>
                            </div>
                            <CopyLinkField
                                v-if="link.status === 'ACTIVE' && link.uuid"
                                :id="`join-link-${link.id}`"
                                :url="urlFor(link.uuid)"
                                label="Join link"
                            />
                            <p v-if="rowError[link.id]" class="text-sm text-red-700" role="alert">{{ rowError[link.id] }}</p>
                        </li>
                    </ul>

                    <div v-if="nextPageUrl" class="text-center mt-3">
                        <button type="button" :disabled="loading" class="text-sm text-blue-600 hover:underline" @click="loadLinks(true)">
                            {{ loading ? 'loading...' : 'load more' }}
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </section>
</template>
