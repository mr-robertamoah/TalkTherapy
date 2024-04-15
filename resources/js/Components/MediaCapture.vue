<script setup>
import getBlobDuration from 'get-blob-duration';
import Modal from './Modal.vue';
import FormLoader from './FormLoader.vue';
import FilePreview from './FilePreview.vue';
import { ref, watch, watchEffect } from 'vue';

const emits = defineEmits(['sendFile', 'close'])

const props = defineProps({
    maxSize: { default: { video: 1 * 1024 * 1024, audio: 0.5 * 1024 * 1024 }, },
    maxTime: { default: { video: 120, audio: 120, }, },
    show: { default: false, },
    type: { default: '', },
    minTime: { default: { video: '00:00:01', audio: '00:00:01' }, },
})

const timer = ref(0)
const interval = ref(null)
const mediacanvas = ref(null)
const mediavideo = ref(null)
const checking = ref(false)
const mediaRecorder = ref(null)
const mediaChunk = ref([])
const showPopUp = ref(false)
const previewShow = ref(false)
const stream = ref(null)
const buttonType = ref('')
const audioState = ref('')
const recordState = ref('')
const popUpMessage = ref('')
const recorderType = ref('')
const file = ref(null)
const devices = ref([])
const width = ref(0)
const height = ref(0)
const camerasNumber = ref(0)
const constraints = ref({
    video:  {
        width: {
            ideal: 1280
        },
        height: {
            ideal: 720
        },
    }, audio: {
        echoCancellation: true
    }
})
const trimMediaData = ref({
    file: null,
    show: false
})
const fileRequirement = ref(
    { doesntHaveAppropriateSize: false, doesntHaveAppropriateDuration: false}
)

watchEffect(() => {
    if (props.type == '') {
        recordState.value = ''
        stopStream()
    } else {
        createConstraints()
        getEnumeratedDevices()
        getUserMedia()
    }
})
watch(() => recorderType.value, () => {
    if (recorderType.value) startRecording()
})
watch(() => audioState.value, () => {
    if (audioState.value == 'recording') startTimer()
})
watch(() => timer.value, () => {
    if (timer.value == props.maxTime[props.type]) stopTimer()
})
watchEffect(() => {
    if (!props.type) buttonType.value = ''
    if (!props.type && !stream.value) return

    if (file.value) return buttonType.value = 'send'
    buttonType.value = 'record'
})
watchEffect(() => {
    if (mediaRecorder.value && mediaRecorder.value?.state !== 'recording')
        mediaRecorder.value.start()
})

function startTimer() {
    timer.value = 0

    interval.value = setInterval(() => {
        timer.value += 1
    }, 1000)
}

function stopTimer() {

    clickedButton('record')
}

function createConstraints() {
    let c;
    if (props.type === 'image') {
        c = {video: {
            width: {
                ideal: 1280
            },
            height: {
                ideal: 720
            },
        }, audio: false}
    } else if (props.type === 'video') {
        c = {video:  {
            width: {
                ideal: 1280
            },
            height: {
                ideal: 720
            },
        }, audio: {
            echoCancellation: true
        }}
    } else if (props.type === 'audio') {
        c = {video: false, audio: {
            echoCancellation: true
        }}
    }

    constraints.value = c
}

function clickedButton(str) {
    if (timer.value) {
        timer.value = 0

        clearInterval(interval.value)
    }

    if (mediaRecorder.value?.state === 'recording') {
        recordState.value = 'stop recording'
        audioState.value = 'doneRecording'
        mediaRecorder.value.stop()
        return
    }
    
    if (str === 'record') {
        clickedRecord()
        return
    }

    clickedSend()
}

function clearTrimMediaData() {
    trimMediaData.value.file = null
    trimMediaData.value.show = false
}

function getTrimmedFile(f) {
    if (f === 'close') {
        clearTrimMediaData()
        restart()
        return
    }

    sendFile(file.value)
}
function restart() {
    resetData()
    getUserMedia()
}
function videoStreamReady(){
    if (props.type !== 'image') {
        return
    }

    if (mediavideo.value) {
        width.value = mediavideo.value.getClientRects()[0].width
        height.value = (mediavideo.value.videoHeight/mediavideo.value.videoWidth) * width.value

        mediavideo.value.setAttribute('width',width.value)
        mediavideo.value.setAttribute('height',height.value)
    }

    if (mediacanvas.value) {
        mediacanvas.value.setAttribute('width',width.value)
        mediacanvas.value.setAttribute('height',height.value)
    }
}
function clickedRemoveFile(){
    stopStream()
    getUserMedia()
    file.value = null
    if (props.type === 'audio') {
        audioState.value = 'waiting to record'
    }
}
function clickedPopupResponse(text) {
    // closePopUp()
    
    if (text == 'trim') {
        trimMediaData.value.file = file.value
        trimMediaData.value.show = true
        return
    }
    
    if (text == 'cancel') {
        restart()
        return
    }
}
function sendFile(f) {
    emits('sendFile', f)
    file.value = null
    closeModal()
}
async function clickedSend(){
    
    if (props.type === 'image') {
        sendFile(file.value)
        return
    }

    checking.value = true

    setFileRequirements(
        await getBlobDuration(file.value)
    )

    checking.value = false

    if (fileRequirement.value.doesntHaveAppropriateDuration) {
        displayPopUp('duration')
        return
    }

    // if (fileRequirement.value.doesntHaveAppropriateSize) {
    //     displayPopUp('size')
    //     return
    // }

    sendFile(file.value)
}

