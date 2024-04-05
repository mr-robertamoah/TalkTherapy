import { ref } from "vue"
import { Loader } from '@googlemaps/js-api-loader';
import useAlert from "./useAlert"

const { setFailedAlertData } =  useAlert()

export default function useMap() {
    
    const mapDetails = ref({
        Map: null,
        Marker: null
    })
    const markerPosition = ref({ lat: '', lng: '' })
    const map = ref(null)

    function setMarkerPosition({ lat = '', lng = '' }) {
        markerPosition.value.lat = lat ? String(lat) : null
        markerPosition.value.lng = lng ? String(lng) : null
    }
    
    async function initMap() {
        const loader = new Loader({
            apiKey: import.meta.env.VITE_GOOGLE_API_KEY,
            version: 'weekly'
        })

        let Map, Marker;

        await loader.importLibrary('maps')
            .then(data => {
                console.log(data, 'map')
                Map = data.Map
            })
            .catch(err => {
                console.log(err)
                
                setFailedAlertData({
                    message: 'Something happened while getting google maps.',
                    time: 5000,
                })
            })
            
        await loader.importLibrary('marker')
            .then(data => {
                console.log(data, 'marker')
                Marker = data.Marker
            })
            .catch(err => {
                console.log(err)
                
                setFailedAlertData({
                    message: 'Something happened while getting google map markers.',
                    time: 5000,
                })
            })

        mapDetails.value.Map = Map
        mapDetails.value.Map = Marker
    }

    function createMap(mapId, { lat, lng }) {
        if (!mapDetails.value?.Map) return

        map.value = new mapDetails.value.Map(document.getElementById(mapId), {
            center: { lat: Number(lat), lng: Number(lng) },
            mapId,
            zoom: 12
        })
    
        map.value.addListener('dblclick', (event) => {
            addMarker(map.value, {
                lat: event.latLng.lat(),
                lng: event.latLng.lng(),
            })
        })
    }

    function addMarker(aMap, location, title = 'session location') {
        if (!mapDetails.value?.Marker) return
    
        const marker = new mapDetails.value.Marker({
            aMap,
            title,
            position: location,
            draggble: true
        })
    
        setMarkerPosition(location)

        marker.addListener('dragend', (event) => {
            console.log(event, 'event')
            event.preventDefault()
            setMarkerPosition(location)
        })
    }

    return {
        setMarkerPosition, addMarker, mapDetails, markerPosition, createMap, initMap
    }
}