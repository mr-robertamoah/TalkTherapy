<script setup>
import MiniTherapyComponent from '@/Components/MiniTherapyComponent.vue';
import StarredCounsellorComponent from '@/Components/StarredCounsellorComponent.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeMount, provide, ref, watch } from 'vue';
import WriteableText from '@/Components/WriteableText.vue';
import StyledA from '@/Components/StyledA.vue';
import Avatar from '@/Components/Avatar.vue';
import useModal from '@/Composables/useModal';
import CreateTestimonialModal from '@/Components/CreateTestimonialModal.vue';
import ContactUsModal from '../Components/ContactUsModal.vue';
import PrimaryButton from '../Components/PrimaryButton.vue';

const { closeModal, showModal, modalData } = useModal()
const user = usePage().props.auth.user

const props = defineProps({
    testimonials: {
        default: []
    }
})

watch(() => user?.id, () => {
    if (user?.id)
        greetings.value += ` ${user.name}`
})

const greetings = ref("Hello")
const robertAvatar = `/storage/others/robertamoah.png`

</script>

<template>
    <Head title="About" />

    <AuthenticatedLayout>
        <div class="pt-6 pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg min-h-[80vh] p-4">
                    <div class="mt-32 ml-6 text-5xl font-bold w-fit bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent uppercase">
                        {{ greetings }}
                    </div>

                    <div class="mt-20 mb-5 flex flex-col justify-center items-start w-[90%] sm:w-[80%] mx-auto">
                        <div class="text-2xl">Welcome to <span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">#TalkTherapy</span> app.</div>
                        <WriteableText
                            :message="'Scroll down to know a bit more about what I am.'"
                            class="text-gray-600 text-lg mt-4"
                        />
                    </div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="overflow-hidden min-h-[20vh] p-4 flex flex-col justify-center items-start mx-auto w-[90%] sm:w-[80%]">
                    <div class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold text-2xl">Mission</div>

                    <div class="text-gray-600 text-lg mt-4 font-medium">A holistic (emotional, psycological and social) wellbeing is achievable.</div>

                    <p class="text-gray-600 text-base mt-8 text-justify">
                        We seek to do this by connecting people with verified, professional counsellors.
                        These counsellors are verified based on their ties with recognisable governmental, professional or religious institutions.
                    </p>
                    <p class="text-gray-600 text-base mt-2 text-justify">
                        This connection between counsellors and users on this platform creates a relationship that helps individuals to either get out of existing mental health issues or prevent them all together.
                    </p>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg min-h-[80vh] p-4">
                    <div class="mt-4 ml-2 text-2xl font-bold w-fit bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent uppercase">
                        What we are to you
                    </div>

                    <div class="mt-10 mb-5 flex flex-col justify-center items-start w-[90%] sm:w-[80%] mx-auto">
                        <div class="text-lg">To <span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">User</span></div>
                        <div class="text-gray-600 text-base mt-4 text-justify">
                            <p>
                                There are countless issues facing us every second of every day. We deal with these issues differently. How will deal with the issues tend to either make us better or worse.
                                We do not have to deal with these issues alone. You can decide to '<strong>TalkTherapy</strong>' with a counsellor so you can navigate your emotional, psycological and social life with some tact and confidence.
                            </p>
                            <p>
                                There are times you do not know what is really wrong, you just do not know feel right. Yes, you can '<strong>TalkTherapy</strong>'.
                            </p>
                            <p>
                                On this app, the power is in you hands. You decide whether you stay anonymous or not, whether you pay or not, as well as the amount you choose to pay.
                            </p>
                            <p class="font-bold">Not everyone with an advice to give can give you a helpful advice.</p>
                        </div>
                    </div>

                    <div class="mt-10 mb-5 flex flex-col justify-center items-start w-[90%] sm:w-[80%] mx-auto">
                        <div class="text-lg">To <span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">Counsellor</span></div>
                        <div class="text-gray-600 text-base mt-4 text-justify">
                            We help counsellors express themselves through creating <strong>posts</strong> on this platform which is created solely to promote mental health issues.
                            Counsellors get to send or receive requests to assist individuals with <strong>therapies</strong> that have been created. Therapies can free or paid (payment can be suggested for the entire therapy or for individual sessions).
                            Even with free sessions, you can still receive donations from the user you are assisting or from other users on the platform.
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="overflow-hidden min-h-[20vh] p-4 flex flex-col justify-center items-start mx-auto w-[90%] sm:w-[80%]">
                    <div class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold text-2xl">Founder</div>

                    <div class="text-gray-600 text-lg mt-4 font-medium relative">
                        <div class="flex justify-start items-center space-x-3">
                            <Avatar :src="robertAvatar ?? ''" class="" :alt="'counsellor avatar'"/>
                            <div class="font-bold text-base ">Robert Amoah</div>
                        </div>
                        <div class="flex justify-start items-start space-x-3 mt-4">

                            <!-- <StyledA
                                :href="'https://mrrobertamoah.me'"
                                :text="'Website'"
                            /> -->
                            <StyledA
                                :href="'https://www.x.com/Mr_robertamoah'"
                                :text="'X'"
                            />
                            <StyledA
                                :href="'https://www.facebook.com/share/Mj7V4hrem4NfVPg4'"
                                :text="'Facebook'"
                            />
                            <StyledA
                                :href="'https://github.com/mr-robertamoah'"
                                :text="'GitHub'"
                            />
                        </div>
                    </div>

                    <p class="text-gray-600 text-base mt-8 text-justify">
                        A software engineer with experience in full-stack web development.
                    </p>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="overflow-hidden min-h-[20vh] p-4 flex flex-col justify-center items-start mx-auto w-[90%] sm:w-[80%]">
                    <div class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold text-2xl">Background Story</div>

                    <div class="text-gray-600 text-lg mt-4 font-medium">Sometimes a bad situation can lead to something positive.</div>

                    <p class="text-gray-600 text-base mt-8 text-justify">
                        I was expectant of a positive result from the selection process of a program in which I was very much interested. Throughout the selection process, I gave my all and I was very certain about getting picked for the program.
                        To me, it was a lifechanging opportunity. After the fourth stage, I suddenly didn't hear from the coordinator, even after leaving numerous texts. It dawned on me. I was out! This broke me. Yes, I know I am not some wood to be broken, but you get it.
                        Everything seemed alright till I suddenly couldn't get myself to do anything, one day. It was a terrible experience. I was <strong>depressed</strong> and I couldn't do anything about it.
                    </p>
                    <p class="text-gray-600 text-base mt-2 text-justify">
                        I know myself to be strong, and so I could not bring myself to finding someone with whom to talk. I cannot tell if I was simply shy or too proud. The issue persisted for weeks.
                        This led to me deferring from an online software engineering program. I finally got myself out with a smiling face but a worried mind.
                        It was in this abyss that the idea to have a platform that can help individuals, like myself, with mental health issues, popped up in my mind.
                    </p>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg min-h-[50vh] p-4">
                    <div class="mt-4 ml-2 text-2xl font-bold w-fit bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent uppercase">
                        Testimonials
                    </div>

                    <div class="mt-10 mb-5 sm:grid sm:grid-cols-2 sm:space-x-3 space-y-3 sm:space-y-0" v-if="testimonials.length">
                        <div 
                            v-for="testimonial in testimonials"
                            :key="testimonial.id"
                            class="flex flex-col justify-center items-start w-[90%] sm:w-[80%] mx-auto"
                        >
                            <div class="text-base"><span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">@{{ testimonial.addedby.username }}</span></div>
                            <div class="text-lg text-gray-600">{{ testimonial.addedby.fullName ?? testimonial.addedby.name }} ({{ testimonial.by }})</div>
                            <div class="text-gray-600 text-sm tracking-wide mt-4 text-justify p-2 bg-gray-200 rounded">
                                <p>
                                    {{ testimonial.content }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="text-sm text-gray-600 mt-8 w-[90%] sm:w-[80%] mx-auto">
                        No testimonials to show for now. {{ user ? 'If you feel this app has had an impact, do share a testimonial' : 'You may have a look around the app and you may want to testify soon'}}.
                    </div>

                    <div class="flex p-2 justify-end mt-4 w-[90%] sm:w-[80%] mx-auto" v-if="user">
                        <PrimaryButton @click="() => showModal('testimonial')">add testimonial</PrimaryButton>
                    </div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="overflow-hidden p-4 py-8">
                    <div class="cursor-pointer bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold text-lg">#TalkTherapy</div>
                    <div class="cursor-pointer mt-2 text-gray-600" @click="() => showModal('contact')">Contact us</div>
                </div>
            </div>
        </div>

        <ContactUsModal
            :show="modalData.show && modalData.type == 'contact'"
            @close-modal="closeModal"
        />
        
        <CreateTestimonialModal
            :show="modalData.show && modalData.type == 'testimonial'"
            @close-modal="closeModal"
        />
    </AuthenticatedLayout>
</template>
