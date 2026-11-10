<script setup>
import FeatureComponent from '@/Components/FeatureComponent.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { onBeforeMount, reactive, ref, watch } from 'vue';
import WriteableText from '@/Components/WriteableText.vue';
import StyledA from '@/Components/StyledA.vue';
import Avatar from '@/Components/Avatar.vue';
import useModal from '@/Composables/useModal';
import useFeatures from '@/Composables/useFeatures';
import CreateTestimonialModal from '@/Components/CreateTestimonialModal.vue';
import PrimaryButton from '../Components/PrimaryButton.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const { closeModal, showModal, modalData } = useModal()
const { features } = useFeatures()
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

onBeforeMount(() => {
    getStats()
})

const greetings = ref("Hello")
const robertAvatar = `/storage/others/robertamoah.png`
const stats = reactive({
    counsellors: 0,
    therapies: 0,
    users: 0,
})

async function getStats() {
    await axios.get(route('api.about.stats'))
        .then((res) => {
            stats.counsellors = res.data.stats.counsellors
            stats.users = res.data.stats.users
            stats.therapies = res.data.stats.therapies
        })
        .catch((err) => {
            console.log(err)
            
        })
}

</script>

<template>
    <Head title="About" />

    <AuthenticatedLayout>
        <!-- Hero Section -->
        <div class="relative bg-gradient-to-br from-gray-50 via-stone-100 to-slate-100 overflow-hidden">
            <div class="absolute inset-0">
                <svg class="absolute bottom-0 left-0 transform translate-y-1/2" width="404" height="404" fill="none" viewBox="0 0 404 404">
                    <defs>
                        <pattern id="85737c0e-0916-41d7-917f-596dc7edfa27" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                            <rect x="0" y="0" width="4" height="4" class="text-gray-200" fill="currentColor" />
                        </pattern>
                    </defs>
                    <rect width="404" height="404" fill="url(#85737c0e-0916-41d7-917f-596dc7edfa27)" />
                </svg>
            </div>
            
            <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-24">
                <div class="text-center">
                    <div class="animate-fade-in-up">
                        <h1 class="text-4xl md:text-6xl font-bold text-gray-800 mb-6">
                            {{ greetings }}
                        </h1>
                        <div class="w-24 h-1 bg-gray-600 mx-auto mb-8 animate-scale-in"></div>
                    </div>
                    
                    <div class="animate-fade-in-up animation-delay-300">
                        <h2 class="text-xl md:text-2xl text-gray-600 mb-8">
                            Welcome to <span class="font-bold text-gray-800">TalkTherapy</span>
                        </h2>
                        <p class="text-lg text-gray-600 max-w-3xl mx-auto mb-12">
                            Your journey to mental wellness starts here. Connect with verified mental health professionals in a secure, supportive environment.
                        </p>
                    </div>
                    
                    <div class="animate-bounce-in animation-delay-600">
                        <svg class="w-8 h-8 mx-auto text-gray-400 animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                        </svg>
                        <p class="text-sm text-gray-500 mt-2">Scroll to learn more</p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Logo Section -->
        <div class="py-16 bg-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <div class="animate-fade-in">
                    <ApplicationLogo class="w-32 h-32 md:w-48 md:h-48 mx-auto mb-8 transform hover:scale-105 transition-transform duration-300"/>
                    <div class="w-16 h-1 bg-gray-300 mx-auto"></div>
                </div>
            </div>
        </div>
        <!-- Mission & Vision Section -->
        <div class="py-20 bg-gray-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-16">
                    <!-- Mission -->
                    <div class="animate-fade-in-left">
                        <div class="bg-white p-8 rounded-2xl shadow-lg hover:shadow-xl transition-shadow duration-300">
                            <div class="flex items-center mb-6">
                                <div class="w-3 h-12 bg-gray-600 rounded-full mr-4"></div>
                                <h3 class="text-3xl font-bold text-gray-800">Our Mission</h3>
                            </div>
                            <div class="mb-6">
                                <svg class="w-16 h-16 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                </svg>
                            </div>
                            <p class="text-lg text-gray-600 mb-6 font-medium">
                                Holistic wellbeing - emotional, psychological, and social - is achievable for everyone.
                            </p>
                            <p class="text-gray-600 leading-relaxed">
                                We connect individuals with verified, professional counsellors who are certified through recognized governmental, professional, or religious institutions. This creates meaningful relationships that help people overcome existing mental health challenges or prevent them altogether.
                            </p>
                        </div>
                    </div>
                    
                    <!-- Vision -->
                    <div class="animate-fade-in-right">
                        <div class="bg-white p-8 rounded-2xl shadow-lg hover:shadow-xl transition-shadow duration-300">
                            <div class="flex items-center mb-6">
                                <div class="w-3 h-12 bg-gray-600 rounded-full mr-4"></div>
                                <h3 class="text-3xl font-bold text-gray-800">Our Vision</h3>
                            </div>
                            <div class="mb-6">
                                <svg class="w-16 h-16 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                            </div>
                            <div class="text-center">
                                <div class="text-lg text-gray-600 mb-4">To build understanding and focus on</div>
                                <div class="bg-gray-800 text-white px-6 py-3 rounded-lg font-bold text-xl mb-6">
                                    MENTAL HEALTH ISSUES
                                </div>
                                <p class="text-gray-600 leading-relaxed">
                                    Enhancing the use of social and systematic approaches in handling matters regarding mental health through innovative technology and human connection.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4 relative">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg min-h-[80vh] p-4">
                    <div class="mt-4 ml-2 text-2xl font-bold w-fit bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent uppercase">
                        What we are to you
                    </div>

                    <div class="mt-10 mb-5 flex flex-col justify-center items-start w-[90%] sm:w-[80%] mx-auto relative">
                        <!-- Floating SVG Shape -->
                        <div class="absolute -top-8 -right-8 opacity-10 animate-float">
                            <svg width="80" height="80" viewBox="0 0 100 100" class="text-blue-300">
                                <circle cx="50" cy="50" r="40" fill="currentColor" />
                            </svg>
                        </div>
                        
                        <div class="text-lg mb-6">For <span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">Individuals</span></div>
                        <div class="text-gray-600 text-base text-justify space-y-4">
                            <p class="leading-relaxed">
                                Life presents us with countless challenges every day. The way we handle these challenges shapes who we become. 
                                But you don't have to face them alone. <strong>TalkTherapy</strong> connects you with professional counsellors 
                                who can guide you through your emotional, psychological, and social journey with confidence and clarity.
                            </p>
                            <p class="leading-relaxed">
                                Sometimes you can't pinpoint what's wrong—you just don't feel right. That's exactly when 
                                <strong>TalkTherapy</strong> becomes your safe haven for healing and self-discovery.
                            </p>
                            <p class="leading-relaxed">
                                <strong>You're in complete control:</strong> Choose to remain anonymous, decide on payment terms, 
                                and set your own pace. Our verified counsellors invest their time, expertise, and care to help 
                                transform your life—consider supporting them as they support you.
                            </p>
                            <p class="font-semibold text-gray-800 bg-gray-100 p-3 rounded-lg">
                                Remember: Not all advice is helpful advice. Choose verified professionals who truly understand your journey.
                            </p>
                        </div>
                    </div>

                    <div class="mt-10 mb-5 flex flex-col justify-center items-start w-[90%] sm:w-[80%] mx-auto relative">
                        <!-- Floating SVG Shape -->
                        <div class="absolute -top-6 -left-6 opacity-10 animate-pulse">
                            <svg width="60" height="60" viewBox="0 0 100 100" class="text-violet-300">
                                <polygon points="50,10 90,90 10,90" fill="currentColor" />
                            </svg>
                        </div>
                        
                        <div class="text-lg mb-6">For <span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">Mental Health Professionals</span></div>
                        <div class="text-gray-600 text-base text-justify space-y-4">
                            <p class="leading-relaxed">
                                <strong>Share your expertise</strong> through meaningful posts on our dedicated mental health platform. 
                                Build your professional presence while contributing to a community focused on mental wellness and healing.
                            </p>
                            <p class="leading-relaxed">
                                <strong>Connect with those who need you most.</strong> Send or receive therapy requests, offer both 
                                individual and group sessions, and set your own terms—whether free, paid, or donation-based. 
                                Your flexibility helps make mental health support accessible to everyone.
                            </p>
                            <p class="leading-relaxed bg-blue-50 p-3 rounded-lg">
                                <strong>Professional Growth:</strong> Even with free sessions, receive donations and build lasting 
                                relationships with clients who value your expertise and dedication to their mental health journey.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="overflow-hidden min-h-[20vh] p-4 flex flex-col justify-center items-start mx-auto w-[90%] sm:w-[80%] relative">
                    <!-- Floating SVG Shape -->
                    <div class="absolute -top-4 -right-4 opacity-10 animate-bounce">
                        <svg width="50" height="50" viewBox="0 0 100 100" class="text-gray-300">
                            <rect x="25" y="25" width="50" height="50" fill="currentColor" transform="rotate(45 50 50)" />
                        </svg>
                    </div>
                    
                    <div class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold text-2xl">Meet the Founder</div>

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
                                :href="'https://github.com/mr-robertamoah'"
                                :text="'GitHub'"
                            />
                            <StyledA
                                :href="'https://www.linkedin.com/in/mr-robert-amoah'"
                                :text="'LinkedIn'"
                            />
                            <StyledA
                                :href="'https://www.x.com/Mr_robertamoah'"
                                :text="'X'"
                            />
                            <StyledA
                                :href="'https://www.facebook.com/share/Mj7V4hrem4NfVPg4'"
                                :text="'Facebook'"
                            />
                        </div>
                    </div>

                    <p class="text-gray-600 text-base mt-8 text-justify">
                        A software engineer with experience in full-stack web development.
                    </p>
                </div>
            </div>
            
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="overflow-hidden min-h-[20vh] p-4 flex flex-col justify-center items-start mx-auto w-[90%] sm:w-[80%] relative">
                    <!-- Floating SVG Shape -->
                    <div class="absolute -bottom-4 -left-4 opacity-10 animate-spin-slow">
                        <svg width="60" height="60" viewBox="0 0 100 100" class="text-blue-200">
                            <path d="M50,10 L90,50 L50,90 L10,50 Z" fill="currentColor" />
                        </svg>
                    </div>
                    
                    <div class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold text-2xl">Our Story</div>

                    <div class="text-gray-600 text-lg mt-4 font-medium italic">"Sometimes our greatest challenges become the foundation for our most meaningful contributions."</div>

                    <p class="text-gray-600 text-sm mt-8 text-justify">
                        I was expectant of a positive result from the selection process of a program in which I was very much interested. Throughout the selection process, I gave my all and I was very certain about getting picked for the program.
                        To me, it was a lifechanging opportunity. After the fourth selection stage (the stages being five), I suddenly stopped hearing from the coordinator, even after leaving numerous texts in her DM. After a while it dawned on me. I could not get into the program! This broke me. Yes, I know I am not some wood to be broken, but you get it.
                        Everything seemed alright till I suddenly couldn't get myself to do anything. This took weeks. It was a terrible experience. I was <strong>depressed</strong> and I couldn't do anything about it.
                    </p>
                    <p class="text-gray-600 text-sm mt-2 text-justify">
                        I know myself to be strong, and so I could not bring myself to finding someone with whom to talk. I cannot tell if I was simply shy or too proud. The issue persisted for weeks.
                        This led to me deferring from an online software engineering program. Even when I finally got myself out with a smiling face, I still had a worried mind.
                        It was in this abyss that the idea to have a platform that can help individuals, like myself, with mental health issues, popped up in my mind. The question whose answer birthed this application was: <strong>Could there be a platform which allowed individuals stay anonymous and still seek help from professionals?</strong>
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
                <div class="overflow-hidden shadow-sm sm:rounded-lg min-h-[50vh] p-4">
                    <div class="mt-4 ml-2 text-2xl font-bold w-fit bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent uppercase">
                        Features
                    </div>

                    <div v-if="features.length" class="mt-8">
                        <!-- <div
                            class="text-lg font-bold w-fit bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent capitalize mb-2 mx-auto">current</div> -->
                        <!-- <div class="text-sm text-gray-600 text-justify mb-2">These are the features that are currently available.</div> -->
                        <div
                            class="mt-4 gap-10 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 p-2 pt-6">
                            <FeatureComponent
                                v-for="(feature, idx) in features"
                                :key="idx"
                                :feature="feature"
                                class="w-[90%] shrink-0 max-w-md"
                            />
                        </div>
                    </div>

                    <div class="w-full text-sm text-gray-600 text-center my-2">no features</div>
                </div>
            </div>

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg min-h-[50vh] p-4">
                    <div class="mt-4 ml-2 text-2xl font-bold w-fit bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent uppercase">
                        Statistics
                    </div>

                    <div class="mt-10 mb-5">
                        <div class="w-full text-pretty text-center">
                            <div class="text-lg uppercase text-center"><span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">Users</span></div>
                            <div class="w-full flex justify-center items-center">
                                <div class="w-8 h-1 bg-gray-400 rounded mb-2"></div>
                            </div>
                            <div class="text-xl text-gray-600">{{ stats.users }}</div>
                        </div>
                    </div>

                    <hr>

                    <div class="mt-10 mb-5">
                        <div class="w-full text-pretty text-center">
                            <div class="text-lg uppercase text-center"><span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">Counsellors</span></div>
                            <div class="w-full flex justify-center items-center">
                                <div class="w-20 h-1 bg-gray-400 rounded mb-2"></div>
                            </div>
                            <div class="text-xl text-gray-600">{{ stats.counsellors }}</div>
                        </div>
                    </div>

                    <hr>

                    <div class="mt-10 mb-5">
                        <div class="w-full text-pretty text-center">
                            <div class="text-lg uppercase text-center"><span class="bg-gradient-to-br from-blue-800 to-violet-500 bg-clip-text text-transparent font-bold">Therapies</span></div>
                            <div class="w-full flex justify-center items-center">
                                <div class="w-16 h-1 bg-gray-400 rounded mb-2"></div>
                            </div>
                            <div class="text-xl text-gray-600">{{ stats.therapies }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Call to Action -->
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mt-12">
                <div class="overflow-hidden p-4 py-8 text-center">
                    <p class="text-gray-600 text-lg">Your mental wellness journey starts with a single conversation.</p>
                </div>
            </div>


        
        <CreateTestimonialModal
            :show="modalData.show && modalData.type == 'testimonial'"
            @close-modal="closeModal"
        />
    </AuthenticatedLayout>
</template>
<style scoped>
@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes fadeInLeft {
  from {
    opacity: 0;
    transform: translateX(-30px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

@keyframes fadeInRight {
  from {
    opacity: 0;
    transform: translateX(30px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

@keyframes scaleIn {
  from {
    transform: scaleX(0);
  }
  to {
    transform: scaleX(1);
  }
}

@keyframes bounceIn {
  0% {
    opacity: 0;
    transform: scale(0.3);
  }
  50% {
    opacity: 1;
    transform: scale(1.05);
  }
  70% {
    transform: scale(0.9);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}

.animate-fade-in-up {
  animation: fadeInUp 0.8s ease-out forwards;
}

.animate-fade-in-left {
  animation: fadeInLeft 0.8s ease-out forwards;
}

.animate-fade-in-right {
  animation: fadeInRight 0.8s ease-out forwards;
}

.animate-fade-in {
  animation: fadeIn 0.8s ease-out forwards;
}

.animate-scale-in {
  animation: scaleIn 0.8s ease-out forwards;
}

.animate-bounce-in {
  animation: bounceIn 1s ease-out forwards;
}

.animation-delay-300 {
  animation-delay: 0.3s;
  opacity: 0;
}

.animation-delay-600 {
  animation-delay: 0.6s;
  opacity: 0;
}

@keyframes float {
  0%, 100% {
    transform: translateY(0px);
  }
  50% {
    transform: translateY(-10px);
  }
}

@keyframes spin-slow {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

.animate-float {
  animation: float 3s ease-in-out infinite;
}

.animate-spin-slow {
  animation: spin-slow 8s linear infinite;
}
</style>