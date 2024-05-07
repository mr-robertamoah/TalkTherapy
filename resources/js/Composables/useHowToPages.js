import { ref } from "vue";

export default function useHowToPages() {
    
    const appPages = ref([
        'Preference', 
        'User Profile', 
        'Home', 
        'about', 
        'Register', 
        'Login', 
        'Therapy',
        'Therapy Index', 
        'Counsellor Profile',
        'Create Therapy',
        'Update Therapy',
        'Create Session',
        'Have Session',
        'Check Requests',
        'User Email Verification',
        'Counsellor Email Verification',
        'Counsellor Profile Update',
        'User Profile Update',
    ])

    return { appPages }
}