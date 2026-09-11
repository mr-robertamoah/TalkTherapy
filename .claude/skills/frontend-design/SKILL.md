---
name: frontend-design
description: Design/code-quality checklist for TalkTherapy's Vue + Tailwind frontend -- flags "AI-glossy" patterns (glassmorphism, gratuitous gradients, decorative blur) and gives the plain, solid alternative this codebase actually uses. Load before writing new UI, before reviewing a UI diff, or whenever a UI change looks or feels "off"/generic.
---

# TalkTherapy frontend design rules

TalkTherapy has no custom Tailwind theme (default palette, default spacing, Figtree font) and no
written design system. That's fine -- the goal here isn't to invent one, it's to stay consistent
with how the *majority* of the existing app already looks (flat, solid-color cards, `shadow-sm`/
`shadow-lg` + `border-gray-100/200` for depth) and stop a few outlier components from drifting into
a generic "AI SaaS template" look that reads as unreviewed/vibe-coded.

## The tell: decorative glassmorphism

The single most common "this looks AI-generated" signal in this codebase has been **translucent
backgrounds + backdrop blur used as decoration** -- a frosted-glass chip, pill, or card floating
over an image or gradient. Concretely, watch for this combination:

```html
<!-- BAD: glassy decoration -->
<div class="bg-white/20 backdrop-blur-sm rounded-full ...">
<div class="bg-white/95 backdrop-blur-sm rounded-lg shadow-lg ...">
<span class="bg-white/30 ...">group</span>
```

**Fix**: make it solid. Depth comes from `shadow-sm`/`shadow-md`/`shadow-lg` and a `border-gray-100`
or `border-gray-200`, not from transparency layered on blur.

```html
<!-- GOOD: solid, flat -->
<div class="bg-white rounded-full ...">
<div class="bg-white rounded-lg shadow-lg border border-gray-200 ...">
<span class="bg-white text-gray-900 ...">group</span>
```

If an icon/text inside the chip was relying on `currentColor` inheriting a light color (`text-white`)
because it sat on a translucent-over-dark background, give it an explicit color once the background
goes solid (e.g. `text-indigo-600` on a solid white circle) -- don't leave it invisible.

## Not every blur/translucency is the problem

A **functional dimming scrim behind a modal or popover** (click-outside-to-close, focus an
overlay) is a normal, load-bearing UI pattern -- it is not what makes something look vibe-coded.
The tell is specifically the *blur*, not the dim itself.

```html
<!-- Overlay dim: fine to keep, this is functional -->
<div class="bg-black/30 fixed inset-0" @click="close">

<!-- Overlay dim + blur: drop the blur, keep the dim -->
<div class="bg-gray-900/50 fixed inset-0 flex items-center justify-center"> <!-- no backdrop-blur-* -->
```

Also fine, leave alone:
- Hover-reveal overlays with no transparency gradient trickery (`bg-black/0 group-hover:bg-black/60`
  on an image for an upload/camera affordance) -- standard, not a glass effect.
- A `bg-gradient-to-t from-black/60 to-transparent` scrim under text on a photo, for legibility --
  functional contrast, not decorative glass. Don't add `backdrop-blur` to it, but the gradient
  itself is fine.

## Gotcha: overriding `StyledLink.vue`'s classes doesn't work

`StyledLink.vue` hardcodes `bg-gray-800 ... text-white ... hover:bg-gray-700` directly on its root
`<Link>`. When a caller passes a conflicting `class` prop (e.g. `bg-white text-gray-800`), Vue's
class-fallthrough merge just concatenates both class strings onto the same element -- it does not
let the passed-in class win. Which utility actually applies is then decided by Tailwind's
*generated stylesheet order* (roughly alphabetical-by-color internally), not by attribute order,
so the result is unpredictable and has previously produced literally invisible text (white text on
a white background that looked fine in the class list but wasn't what actually rendered).

**Fix**: don't fight `StyledLink`'s own colors. If you need a fully custom-styled link (a pill,
an outline button, anything that isn't its default dark uppercase style), use a plain `Link` from
`@inertiajs/vue3` directly with your own complete class list -- exactly what
`MiniTherapyComponent.vue`/`MiniGroupTherapyComponent.vue` already do. Only pass layout-only
classes (`shrink-0`, `float-right`, margins) to `StyledLink` itself.

This is a general rule, not just about this one component: **when overriding a child component's
classes via a `class` prop, check whether the child hardcodes a conflicting utility for the same
CSS property on its root element.** If it does, the override is not guaranteed to win — verify the
actual rendered result (browser devtools or a screenshot), don't just trust the class list you wrote.

## Quick checklist before calling UI work done

1. `grep -rn "backdrop-blur" resources/js` on any files you touched -- if you added one, ask
   whether it's dimming a modal (keep, no blur) or decorating a chip/card (remove, go solid).
2. `grep -rn "bg-white/[0-9]\|bg-black/[0-9]\|bg-opacity-" resources/js` on files you touched --
   translucent color on a card, badge, pill, or icon chip should almost always be a solid color
   plus `shadow-*`/`border-*` instead.
3. Don't introduce a new gradient hero/banner (`bg-gradient-to-*`) as a default choice for a new
   page section just because it "looks nice" -- most of the app uses plain `bg-white` cards with a
   border and shadow. If a gradient banner already exists somewhere you're editing, you don't need
   to rip it out unasked (that's scope creep), but don't add new ones as your own default.
4. When copying an existing component as a starting point, check it isn't one of the outliers this
   skill exists to catch -- grep it for `backdrop-blur`/translucent-`bg-*` before treating it as the
   pattern to follow.

## Why this matters here specifically

TalkTherapy is a mental-health platform -- the UI needs to read as calm, legible, and considered,
not like a generic marketing-site template. Decorative glass/blur is exactly the kind of default an
LLM reaches for when asked for "modern" styling with no other constraints; catching it here keeps
the app's actual, already-established look (plain cards, clear borders, real shadows) consistent
instead of drifting component-by-component.
