import { ref } from "vue";

export default function useGuidedTours() {

    const PAGES = {
        home: "Home",
        profile: "Profile",
        therapy: "Therapy",
        preference: "Preference",
    }
    
    const CONDITION_NAMES = {
        isUser: "User is logged in",
        isGuest: "User is not logged in",
        emailUnverified: "User email not verified",
        isCounsellor: "User is counsellor",
    }
    
    const CONDITION_CALLABLES = {
        [CONDITION_NAMES.isUser]: (user) => {
            return !!user
        },
        [CONDITION_NAMES.isGuest]: (user) => {
            return user ? false : true
        },
        [CONDITION_NAMES.emailUnverified]: (user) => {
            return user?.emailVerifiedAt ? false : true
        },
        [CONDITION_NAMES.isCounsellor]: (user) => {
            return !!user?.counsellor
        },
    }

    const tours = ref([
        {
            name: "Home Page Tour",
            description: "You are taking a short trip on how the Home page looks like.",
            page: PAGES.home,
            howToSteps: [
                { 
                    id: 1,
                    position: 1,
                    name: 'Navigation', 
                    description: 'These help you to find your way around TalkTherapy app.',
                    messages: [
                        {
                            condition: CONDITION_NAMES.isUser,
                            message: "The dropdown arrow or humburger icon should show you the various pages you can visit. Explore!",
                        },
                        {
                            condition: CONDITION_NAMES.isGuest,
                            message: "Use the respective buttons to either log into your account or register to be part of this community. If you are seeing the humburger icon, simply click it to reveal the buttons.",
                        }
                    ],
                    elementId: 'home-nav-id',
                    scroll: false,
                },
                { 
                    id: 2,
                    name: 'Counsellors', 
                    description: 'The two counsellor sections show you which counsellors when active in the previous month as well as their performance in the current month.',
                    position: 2,
                    elementId: 'home-counsellors-id',
                    scroll: true,
                },
                { 
                    id: 3,
                    position: 3,
                    name: 'Therapies', 
                    description: 'This section shows you some therapies which have been shared publicly. Public therapies helps you to know how therapies go. Note that the user may have chosen to be anonymous.',
                    messages: [
                        {
                            condition: CONDITION_NAMES.isUser,
                            message: "Since you are logged in, there is a section which gives you quick access to some of your therapies as a user or counsellor.",
                        },
                    ],
                    elementId: 'home-therapies-id',
                    scroll: true,
                },
                { 
                    id: 4,
                    position: 4,
                    name: 'Posts', 
                    description: 'This section shows you posts that have been shared by counsellors or administrators of TalkTherapy app. This is to bring certain mental health issues to your attention.',
                    messages: [
                        {
                            condition: CONDITION_NAMES.isUser,
                            message: "Do well to share what you think about the posts in the comment section.",
                        },
                        {
                            condition: CONDITION_NAMES.isGuest,
                            message: "You can get to like or comment on them once you are logged in.",
                        },
                    ],
                    elementId: 'home-posts-id',
                    scroll: true,
                },
            ],
        },
        {
            name: "User Profile Page Tour",
            description: "Have a look at what you can do on your user profile page.",

            page: PAGES.profile,
            howToSteps: [
                { 
                    id: 1,
                    position: 1,
                    name: 'Navigation', 
                    description: 'These help you to find your way around TalkTherapy app.',
                    messages: [
                        {
                            message: "The dropdown arrow or humburger icon should show you the various pages you can visit. Explore!",
                        },
                    ],
                    elementId: 'home-nav-id',
                    scroll: false,
                },
                { 
                    id: 2,
                    position: 2,
                    name: 'Therapies', 
                    description: 'The "Show Therapies" button will take you to the therapies page where you can see all of your therapies as a user, counsellor and guardian.',
                    elementId: 'profile-therapies-id',
                    scroll: true,
                },
                { 
                    id: 3,
                    position: 3,
                    name: 'Guardianship', 
                    description: 'With this section, you can send guardianship requests to other users or manage all the guardianship requests sent to you.',
                    elementId: 'profile-guardianship-id',
                    scroll: true,
                },
                { 
                    id: 4,
                    position: 4,
                    name: 'Testimonials', 
                    description: 'Once you use this application and have something nice to share with others about your experience, use this section to share with the world. Your testimonials appear in our about page.',
                    elementId: 'profile-testimonial-id',
                    scroll: true,
                },
                { 
                    id: 5,
                    position: 5,
                    name: 'Profile Information', 
                    description: 'Here you see the basic information of your user account and by clicking the "UPDATE" button, you can easily update these information.',
                    elementId: 'profile-info-id',
                    messages: [
                        {
                            condition: CONDITION_NAMES.emailUnverified,
                            message: "The email address you have provided is not verified. Please use the next section to verify your email. This will ensure you receive the right email notifications.",
                        },
                    ],
                    scroll: true,
                },
                { 
                    id: 6,
                    position: 6,
                    name: 'Set Preferences', 
                    description: 'These preferences help us to better tailor suggestions to you.',
                    elementId: 'profile-pref-id',
                    scroll: true,
                },
                { 
                    id: 7,
                    position: 7,
                    name: 'Update Password', 
                    description: 'Update your passwords whenever you can to better secure your account. Always use a strong password.',
                    elementId: 'profile-password-id',
                    scroll: true,
                },
                { 
                    id: 8,
                    name: 'Become a Counsellor', 
                    description: 'This section takes you through the processes of become a counsellor on the app. It starts from clicking the "Become" button to handling your first therapy.',
                    position: 8,
                    elementId: 'profile-counsellor-id',
                    scroll: true,
                },
                { 
                    id: 9,
                    position: 9,
                    name: 'Delete Account', 
                    description: 'Here, you can delete your account and it will be like you were never here. We hope you never get to use this section of your profile.',
                    elementId: 'profile-account-id',
                    scroll: true,
                },
            ],
        },
        {
            name: "Therapy Page Tour",
            description: "Have a look at what you can do on your user profile page.",

            page: PAGES.therapy,
            howToSteps: [
                { 
                    id: 1,
                    position: 1,
                    name: 'Sessions', 
                    description: 'You get to see the sessions scheduled and/or held.',
                    elementId: 'therapy-sessions-id',
                    scroll: true,
                },
                { 
                    id: 2,
                    position: 2,
                    name: 'Topics', 
                    description: 'This shows the topics that the counsellor has set for the therapy. Topics provides a way to organize messages to put them in the right context.',
                    elementId: 'therapy-topics-id',
                    messages: [
                        {
                            condition: CONDITION_NAMES.isCounsellor,
                            message: "The Discussions section below shows the discussions a counsellor for this therapy schedules with other counsellors.",
                        },
                    ],
                    scroll: true,
                },
                { 
                    id: 3,
                    position: 3,
                    name: 'Therapy Information', 
                    description: 'With this section, you get to see the various information about this therapy.',
                    elementId: 'therapy-info-id',
                    messages: [
                        {
                            message: "The participants section shows the counsellor and user (if the user is not anonymous) who are engaged in therapy. This is also where you get to send invitations to counsellors.",
                        },
                    ],
                    scroll: true,
                },
                { 
                    id: 4,
                    position: 4,
                    name: 'Messages', 
                    description: 'Here, you can view the messages per session or topic and have a feel of how a therapy session can help improve a person\'s mental health.',
                    elementId: 'therapy-messages-id',
                    scroll: true,
                },
                { 
                    id: 5,
                    position: 5,
                    name: 'Actions', 
                    description: 'This section reveals the various actions a user or counsellor can perform such as Creating, Updating or Deleting sessions, etc.',
                    elementId: 'therapy-actions-id',
                    scroll: true,
                },
            ],
        },
        {
            name: "Preference Page Tour",
            description: "Have a look at what you can do on your user profile page.",

            page: PAGES.preference,
            howToSteps: [
                { 
                    id: 1,
                    position: 1,
                    name: 'Anonymity', 
                    description: 'If you set this to true, your name will be hidden, by default, from the counsellor and any other person, in the case of public therapies.',
                    elementId: 'preference-anon-id',
                    scroll: true,
                },
                { 
                    id: 2,
                    position: 2,
                    name: 'Cases', 
                    description: 'You can create a therapy case if it does not exist. The cases helps to categorize therapy types and gives a sense of what you are dealing with. When you select a case, it will help us make better suggestions to you.',
                    elementId: 'preference-cases-id',
                    scroll: true,
                },
                { 
                    id: 3,
                    position: 3,
                    name: 'Languages', 
                    description: 'You can create a language if it does not exist. When you select a language, it will help us connect you with counsellors who communicate in those languages.',
                    elementId: 'preference-languages-id',
                    scroll: true,
                },
                { 
                    id: 4,
                    position: 4,
                    name: 'Religion', 
                    description: 'You can ignore this part if you are not religious and can accept to have sessions with counsellors who do not necessarily have the same religious orientation. You can create a therapy case if it does not exist. When you select a religion, it will help connect you with counsellors with similar religious orientation.',
                    elementId: 'preference-religions-id',
                    scroll: true,
                },
                { 
                    id: 5,
                    position: 5,
                    name: 'Actions', 
                    description: 'Once you make changes to your preferences,  you can save them by clicking on the "SET PREFERENCES" button.',
                    elementId: 'preference-action-id',
                    scroll: true,
                },
            ],
        },
    ])

    return { tours, PAGES, CONDITION_CALLABLES, CONDITION_NAMES }
}