function setFileRequirements(duration) {

    resetFileRequirements()

    if (! ['video', 'audio'].includes(props.type)) {
        return
    }

    let doesntHaveAppropriateSize = false
    let doesntHaveAppropriateDuration = false
    if (! hasAppropriateSize(file.value, props.type)) {
        doesntHaveAppropriateSize = true
    }

    if (! hasAppropriateDuration(duration, props.type)) {
        doesntHaveAppropriateDuration = true
    }

    fileRequirement.value = {
        doesntHaveAppropriateDuration, doesntHaveAppropriateSize
    }
}

function resetFileRequirements() {
    fileRequirement.value = {
        doesntHaveAppropriateDuration: false, doesntHaveAppropriateSize: false
    }
}

function displayPopUp(t) {

    if (t === 'size') {
        popUpMessage.value = `the size of the ${props.type} should be less than ${props.maxSize[props.type]}. trim the file or cancel to delete`
    }
        
    if (t === 'duration') {
        popUpMessage.value = `the duration of the ${props.type} should be less than ${props.maxTime[props.type]}. trim the file or cancel to delete`
    }

    showPopUp.value = true
}

function clickedRecord(){
    if (props.type === 'image') {
        snap()
        buttonType.value = 'send'
        return
    }

    previewShow.value = false
    file.value = null

    if (props.type === 'audio') {
        audioState.value = 'recording'
    }

    recordState.value = 'recording'
    
    if (props.type == 'video') record('video/webm')
    if (props.type == 'audio') record('audio/mp3')
}

function clickedSwitch(){
    if (camerasNumber.value < devices.value.length -1) {
        camerasNumber.value =  camerasNumber.value + 1
    } else {
        camerasNumber.value = 0
    }
    
    if (props.type === 'audio') {
        audioState.value = 'recording'
    }

    constraints.value.video.deviceId = devices.value[camerasNumber.value].deviceId

    stopStream()
    getUserMedia()
}

function mainModalDisappear(){
    if (mediaRecorder.value?.state == 'recording') mediaRecorder.value.stop()
    if (stream.value) stopStream()
    if (file.value) file.value = null
    emits('close')
}

function resetData() {
    file.value = null
    buttonType.value = ''
    stream.value = null
}

function stopStream() {
    if(!stream.value) return
    stream.value.getTracks().forEach(track=>{
        track.stop()
    })

    if (mediavideo.value) mediavideo.value.srcObject = null
}

async function getUserMedia(){
    await navigator.mediaDevices.getUserMedia(constraints.value)
        .then(strm=>{

            stream.value = strm

            if (props.type === 'audio') {
                audioState.value = 'waiting to record'
                
                return
            }

            buttonType.value = 'record'

            if (videoIsStreaming()) {
                return
            }

            if (mediavideo.value) {
                mediavideo.value.srcObject =  strm
                mediavideo.value.play()
            }
        })
        .catch(err=>{
            
        })
}

function videoIsStreaming() {
    if (props.type === 'audio') {
        return false
    }

    if (mediavideo.value?.srcObject || 
        mediavideo.value?.src?.length) {
        return true
    }

    return false
}

function record(t){
    recorderType.value = t
}

function startRecording() {
    const recorder = new MediaRecorder(stream.value)

    recorder.onstart = ev=>{
        
    }

    let chunks = []
    recorder.ondataavailable = ev=>{
        
        chunks.push(ev.data)
    }

    recorder.onstop = (ev)=>{
        file.value = new Blob(chunks, {'type': recorderType.value})
        mediaChunk.value = []
        previewShow.value = true

        stopStream()

        buttonType.value = 'send'
    }

    mediaRecorder.value = recorder
}

function snap(){
    const context = mediacanvas.value.getContext('2d')

    mediacanvas.value.width = width.value
    mediacanvas.value.height = height.value

    context.drawImage(mediavideo.value,0,0,width.value,height.value)
    mediacanvas.value.toBlob(blob=>{
        file.value = blob
    },'image/png')
}

