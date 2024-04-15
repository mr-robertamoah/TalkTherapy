import { ref } from "vue"
import { Loader } from '@googlemaps/js-api-loader';
import useAlert from "./useAlert"

const { setFailedAlertData } =  useAlert()

export default function useMap(zoom = 15) {
    
    const mapDetails = ref({
        Map: null,
        Marker: null
    })
    const markerPosition = ref({ lat: '', lng: '' })
    const map = ref(null)
    const advancedMarker = ref()

    function setMarkerPosition({ lat = '', lng = '' }) {
        markerPosition.value.lat = lat ? String(lat) : null
        markerPosition.value.lng = lng ? String(lng) : null
    }
    
    async function initMap() {
        const loader = new Loader({
            apiKey: import.meta.env.VITE_GOOGLE_API_KEY,
            version: 'weekly'
        })

        if (!mapDetails.value.Map) {
            await loader.importLibrary('maps')
                .then(data => {
                    console.log(data, 'map')
                    mapDetails.value.Map = data.Map
                })
                .catch(err => {
                    console.log(err)
                    
                    setFailedAlertData({
                        message: 'Something happened while getting google maps.',
                        time: 5000,
                    })
                })
        }
            
        if (!mapDetails.value.Marker) {
            await loader.importLibrary('marker')
                .then(data => {
                    console.log(data, 'marker')
                    mapDetails.value.Marker = data.AdvancedMarkerElement
                })
                .catch(err => {
                    console.log(err)
                    
                    setFailedAlertData({
                        message: 'Something happened while getting google map markers.',
                        time: 5000,
                    })
                })
            }
    }

    function createMap(mapDiv, { lat, lng }) {
        if (!mapDetails.value?.Map || !mapDiv) return

        const aMap = new mapDetails.value.Map(mapDiv, {
            center: { lat: parseFloat(lat), lng: parseFloat(lng) },
            mapId: mapDiv.id,
            zoom
        })
    
        aMap.addListener('click', (event) => {
            addMarker(aMap, {
                lat: event.latLng.lat(),
                lng: event.latLng.lng(),
            })
        })

        map.value = aMap
    }

    function addMarker(aMap, location, title = 'session location') {
        if (!mapDetails.value?.Marker) return
    
        const marker = new mapDetails.value.Marker({
            map: aMap,
            title,
            position: { lat: parseFloat(location.lat), lng: parseFloat(location.lng) },
            gmpDraggable: true,
        })
    
        setMarkerPosition(location)
        
        marker.addListener('dragend', (event) => {
            const newLocation = {
                lat: event.latLng.lat(),
                lng: event.latLng.lng(),
            }

            setMarkerPosition(newLocation)
        })

        if (advancedMarker.value)
            advancedMarker.value.setMap(null)

        advancedMarker.value = marker
    }

    return {
        setMarkerPosition, addMarker, mapDetails, markerPosition, createMap, initMap, map
    }
}