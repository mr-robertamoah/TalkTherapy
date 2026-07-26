<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Alert from "@/Components/Alert.vue";
import MessageBadge from "@/Components/MessageBadge.vue";
import MediaCapture from "@/Components/MediaCapture.vue";
import TextBox from "@/Components/TextBox.vue";
import FilePreview from "@/Components/FilePreview.vue";
import PaperplaneIcon from "@/Icons/PaperplaneIcon.vue";
import PaperclipIcon from "@/Icons/PaperclipIcon.vue";
import CameraIcon from "@/Icons/CameraIcon.vue";
import MicrophoneIcon from "@/Icons/MicrophoneIcon.vue";
import FileIcon from "@/Icons/FileIcon.vue";
import useMessage from "@/Composables/useMessage";
import useAlert from "@/Composables/useAlert";
import useAuth from "@/Composables/useAuth";
import useEnums from "@/Composables/useEnums";
import useConnectionStatus from "@/Composables/useConnectionStatus";

const { DiscussionStatusEnum } = useEnums();
const { goToLogin } = useAuth();
const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert();
const { bindConnectionStatus, unbindConnectionStatus } = useConnectionStatus({
  onDisconnected: () =>
    setFailedAlertData({
      message: "Connection lost. Trying to reconnect...",
      time: 6000,
    }),
  onReconnected: () =>
    setSuccessAlertData({
      message: "Connection restored.",
      time: 3000,
    }),
});
const {
  message,
  files,
  computedHasMessage,
  scrollToBottom,
  showAttachmentIcons,
  messageFilesInput,
  messageArea,
  changeFile,
  resetMessage,
  clickedIcon,
  mediaCaptureData,
  closeMediaCapture,
  removeUploadFile,
  selectForUpdate,
  selectAsReply,
  removeReply,
  sendMessage,
} = useMessage();

const props = defineProps({
  discussion: {
    default: null,
  },
});

const getting = ref({ show: false, type: "" });
const discussionMessages = ref({ data: [], page: 1 });
const discussionCounsellors = ref({ data: [], page: 1 });
const listening = ref(false);

onMounted(() => {
  getDiscussionMessages();
  getDiscussionCounsellors();
  listenToMessages();
  bindConnectionStatus();
});

onBeforeUnmount(() => {
  if (listening.value) Echo.leave(`discussions.${props.discussion.id}`);
  unbindConnectionStatus();
});

const computedUser = computed(() => usePage().props.auth.user);

const computedBackHref = computed(() => {
  if (!props.discussion?.forId) return route("home");

  return props.discussion.forType === "Therapy"
    ? route("therapies.get", { therapyId: props.discussion.forId })
    : route("group.therapies.get", { groupTherapyId: props.discussion.forId });
});

const computedCanSendMessage = computed(() => {
  if (props.discussion?.status !== DiscussionStatusEnum.inSession) return false;

  if (!computedUser.value?.id) return false;

  if (props.discussion.addedby.userId == computedUser.value?.id) return true;

  if (
    discussionCounsellors.value.data.filter((c) => c.userId == computedUser.value?.id)
      .length
  )
    return true;

  return false;
});

const computedCounsellorAccount = computed(() => {
  let counsellor = null;
  let idx = -1;

  if (props.discussion.addedby.userId == computedUser.value?.id)
    counsellor = props.discussion.addedby;
  else
    idx = discussionCounsellors.value.data.findIndex(
      (c) => c.userId == computedUser.value?.id
    );

  if (idx > -1) counsellor = discussionCounsellors.value.data[idx];

  if (!counsellor) return null;

  return {
    type: "Counsellor",
    id: counsellor.id,
    userId: counsellor.userId,
    isCounsellor: true,
    avatar: counsellor.avatar,
  };
});

function listenToMessages() {
  if (listening.value) return;

  listening.value = true;
  let userId = computedUser.value?.id;

  // Independent join from DiscussionModal.vue's own presence join for this same channel --
  // this page has its own lifecycle, so it listens for `.message.created` on its own rather
  // than trying to share the connection with the modal.
  Echo.join(`discussions.${props.discussion.id}`).listen(".message.created", (data) => {
    console.log(data, "message created");
    if (data.message?.fromUserId == userId) return;

    addNewMessage(data.message);
  });
}

