<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import { ref, watchEffect } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import TherapyComponent from "@/Components/TherapyComponent.vue";
import Alert from "@/Components/Alert.vue";
import useTherapyState from "@/Composables/useTherapyState";
import useAlert from "@/Composables/useAlert";
import useAuth from "@/Composables/useAuth";
import useUtilities from "@/Composables/useUtilities";

const { goToLogin } = useAuth();
const { getReadableStatus } = useUtilities();
const { alertData, clearAlertData, setFailedAlertData } = useAlert();

const props = defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: "individual" },
});

// Re-invoking useTherapyState here (rather than sharing UnifiedTherapy.vue's instance) mirrors
// this codebase's established pattern of independently re-joining the presence channel per
// mounted page -- see TherapyComponent.vue's own `.private('sessions.{id}')` join alongside it.
const therapyRef = ref(props.therapy);
const {
  activeSession,
  listening,
  timer,
  computedTherapy,
  computedIsUser,
  computedIsCounsellor,
  computedIsParticipant,
  computedIsInSession,
  startTimer,
  listenToTherapy,
  updateSessionOrTopic,
  deleteSessionOrTopic,
  addSessionOrTopic,
} = useTherapyState(therapyRef, props.therapyType);

const newSession = ref(null);
const sessionActionRunning = ref("");
const currentUpdatedSessionOrTopic = ref(null);
const currentDeletedSessionOrTopic = ref(null);

watchEffect(() => {
  if (props.therapy?.activeSession?.id) {
    activeSession.value = props.therapy.activeSession;
    startTimer();
  }
});

watchEffect(() => {
  let currentTherapy = props.therapy?.data ? props.therapy.data : props.therapy;
  let user = usePage().props.auth.user;

  if (!user?.id || !currentTherapy?.id) return;

  if (listening.value) return;
  listening.value = true;
  listenToTherapy(currentTherapy);
});

function handleSessionTopicUpdate(item) {
  updateSessionOrTopic(item);
  currentUpdatedSessionOrTopic.value = item;
}

function handleSessionTopicDelete(item) {
  deleteSessionOrTopic(item);
  currentDeletedSessionOrTopic.value = item;
}

async function clickedAbandonSession() {
  if (!activeSession.value?.id) return;

  sessionActionRunning.value = "abandoning session";
  await axios
    .post(route("api.sessions.abandon", activeSession.value.id))
    .then((res) => {
      updateSessionOrTopic(res.data.session);
    })
    .catch((err) => {
      console.log(err);
      goToLogin(err);
      setFailedAlertData({
        message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
      });
    })
    .finally(() => {
      sessionActionRunning.value = "";
    });
}

async function clickedStartSession() {
  if (!activeSession.value?.id) return;

  sessionActionRunning.value = "starting session";
  await axios
    .post(route("api.sessions.in_session", activeSession.value.id))
    .then((res) => {
      updateSessionOrTopic(res.data.session);
    })
    .catch((err) => {
      console.log(err);
      goToLogin(err);
      setFailedAlertData({
        message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
        timer: 4000,
      });
    })
    .finally(() => {
      sessionActionRunning.value = "";
    });
}

async function clickedEndSession() {
  if (!activeSession.value?.id) return;

  sessionActionRunning.value = "ending session";
  await axios
    .post(route("api.sessions.end", activeSession.value.id))
    .then((res) => {
      updateSessionOrTopic(res.data.session);
    })
    .catch((err) => {
      console.log(err);
      goToLogin(err);
      setFailedAlertData({
        message: `Something unfortunate happened while ${sessionActionRunning.value}. Try again shortly.`,
        timer: 4000,
      });
    })
    .finally(() => {
      sessionActionRunning.value = "";
    });
}

function clickedSessionAction(action) {
  if (action == "start") return clickedStartSession();
  if (action == "end") return clickedEndSession();
  clickedAbandonSession();
}
</script>

<template>
  <Head
    :title="`${therapyType === 'group' ? 'Group ' : ''}Therapy Chat${computedTherapy ? ` - ${computedTherapy.name}` : ''}`"
  />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center w-full">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight capitalize">
          {{ computedTherapy?.name }} chat
        </h2>
        <Link
          :href="
            route(therapyType === 'individual' ? 'therapies.get' : 'group.therapies.get', {
              [therapyType === 'individual' ? 'therapyId' : 'groupTherapyId']:
                computedTherapy?.id,
            })
          "
          class="text-sm text-gray-600 hover:underline"
        >
          back to therapy
        </Link>
      </div>
    </template>

    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 py-6 relative">
      <div
        v-if="activeSession"
        class="text-center text-sm text-gray-600 mb-2 capitalize"
      >
        {{ activeSession.name }}
        <span class="lowercase font-normal">
          . {{ getReadableStatus(activeSession.status) }}
        </span>
      </div>

      <TherapyComponent
        :therapy="computedTherapy"
        :therapy-type="therapyType"
        :new-session="newSession"
        :active-session="activeSession"
        :deleted-session-or-topic="currentDeletedSessionOrTopic"
        :updated-session-or-topic="currentUpdatedSessionOrTopic"
        :is-participant="computedIsParticipant"
        :is-user="computedIsUser"
        :is-counsellor="computedIsCounsellor"
        :show-sessions="!activeSession"
        :can-start="!!activeSession && timer.beforeEnd > 0"
        :can-end="!!activeSession && computedIsInSession && timer.beforeEnd < 0"
        :can-abandon="!!activeSession && timer.beforeEnd > 0 && computedIsInSession"
        @update-active-session="(data) => (activeSession = data)"
        @created="addSessionOrTopic"
        @updated="handleSessionTopicUpdate"
        @done-updating="() => (currentUpdatedSessionOrTopic = null)"
        @done-deleting="() => (currentDeletedSessionOrTopic = null)"
        @deleted="handleSessionTopicDelete"
        @session-action="clickedSessionAction"
      />
    </div>

    <Alert
      :show="alertData.show"
      :type="alertData.type"
      :message="alertData.message"
      :time="alertData.time"
      @close="clearAlertData"
    />
  </AuthenticatedLayout>
</template>
