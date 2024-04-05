import { ref } from "vue"
import useAlert from "./useAlert"

const { setFailedAlertData } =  useAlert()

export default function useLocation() {
    
    const currentLocation = ref({ lat: '', lng: '' })

    function setCurrentLocation({ lat = null, lng = null }) {
        currentLocation.value.lat = lat
        currentLocation.value.lng = lng
    }

    function getCurrentLocation () {
        if (navigator.geolocation) {

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    setCurrentLocation({
                        lat: `${position.coords.latitude}`,
                        lng: `${position.coords.longitude}`,
                    })

                    addMarker(reportMap, {lat: position.coords.latitude, lng: position.coords.longitude})
                },
                (err) => {
                    console.log(err)
                    setFailedAlertData({
                        message: 'Something happened while getting location. Try again later or check your browser settings.',
                        time: 5000,
                    })
                },
            )
        } else {
            setFailedAlertData({
                message: 'Your browser does not support getting location.',
                time: 5000,
            })
        }
    }

    return {
        setCurrentLocation, getCurrentLocation, currentLocation
    }
}