async function getDiscussionMessages() {
  if (getting.value.show) return;

  getting.value.show = true;

  await axios
    .get(
      route("api.discussion.messages.get", {
        discussionId: props.discussion.id,
        page: discussionMessages.value?.page,
      })
    )
    .then((res) => {
      console.log(res);

      if (discussionMessages.value.page == 1) discussionMessages.value.data = [];

      discussionMessages.value.data = [
        ...discussionMessages.value.data,
        ...res.data.data,
      ];

      if (discussionMessages.value.page == 1 && discussionMessages.value.data.length)
        discussionMessages.value.data[0].scroll = true;
      else if (discussionMessages.value.data.length > 10)
        discussionMessages.value.data[11].scroll = true;

      updateDiscussionMessagesPage(res);
    })
    .catch((err) => {
      console.log(err);

      if (err?.response?.message) {
        setFailedAlertData({
          message: err?.response?.message,
          time: 5000,
        });
        return;
      }

      setFailedAlertData({
        message: `Failed to get messages for the discussion. Please try again shortly.`,
        time: 5000,
      });

      goToLogin(err);
    })
    .finally(() => {
      getting.value.show = false;
    });
}

async function getDiscussionCounsellors() {
  if (!discussionCounsellors.value.page) return;

  await axios
    .get(
      route(`api.discussions.counsellors`, {
        page: discussionCounsellors.value.page,
        discussionId: props.discussion.id,
      })
    )
    .then((res) => {
      console.log(res);

      if (discussionCounsellors.value.page == 1) discussionCounsellors.value.data = [];

      discussionCounsellors.value.data = [
        ...discussionCounsellors.value.data,
        ...res.data.data,
      ];

      updatePage(res, discussionCounsellors);
    })
    .catch((err) => {
      console.log(err);
      goToLogin(err);
    });
}

function updatePage(res, data) {
  if (res.data.links.next) data.value.page = data.value.page + 1;
  else data.value.page = 0;
}

function updateDiscussionMessagesPage(res) {
  if (res.data.links.next) discussionMessages.value.page += 1;
  else discussionMessages.value.page = 0;
}

function replaceOldMessage(data) {
  discussionMessages.value.data.splice(
    discussionMessages.value.data.findIndex((d) => d.id == data.id),
    1,
    { ...data }
  );
}

function replaceFirstMessage(data) {
  discussionMessages.value.data.splice(0, 1, { ...data });
}

function addNewMessage(newMessage) {
  if (!newMessage) return;

  discussionMessages.value.data = [
    { ...newMessage },
    ...discussionMessages.value.data.filter((m) => newMessage.id !== m.id),
  ];

  scrollToBottom();
}
</script>

