<script setup>
// SCRUM-198/TT-2.2c: a counsellor's own private notes on the currently selected session --
// fetched on demand (never broadcast, never part of the live chat/messages stream), and never
// rendered at all unless the viewer is a counsellor on this session (see the isCounsellor guard
// where this component is used). Read-only once the backend reports isEditable: false for a
// note (its session has left the edit grace window) -- see SessionNoteResource.
import axios from 'axios';
import { ref, watch } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import TextBox from './TextBox.vue';
import InputError from './InputError.vue';

const props = defineProps({
    session: {
        required: true,
    },
})

const expanded = ref(false)
const loading = ref(false)
const error = ref('')
const notes = ref([])
const newContent = ref('')
const editingId = ref(null)
const editingContent = ref('')

function fetchNotes() {
    if (!props.session?.id) return

    loading.value = true
    error.value = ''

    axios
        .get(route('api.session.notes.index', { sessionId: props.session.id }))
        .then((res) => notes.value = res.data.notes)
        .catch(() => error.value = 'Could not load your notes for this session.')
        .finally(() => loading.value = false)
}

function toggleExpanded() {
    expanded.value = !expanded.value

    if (expanded.value) fetchNotes()
}

watch(() => props.session?.id, () => {
    notes.value = []
    cancelEdit()
    if (expanded.value) fetchNotes()
})

function createNote() {
    if (!newContent.value.trim() || loading.value) return

    loading.value = true
    error.value = ''

    axios
        .post(route('api.session.notes.store', { sessionId: props.session.id }), { content: newContent.value })
        .then((res) => {
            notes.value = [res.data.note, ...notes.value]
            newContent.value = ''
        })
        .catch((err) => error.value = err.response?.data?.message || 'Could not save your note.')
        .finally(() => loading.value = false)
}

function startEdit(note) {
    editingId.value = note.id
    editingContent.value = note.content
}

function cancelEdit() {
    editingId.value = null
    editingContent.value = ''
}

function saveEdit(note) {
    if (!editingContent.value.trim() || loading.value) return

    loading.value = true
    error.value = ''

    axios
        .patch(route('api.session.notes.update', { noteId: note.id }), { content: editingContent.value })
        .then((res) => {
            const idx = notes.value.findIndex((n) => n.id === note.id)
            if (idx !== -1) notes.value[idx] = res.data.note
            cancelEdit()
        })
        .catch((err) => error.value = err.response?.data?.message || 'Could not update your note.')
        .finally(() => loading.value = false)
}

function deleteNote(note) {
    if (loading.value) return

    loading.value = true
    error.value = ''

    axios
        .delete(route('api.session.notes.destroy', { noteId: note.id }))
        .then(() => notes.value = notes.value.filter((n) => n.id !== note.id))
        .catch((err) => error.value = err.response?.data?.message || 'Could not delete your note.')
        .finally(() => loading.value = false)
}
</script>

<template>
    <div class="w-full rounded-lg bg-stone-100 p-2 mb-2">
        <button
            type="button"
            @click="toggleExpanded"
            class="w-full flex justify-between items-center text-sm font-bold text-gray-700 capitalize"
        >
            <span>session notes<span v-if="notes.length" class="ml-1 font-normal text-gray-500">({{ notes.length }})</span></span>
            <span class="text-xs">{{ expanded ? 'hide' : 'show' }}</span>
        </button>

        <div v-if="expanded" class="mt-2">
            <div class="text-xs text-gray-500 mb-2">Only you can see these notes.</div>

            <div v-if="loading && !notes.length" class="text-xs text-gray-500">loading...</div>

            <div v-for="note in notes" :key="note.id" class="bg-white rounded p-2 mb-2 text-sm">
                <template v-if="editingId === note.id">
                    <TextBox rows="3" class="w-full" v-model="editingContent" />
                    <div class="flex justify-end space-x-2 mt-1">
                        <button type="button" class="text-xs text-gray-500" @click="cancelEdit">cancel</button>
                        <PrimaryButton class="text-xs" :disabled="loading" @click="() => saveEdit(note)">save</PrimaryButton>
                    </div>
                </template>
                <template v-else>
                    <div class="whitespace-pre-wrap text-gray-800">{{ note.content }}</div>
                    <div class="flex justify-between items-center mt-1">
                        <div class="text-xs text-gray-400">{{ new Date(note.createdAt).toLocaleString() }}</div>
                        <div v-if="note.isEditable" class="flex space-x-2">
                            <button type="button" class="text-xs text-gray-500 underline" @click="() => startEdit(note)">edit</button>
                            <button type="button" class="text-xs text-red-600 underline" @click="() => deleteNote(note)">delete</button>
                        </div>
                        <div v-else class="text-xs text-gray-400 italic">locked</div>
                    </div>
                </template>
            </div>

            <div v-if="!loading && !notes.length" class="text-xs text-gray-500 mb-2">no notes yet</div>

            <TextBox
                rows="2"
                class="w-full"
                placeholder="add a private note..."
                v-model="newContent"
            />
            <div class="flex justify-end mt-1">
                <PrimaryButton class="text-xs" :disabled="loading || !newContent.trim()" @click="createNote">add note</PrimaryButton>
            </div>

            <InputError class="mt-1" :message="error" />
        </div>
    </div>
</template>
