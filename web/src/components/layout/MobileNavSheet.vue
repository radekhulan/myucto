<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import WorkspaceNavLink from '@/components/workspace/WorkspaceNavLink.vue'

/**
 * Nabídka agendy nad spodní lištou telefonu. Nahoře velké dlaždice s nejčastějšími
 * kroky (vystavit fakturu, vyfotit doklad...), pod nimi všechny položky menu dané
 * sekce. Položky přebírá z hlavního menu i s jeho filtrem oprávnění, takže nabídka
 * nemůže ukázat nic, co by uživatel v menu neviděl.
 */
export interface SheetAction {
  key: string
  label: string
  hint?: string
  icon: string
  /** Cíl dlaždice. Bez něj je dlaždice tlačítkem fotoaparátu (`capture`). */
  to?: string
  /** Dlaždice otevře fotoaparát a fotku vydá událostí `capture`. */
  capture?: boolean
}

export interface SheetLink {
  to: string
  label: string
  icon: string
  active: boolean
  external?: boolean
  badge?: number
}

type Accent = 'primary' | 'warning' | 'success'

defineProps<{
  title: string
  accent: Accent
  actions: SheetAction[]
  links: SheetLink[]
  closeLabel: string
  linksLabel: string
}>()
const emit = defineEmits<{ close: []; capture: [file: File] }>()

const ICON_TINT: Record<Accent, string> = {
  primary: 'bg-primary-500/12 text-primary-700',
  warning: 'bg-warning-500/15 text-warning-600',
  success: 'bg-success-500/15 text-success-600',
}
const ICON_FILLED: Record<Accent, string> = {
  primary: 'bg-primary-600 text-white',
  warning: 'bg-warning-500 text-white',
  success: 'bg-success-600 text-white',
}
const DOT: Record<Accent, string> = {
  primary: 'bg-primary-400',
  warning: 'bg-warning-500',
  success: 'bg-success-500',
}

function onPick(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  input.value = ''
  if (file) emit('capture', file)
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))

const tileClass = 'flex min-h-[5.5rem] cursor-pointer flex-col items-start justify-between gap-2 rounded-2xl border border-neutral-200 bg-surface p-3 text-left shadow-sm transition-colors active:bg-neutral-100'
</script>

<template>
  <section
    class="flex max-h-full flex-col rounded-t-3xl border-t border-neutral-200 bg-neutral-50 shadow-[0_-12px_32px_rgba(21,19,29,0.22)]"
    role="dialog"
    :aria-label="title"
    data-testid="mobile-nav-sheet"
  >
    <div class="flex shrink-0 items-center gap-2 px-4 pb-2 pt-3">
      <span class="h-2 w-2 shrink-0 rounded-full" :class="DOT[accent]" aria-hidden="true"></span>
      <h2 class="flex-1 text-base font-semibold text-neutral-900">{{ title }}</h2>
      <button
        type="button"
        class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full text-neutral-500 hover:bg-neutral-100"
        :aria-label="closeLabel"
        @click="emit('close')"
      >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-4">
      <slot />
      <div v-if="actions.length" class="grid grid-cols-2 gap-2.5">
        <template v-for="(action, index) in actions" :key="action.key">
          <label v-if="action.capture" :class="tileClass" :data-action="action.key">
            <input type="file" accept="image/*" capture="environment" class="hidden" @change="onPick" />
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl" :class="index === 0 ? ICON_FILLED[accent] : ICON_TINT[accent]">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="action.icon" />
              </svg>
            </span>
            <span class="min-w-0">
              <span class="block text-sm font-semibold leading-tight text-neutral-900">{{ action.label }}</span>
              <span v-if="action.hint" class="mt-0.5 block text-xs leading-tight text-neutral-500">{{ action.hint }}</span>
            </span>
          </label>
          <WorkspaceNavLink v-else :to="action.to!" fresh :class="tileClass" :data-action="action.key" @click="emit('close')">
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl" :class="index === 0 ? ICON_FILLED[accent] : ICON_TINT[accent]">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="action.icon" />
              </svg>
            </span>
            <span class="min-w-0">
              <span class="block text-sm font-semibold leading-tight text-neutral-900">{{ action.label }}</span>
              <span v-if="action.hint" class="mt-0.5 block text-xs leading-tight text-neutral-500">{{ action.hint }}</span>
            </span>
          </WorkspaceNavLink>
        </template>
      </div>

      <h3 v-if="links.length" class="mb-1.5 mt-4 px-1 text-[11px] font-bold uppercase tracking-wider text-neutral-500">{{ linksLabel }}</h3>
      <div class="grid grid-cols-2 gap-1.5">
        <template v-for="link in links" :key="link.to">
          <a
            v-if="link.external"
            :href="link.to"
            target="_blank"
            rel="noopener"
            class="flex min-h-12 items-center gap-2.5 rounded-xl px-3 py-2 text-sm leading-tight text-neutral-700 active:bg-neutral-100"
          >
            <svg class="h-5 w-5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" />
            </svg>
            <span class="min-w-0">{{ link.label }}</span>
          </a>
          <WorkspaceNavLink
            v-else
            :to="link.to"
            class="flex min-h-12 items-center gap-2.5 rounded-xl px-3 py-2 text-sm leading-tight transition-colors"
            :class="link.active ? 'bg-primary-50 font-medium text-primary-700' : 'bg-surface text-neutral-700 active:bg-neutral-100'"
            @click="emit('close')"
          >
            <svg class="h-5 w-5 shrink-0" :class="link.active ? 'text-primary-600' : 'text-neutral-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" />
            </svg>
            <span class="min-w-0 flex-1">{{ link.label }}</span>
            <span
              v-if="link.badge"
              class="min-w-5 rounded-full bg-warning-500/20 px-1.5 py-0.5 text-center text-[10px] font-semibold text-warning-600"
            >{{ link.badge }}</span>
          </WorkspaceNavLink>
        </template>
      </div>
    </div>
  </section>
</template>
