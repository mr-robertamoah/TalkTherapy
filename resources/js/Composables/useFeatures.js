import { ref } from "vue";

export default function useFeatures() {
    
    const features = ref({
        current: [
            {
                name: "Therapy",
                description: "This feature allows users to create a Therapy. A Therapy provides users with the opportunity to request help from counsellors. It allows single or multiple sessions with the counsellor. This can be paid or free and it is in the hands of the user to choose. It has a duration which is set by the counsellor when creating sessions of the therapy.",
                descriptions: [
                    { 
                        title: 'Online', 
                        note: 'For online sessions, The therapy happens on the app. The user and counsellor will have the session by sending real-time messages to eachother like a chat.' 
                    },
                    { 
                        title: 'Offline', 
                        note: 'For offline sessions, The therapy happens in-person and it is held at a location choosen by the counsellor and shared with the user.' 
                    },
                ]
            },
            {
                name: "Counsellor",
                description: "You can become a counsellor on this application by first creating a counsellor account. With this account, you remain unverified by default. To be verified, you need to set you email as counsellor and seek email verification. Once this is done, you can, you can send a counsellor veriification request with National Identification number/image as well as license or any document that makes you a recognised counsellor by a governmental, non-governmental or regigious body. In sending the request, if the said body is not know to the platform, you easily create, select and add the relevant number/image.",
            },
        ],
        next: [
            {
                name: "Group Therapy",
                description: "This feature allows a group of counsellors the opportunity to have sessions with multiple users having similar cases. A user or counsellor will be able to create a group therapy and send requests to counsellors as well as users. There will be an invitation link both for group administrators, counsellors and users to make easy to join group therapies.",
            },
            {
                name: "Post",
                description: "This feature allows counsellors make posts that last seven days. This post allows text and images and can be used by counsellors to educate user on mental health matters.",
            },
            {
                name: "Discussion",
                description: "This feature allows counsellors to seek the help of other counsellors in order to better understand the case of a user and determine the best form of help to provide. A counsellor will schedule a discussion and send out requests to preferred counsellors to hold a discussion either on the entire therapy or a selected session of the therapy.",
            },
        ],
        future: [
            {
                name: "Institutionalisation",
                description: "We are currently considering the possibily of adding a feature that allows users to suggest persons who need institutionalisation. With the help of mental institutions and donors, we would be able to institutionalise these persons and help them get back to their better selves. This feature is currently only in consideration stage because it requires unestablished coordination between us and other institutions.",
            },
        ],
    })

    return { features }
}