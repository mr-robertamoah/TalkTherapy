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
    
    // The loader is a singleton per set of options, so the map and the place search share one script.
    function loader() {
        return new Loader({
            apiKey: import.meta.env.VITE_GOOGLE_API_KEY,
            version: 'weekly'
        })
    }

    async function initMap() {
        if (mapDetails.value.Map && mapDetails.value.Marker) return
        
        const mapsLoader = loader()

        if (!mapDetails.value.Map) {
            await mapsLoader.importLibrary('maps')
                .then(data => {
                    mapDetails.value.Map = data.Map
                })
                .catch(() => {
                    setFailedAlertData({
                        message: 'Something happened while getting google maps.',
                    })
                })
        }
            
        if (!mapDetails.value.Marker) {
            await mapsLoader.importLibrary('marker')
                .then(data => {
                    mapDetails.value.Marker = data.AdvancedMarkerElement
                })
                .catch(() => {
                    setFailedAlertData({
                        message: 'Something happened while getting google map markers.',
                    })
                })
            }
    }

    // SCRUM-444: the place search (Places API, new). Resolves to the PlaceAutocompleteElement class, or null when
    // there's no key or the library can't load -- the caller falls back to the plain map, with no alert: the map and the
    // landmark still work without search.
    async function initPlaces() {
        if (!import.meta.env.VITE_GOOGLE_API_KEY) return null

        try {
            const { PlaceAutocompleteElement } = await loader().importLibrary('places')

            return PlaceAutocompleteElement ?? null
        } catch {
            return null
        }
    }

    function createMap(mapDiv, { lat, lng }) {
        if (!mapDetails.value?.Map || !mapDiv) return

        // SCRUM-443 (QA): without the browser's location the centre was NaN, the map stayed grey and a click dropped the
        // pin at the edge of the world -- centre on Accra instead until a place is known.
        const centre = Number.isFinite(parseFloat(lat)) && Number.isFinite(parseFloat(lng))
            ? { lat: parseFloat(lat), lng: parseFloat(lng) }
            : { lat: 5.6037, lng: -0.1870 }

        const aMap = new mapDetails.value.Map(mapDiv, {
            center: centre,
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
        setMarkerPosition, addMarker, mapDetails, markerPosition, createMap, initMap, initPlaces, map
    }
}