function getEnumeratedDevices(){
    navigator.mediaDevices.enumerateDevices()
        .then(d=>{
            d.forEach(device=>{
                // let option = document.createElement("option")
                
                if (device.kind.includes(props.type == 'image' ? 'video' : props.type)) {
                    devices.value = [...devices.value, device]
                }
                
                //add details to option and append to parent
            })
        })
}

function hasAppropriateSize(f, fileType) {

    return f.size <= props.maxSize[fileType]
}

function hasAppropriateDuration(duration, fileType) {
    return duration < props.maxTime[fileType]
}

function formatTimeUnit(unit) {
    return unit < 10 ? `0${unit}` : unit
}

function issueUploadedFileDangerAlert({ alertType, message }) {
    let fileType = getUploadedFileType(file.value)

    if (! fileType) {
        return
    }

    if (alertType === 'fileSize') {
        message = `the size of the ${fileType} should be less than ${props.maxSize[fileType]}`    
    }

    if (alertType === 'duration') {
        message = `the duration of the ${fileType} should be between ${props.maxTime[fileType]} && ${props.minTime[fileType]}`    
    }

    // if (issueDangerAlert) issueDangerAlert({message})
}
function getUploadedFileType(f) {

    if (f?.type.match('audio/*')?.length) {
        return 'audio'
    }

    if (f?.type.match('video/*')?.length) {
        return 'video'
    }

    return null
}

function closeModal() {
    mainModalDisappear()
}
</script>
    
<template>
    <Modal
        :show="show"
        @close="closeModal"
    >
        <div class='relative h-[60vh] flex justify-center items-center'>
                <div
                    v-if="timer && audioState == 'recording'"
                    class="absolute top-2 right-2 text-sm text-gray-600"
                >{{ timer }} seconds done . {{ maxTime[type] }} left</div>
            <div class="flex items-center justify-center relative w-[90%] mx-auto">
                <div v-if="(file ? false : ((type === 'image' || type === 'video') ? true : false))" class="video-container">
                    <video autoPlay ref="mediavideo"
                        @canPlay="videoStreamReady"
                        @resize="videoStreamReady"
                        muted
                    >your device does not support this</video>
                </div>
                <div class="audio-container" v-if="(file ? false : (type === 'audio' ? true : false))">
                    <div class="recorder">
                        <div v-if="audioState?.includes('wait')" class="text-gray-600 text-sm">{{ audioState }}</div>
                        <FormLoader :show="audioState === 'recording'" :text="audioState"/>
                    </div>
                </div>

                <FilePreview
                    :file="file"
                    @removeFile="clickedRemoveFile"
                    v-if="file"
                    class="w-full"
                ></FilePreview>
                <!-- <video v-if="(file && type == 'video')" autoPlay :src="URL.createObjectURL(file)">file not support</video> -->
                <canvas ref="mediacanvas" class="hidden"></canvas>
                <div v-if="(devices.length > 1 && !file && ['video', 'image'].includes(type))" class="absolute bg-gray-700 text-cyan-100 top-0 right-0 z-[1] p-1 cursor-pointer rounded"
                    @click="clickedSwitch"
                    title="change camera"
                >switch</div>
                
            </div>

            <!-- <pop-up
                :show="showPopUp"
                :responses="['trim', 'cancel']"
                default="cancel"
                :message="popUpMessage"
                @clickedResponse="clickedPopupResponse"
                @closePopUp="closePopUp"
            ></pop-up> -->

            <div v-if="checking" class="z-50 text-white absolute bottom-0 w-full text-center">
                checking for duration...
            </div>
            <div class="w-full absolute bottom-1">
                <div class=''></div>
                <div
                    v-if="(!file && buttonType == 'record')"
                    :class="`${recordState === 'recording' ? 'bg-gray-400' : 'bg-gray-700'} flex justify-center items-center transition duration-75 relative w-[40px] h-[40px] p-1 mx-auto cursor-pointer rounded-full`"
                    @click="() => clickedButton('record')" 
                    title=''
                >
                    <div 
                        :class="`${recordState === 'recording' ? 'bg-red-400' : 'bg-gray-400'} transition duration-75 w-full h-full rounded-full`"></div>    
                </div>
                <div 
                    v-if="(file && !checking && buttonType == 'send')"
                    class="w-[40px] h-[40px] rounded-full flex justify-center items-center text-xl cursor-pointer mx-auto bg-green-700 text-green-300" 
                    @click="() => clickedButton('send')"
                >
                    <div>+</div>
                </div>
            </div>
        </div>

    </Modal>
</template>