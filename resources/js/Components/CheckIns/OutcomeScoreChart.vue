<script setup>
// SCRUM-37 (TT-5.2): one questionnaire's scores over time -- plain SVG (no chart library), the symptom levels as
// bands behind the line, and the same numbers as a table for screen readers.
import { computed } from 'vue'

const props = defineProps({
    measure: { type: Object, required: true }, // { key, title, maxScore, bands: [{ max, level }] }
    points: { type: Array, required: true }, // [{ date, score, level }], oldest first
})

const W = 320
const H = 140
const PAD = { left: 28, right: 16, top: 8, bottom: 22 }
const plotW = W - PAD.left - PAD.right
const plotH = H - PAD.top - PAD.bottom

const y = (score) => PAD.top + plotH - (score / props.measure.maxScore) * plotH
const x = (i) => PAD.left + (props.points.length === 1 ? plotW / 2 : (i / (props.points.length - 1)) * plotW)

// Lighter to darker, never alarming red (a level isn't a diagnosis).
const shades = ['#f1f5f9', '#e2e8f0', '#cbd5e1', '#94a3b8', '#64748b']

const bandRects = computed(() => {
    let from = 0
    return props.measure.bands.map((band, i) => {
        const rect = { y: y(band.max), h: y(from) - y(band.max), fill: shades[i] ?? shades[shades.length - 1], level: band.level }
        from = band.max
        return rect
    })
})

const path = computed(() => props.points.map((p, i) => `${i ? 'L' : 'M'}${x(i)},${y(p.score)}`).join(' '))

const step = computed(() => Math.max(1, Math.ceil(props.points.length / 6)))

function showLabel(i) {
    return i === props.points.length - 1 || i % step.value === 0
}

function anchor(i) {
    if (props.points.length === 1) return 'middle'
    return i === 0 ? 'start' : i === props.points.length - 1 ? 'end' : 'middle'
}

function long(date) {
    return new Date(date).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })
}

function short(date) {
    return new Date(date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
}
</script>

<template>
    <figure class="m-0">
        <svg :viewBox="`0 0 ${W} ${H}`" class="w-full h-auto" aria-hidden="true" focusable="false">
            <rect v-for="band in bandRects" :key="band.level" :x="PAD.left" :y="band.y" :width="plotW" :height="band.h" :fill="band.fill" />
            <text v-for="tick in [0, measure.maxScore]" :key="tick" :x="PAD.left - 4" :y="y(tick) + 3" text-anchor="end" font-size="9" fill="#475569">{{ tick }}</text>
            <path v-if="points.length > 1" :d="path" fill="none" stroke="#3730a3" stroke-width="2" />
            <circle v-for="(p, i) in points" :key="i" :cx="x(i)" :cy="y(p.score)" r="3.5" fill="#3730a3" />
            <!-- QA: the ends anchored inward (a centred last label was clipped), at most ~6 labels so they never overlap. -->
            <template v-for="(p, i) in points" :key="`d${i}`">
                <text v-if="showLabel(i)" :x="x(i)" :y="H - 6" :text-anchor="anchor(i)" font-size="10" fill="#475569">{{ short(p.date) }}</text>
            </template>
        </svg>
        <table class="sr-only">
            <caption>{{ measure.title }} scores over time</caption>
            <thead><tr><th scope="col">Date</th><th scope="col">Score (out of {{ measure.maxScore }})</th><th scope="col">Level</th></tr></thead>
            <tbody>
                <tr v-for="(p, i) in points" :key="i"><td>{{ long(p.date) }}</td><td>{{ p.score }}</td><td>{{ p.level }}</td></tr>
            </tbody>
        </table>
    </figure>
</template>