<template>
  <Head :title="`${discussion?.name ?? 'Discussion'} - Chat`" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center w-full">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight capitalize">
          {{ discussion?.name }} chat
        </h2>
        <Link :href="computedBackHref" class="text-sm text-gray-600 hover:underline">
          back to therapy
        </Link>
      </div>
    </template>

    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 py-6 relative">
      <div class="rounded-lg min-h-[400px] bg-stone-200 h-full w-full shrink mb-2">
        <div
          class="h-[60vh] p-2 overflow-hidden overflow-y-auto space-y-2 flex items-center flex-col"
          :class="{ 'justify-end': discussionMessages.data?.length <= 3 }"
          id="message_area"
          ref="messageArea"
        >
          <div
            v-if="
              !getting.show && !discussionMessages.page && discussionMessages.data.length
            "
            class="w-fit mx-auto my-2 text-sm text-gray-600"
          >
            no more messages
          </div>
          <div v-if="!getting.show && discussionMessages.page > 1" class="w-full">
            <div
              @click="getDiscussionMessages"
              class="w-fit mx-auto p-4 text-lg text-gray-600 cursor-pointer"
            >
              ...
            </div>
          </div>
          <template v-if="discussionMessages.data.length">
            <MessageBadge
              v-for="(msg, idx) in discussionMessages.data.toReversed()"
              :key="idx"
              :idx="idx"
              :msg="msg"
              :item="discussion"
              :allow-actions="computedCanSendMessage"
              :current-reply="message.replying?.id && message.replying?.id == msg.id"
              @on-success="(data) => replaceFirstMessage(data)"
              @on-update="(data) => replaceOldMessage(data)"
              @select-as-reply="(data) => selectAsReply(data, idx)"
              @select-for-update="(data) => selectForUpdate(data, idx)"
            />
          </template>
          <div
            v-else
            class="text-gray-600 text-sm font-bold w-full h-[300px] flex justify-center items-center my-auto"
          >
            no discussion messages
          </div>
        </div>
      </div>

      <div
        class="rounded-lg bg-stone-100 w-full p-2"
        v-if="computedCanSendMessage && !getting.show"
      >
        <div v-if="message.replying" class="relative">
          <MessageBadge
            :msg="message.replying"
            :allow-actions="false"
            :allow-details="false"
            :reply="true"
          />
          <div
            @click="removeReply"
            class="-top-2 absolute bg-blue-600 cursor-pointer flex h-5 items-center justify-center p-2 rounded-full text-blue-200 text-xs w-5 z-[1]"
          >
            <div>X</div>
          </div>
        </div>
        <div v-if="message.id" class="flex justify-between items-center">
          <div
            class="text-gray-300 bg-gray-800 rounded-full p-2 cursor-pointer w-4 flex justify-center items-center text-xs h-4"
            @click="resetMessage"
          >
            x
          </div>
          <div class="text-xs text-gray-600 my-2 text-center">updating message</div>
        </div>
        <div class="w-full mx-auto min-h-10 flex justify-center items-center gap-2">
          <TextBox rows="1" class="w-full shrink" v-model="message.content" />
          <div class="flex justify-end gap-2 items-start">
            <PaperplaneIcon
              title="send message"
              v-if="computedHasMessage"
              @click="
                () =>
                  sendMessage({
                    item: discussion,
                    itemType: 'Discussion',
                    addNewMessage,
                    from: computedCounsellorAccount,
                    action: replaceOldMessage,
                  })
              "
              class="w-8 cursor-pointer p-2 h-8 rotate-45 shrink-0"
            />
            <PaperclipIcon
              class="w-8 cursor-pointer p-2 h-8 shrink-0"
              @click="() => (showAttachmentIcons = true)"
            />
          </div>
        </div>
        <div
          class="w-full max-h-[100px] p-2 flex justify-start overflow-hidden overflow-x-auto items-center space-x-2"
          v-if="files?.length"
        >
          <FilePreview
            v-for="(file, idx) in files"
            :key="idx"
            :file="file"
            class="h-[90px] w-[90px]"
            @remove-file="() => removeUploadFile(file, idx)"
          />
        </div>
      </div>
      <div
        v-else-if="!getting.show"
        class="text-center text-sm text-gray-600 py-4"
      >
        You cannot send messages in this discussion right now.
      </div>

      <div
        @click.self="() => (showAttachmentIcons = false)"
        :class="[
          showAttachmentIcons ? 'opacity-100 visible z-[1]' : 'opacity-0 invisible -z-[1]',
        ]"
        class="w-full top-0 absolute transition-all duration-100 right-0 h-full bg-gray-900/40 backdrop-blur-[2px] flex justify-center items-center"
      >
        <div
          class="relative w-[85%] max-w-sm bg-white shadow-2xl rounded-2xl p-6 flex justify-center items-start gap-6"
        >
          <button
            type="button"
            title="close"
            @click="() => (showAttachmentIcons = false)"
            class="absolute top-2 right-2 w-6 h-6 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100"
          >&times;</button>
          <div class="flex flex-col items-center gap-2 cursor-pointer group" @click="() => clickedIcon('camera')">
            <div class="w-12 h-12 rounded-full bg-gray-100 group-hover:bg-gray-200 flex items-center justify-center transition-colors">
              <CameraIcon title="take a picture" class="w-6 h-6" />
            </div>
            <span class="text-xs text-gray-500">Camera</span>
          </div>
          <div class="flex flex-col items-center gap-2 cursor-pointer group" @click="() => clickedIcon('microphone')">
            <div class="w-12 h-12 rounded-full bg-gray-100 group-hover:bg-gray-200 flex items-center justify-center transition-colors">
              <MicrophoneIcon title="record your voice note" class="w-6 h-6" />
            </div>
            <span class="text-xs text-gray-500">Voice</span>
          </div>
          <div class="flex flex-col items-center gap-2 cursor-pointer group" @click="() => clickedIcon('file')">
            <div class="w-12 h-12 rounded-full bg-gray-100 group-hover:bg-gray-200 flex items-center justify-center transition-colors">
              <FileIcon title="upload an image or pdf file" class="w-6 h-6" />
            </div>
            <span class="text-xs text-gray-500">File</span>
          </div>
        </div>
      </div>

      <input
        type="file"
        name="messageFiles"
        ref="messageFilesInput"
        @change="changeFile"
        class="hidden"
        id="messageFiles"
        multiple
        accept="image/*"
      />
    </div>

    <Alert
      :show="alertData.show"
      :type="alertData.type"
      :message="alertData.message"
      :time="alertData.time"
      @close="clearAlertData"
    />

    <MediaCapture
      :show="mediaCaptureData.show"
      :type="mediaCaptureData.type"
      @close="closeMediaCapture"
      @send-file="
        (file) => {
          files = [file, ...files];
        }
      "
    />
  </AuthenticatedLayout>
</template>
