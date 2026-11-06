<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, useId, watch } from 'vue'
import { usePaneActivity, usePaneId } from '@/workspace/paneActivity'
import { lockBodyScroll, unlockBodyScroll } from '@/utils/bodyScrollLock'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'

/**
 * Generic modal — backdrop, ESC close, click-outside close, sticky header/footer.
 * Tělo se scrolluje, hlavička a volitelná patička zůstávají. Šířka přes
 * `widthClass` (Tailwind utility).
 */
const props = withDefaults(defineProps<{
  title: string
  widthClass?: string
}>(), {
  widthClass: 'max-w-3xl',
})

const emit = defineEmits<{
  (e: 'close'): void
}>()
const paneActive = usePaneActivity()
/** Čtečka obrazovky ohlásí dialog jeho nadpisem (aria-labelledby). */
const titleId = useId()
const paneId = usePaneId()
let scrollLocked = false

/**
 * Otevřený náhled originálu vedle formuláře (DocumentPreviewSplit) modál nezakrývá: backdrop
 * končí na jeho levé hraně a dialog se centruje do zbylé části, aby šel doklad
 * při potvrzování vizuálně kontrolovat.
 */
const previewInset = ref(0)
function measurePreview(): void {
  const preview = document.querySelector<HTMLElement>('[data-side-preview]')
  const rect = preview?.getBoundingClientRect()
  previewInset.value = rect && rect.width > 0 ? Math.max(0, window.innerWidth - rect.left) : 0
}
const backdropStyle = computed(() => previewInset.value > 0 ? { right: `${previewInset.value}px` } : undefined)

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
  if (paneActive.value && e.key === 'Escape') emit('close')
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  window.addEventListener('resize', measurePreview)
  measurePreview()
  syncBodyScrollLock(paneActive.value)
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('resize', measurePreview)
  syncBodyScrollLock(false)
})
watch(paneActive, syncBodyScrollLock)

void props
</script>

<template>
  <WorkspaceTeleport>
    <!--
      Backdrop rozostřuje pozadí a je o odstín hlubší: modál je modální, obsah
      pod ním má ustoupit. Panel přijíždí spring křivkou (.rise-in) — naskočení
      bez pohybu působí jako překreslení stránky, ne jako otevření dialogu.
    -->
    <div v-show="paneActive" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-900/45 backdrop-blur-[3px]"
      role="dialog" aria-modal="true" :aria-labelledby="titleId" :aria-hidden="paneActive ? undefined : true" :data-workspace-pane="paneId ?? undefined"
      :style="backdropStyle" @click.self="emit('close')">
      <div class="rise-in flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-xl bg-surface-raised shadow-2xl ring-1 ring-neutral-200" :class="widthClass">
        <header class="px-5 py-3.5 border-b border-neutral-200 flex items-center justify-between shrink-0">
          <h2 :id="titleId" class="text-lg font-semibold">{{ title }}</h2>
          <button type="button" @click="emit('close')" aria-label="Close"
            class="cursor-pointer w-8 h-8 inline-flex items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
          <slot />
        </div>
        <footer v-if="$slots.footer" class="shrink-0 border-t border-neutral-200 px-5 py-4">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </WorkspaceTeleport>
</template>
