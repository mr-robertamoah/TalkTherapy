// SCRUM-399: urgent help alerts on this device (web push). Off until the counsellor turns it on here; the
// browser's permission prompt only follows that click. Best effort: the in-app ring and the administrators'
// alerts stay the safety net.
import { ref } from 'vue'
import axios from 'axios'

const info = ref({ enabled: false, publicKey: null, devices: [] })
const thisDeviceOn = ref(false)
const busy = ref(false)
const error = ref('')

export function pushSupported() {
    return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

// iPhone and iPad deliver web push only to a web app added to the Home Screen (iOS 16.4+).
export function needsHomeScreen() {
    if (typeof navigator === 'undefined') return false
    const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    const standalone = window.navigator.standalone === true || window.matchMedia?.('(display-mode: standalone)').matches
    return ios && !standalone
}

function keyBytes(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4)
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)))
}

function deviceLabel() {
    const ua = navigator.userAgent
    const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser'
    const system = /Android/.test(ua) ? 'Android' : /iPhone|iPad|iPod/.test(ua) ? 'iPhone or iPad' : /Mac OS X/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : 'device'
    return `${browser} on ${system}`
}

async function currentSubscription() {
    if (!pushSupported()) return null
    const registration = await navigator.serviceWorker.getRegistration('/')
    return registration ? registration.pushManager.getSubscription() : null
}

// The server knows this browser only by its endpoint's SHA-256 -- endpoints are never sent back.
async function endpointHash(endpoint) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint))
    return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('')
}

async function load() {
    error.value = ''
    const subscription = await currentSubscription().catch(() => null)
    const device = subscription ? await endpointHash(subscription.endpoint).catch(() => '') : ''
    await axios.get(route('push.subscriptions.index'), { params: device ? { device } : {} }).then((res) => { info.value = res.data }).catch(() => {})
    thisDeviceOn.value = !!subscription && info.value.devices.some((entry) => entry.current)
}

// Signing out: this browser stops being alerted for this account. Called before every sign-out (app.js); never
// throws, so signing out always goes ahead.
export async function forgetThisDevice() {
    try {
        const subscription = await currentSubscription()
        if (!subscription) return
        await axios.post(route('push.subscriptions.destroy_current'), { endpoint: subscription.endpoint }, { timeout: 4000 }).catch(() => {})
        await subscription.unsubscribe().catch(() => {})
    } catch {
        // Nothing to forget, or no push here.
    }
}

async function enable() {
    if (!pushSupported() || !info.value.publicKey) return
    busy.value = true
    error.value = ''
    try {
        const permission = await Notification.requestPermission()
        if (permission !== 'granted') {
            error.value = 'Alerts are blocked for this site. Allow notifications in your browser settings, then try again.'
            return
        }
        const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' })
        await navigator.serviceWorker.ready
        const subscription = (await registration.pushManager.getSubscription())
            ?? await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(info.value.publicKey) })
        const json = subscription.toJSON()
        const res = await axios.post(route('push.subscriptions.store'), {
            endpoint: json.endpoint,
            keys: json.keys,
            contentEncoding: (window.PushManager.supportedContentEncodings ?? ['aes128gcm'])[0],
            label: deviceLabel(),
        })
        info.value = res.data
        thisDeviceOn.value = true
    } catch (err) {
        error.value = err.response?.data?.message ?? 'Alerts could not be turned on for this device.'
    } finally {
        busy.value = false
    }
}

async function disable() {
    busy.value = true
    error.value = ''
    try {
        const subscription = await currentSubscription()
        if (subscription) {
            await axios.post(route('push.subscriptions.destroy_current'), { endpoint: subscription.endpoint }).catch(() => {})
            await subscription.unsubscribe().catch(() => {})
        }
        thisDeviceOn.value = false
        await load()
    } finally {
        busy.value = false
    }
}

async function removeDevice(id) {
    await axios.delete(route('push.subscriptions.destroy', { device: id }))
        .then((res) => { info.value = res.data })
        .catch(() => {})
    const removedThis = !info.value.devices.some((device) => device.current)
    if (removedThis && thisDeviceOn.value) {
        await (await currentSubscription())?.unsubscribe().catch(() => {})
        thisDeviceOn.value = false
    }
}

export default function useWebPush() {
    return { info, thisDeviceOn, busy, error, load, enable, disable, removeDevice }
}
