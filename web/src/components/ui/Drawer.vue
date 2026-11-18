<script setup lang="ts">
import { onMounted, onBeforeUnmount, watch } from 'vue'
import { usePaneActivity, usePaneId } from '@/workspace/paneActivity'
import { lockBodyScroll, unlockBodyScroll } from '@/utils/bodyScrollLock'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'
import { closesOnEscape, pushModal, removeModal } from './modalStack'

/**
 * Generic right-anchored drawer — sourozenec Modal.vue se stejným kontraktem
 * (title, widthClass, emit close, ESC, scroll-lock, Teleport, backdrop click).
 * Liší se jen ukotvením vpravo a slide-in animací.
 *
 * Používej vždy s `v-if` na volajícím místě — mount/unmount řídí transition
 * i registraci ESC listeneru.
 */
const props = withDefaults(defineProps<{
  title: string
  subtitle?: string | null
  widthClass?: string
}>(), {
  subtitle: null,
  widthClass: 'max-w-xl',
})

const emit = defineEmits<{
  (e: 'close'): void
}>()
const paneActive = usePaneActivity()
const paneId = usePaneId()
let scrollLocked = false
/** Drawer je v pořadí oken jako Modal: Escape zavře jen nejvrchnější a ne, když ho spotřeboval prvek uvnitř. */
const stackId = Symbol('drawer')
pushModal(stackId, () => paneId.value, () => paneActive.value)

function syncBodyScrollLock(active: boolean): void {
  if (active && !scrollLocked) {
    lockBodyScroll()
    scrollLocked = true
  } else if (!active && scrollLocked) {
    unlockBodyScroll()
    scrollLocked = false
  }
}

function onKey(e: KeyboardEvent) {
  if (paneActive.value && e.key === 'Escape' && closesOnEscape(stackId, e)) emit('close')
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  syncBodyScrollLock(paneActive.value)
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  removeModal(stackId)
  syncBodyScrollLock(false)
})
watch(paneActive, syncBodyScrollLock)

void props
</script>

<template>
  <WorkspaceTeleport>
    <div v-show="paneActive" class="fixed inset-0 z-50 bg-neutral-900/45 backdrop-blur-[3px]"
      :aria-hidden="paneActive ? undefined : true" :data-workspace-pane="paneId ?? undefined" @click.self="emit('close')">
      <!-- Vjezd spring křivkou (--ease-spring): panel dojede a nepatrně dosedne,
           místo aby lineárně dokloužl. Odjezd je kratší a lineární — zavírání
           nemá na co čekat. -->
      <transition
        appear
        enter-active-class="transition-transform duration-300 ease-[cubic-bezier(0.22,1.2,0.36,1)]"
        enter-from-class="translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="transition-transform duration-150 ease-in"
        leave-from-class="translate-x-0"
        leave-to-class="translate-x-full"
      >
        <aside
          class="ml-auto flex h-full w-full flex-col bg-surface-raised shadow-2xl ring-1 ring-neutral-200"
          :class="widthClass"
          role="dialog"
          aria-modal="true"
        >
          <header class="flex items-start justify-between gap-4 border-b border-neutral-200 px-5 py-3 shrink-0">
            <div class="min-w-0">
              <h2 class="text-lg font-semibold truncate">{{ title }}</h2>
              <p v-if="subtitle" class="text-sm text-neutral-500 truncate">{{ subtitle }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <slot name="header-actions" />
              <button type="button" @click="emit('close')" aria-label="Close"
                class="cursor-pointer w-8 h-8 inline-flex items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </header>
          <div class="flex-1 overflow-y-auto p-5">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="border-t border-neutral-200 px-5 py-3 shrink-0">
            <slot name="footer" />
          </footer>
        </aside>
      </transition>
    </div>
  </WorkspaceTeleport>
</template>
