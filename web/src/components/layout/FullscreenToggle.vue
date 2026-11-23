<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const fullscreenDocument = document as Document & {
  webkitFullscreenElement?: Element | null
  webkitFullscreenEnabled?: boolean
  webkitExitFullscreen?: () => Promise<void> | void
}
const root = document.documentElement as HTMLElement & {
  webkitRequestFullscreen?: () => Promise<void> | void
}
const supported = ref(false)
const active = ref(false)
const busy = ref(false)
const label = computed(() => t(!supported.value ? 'nav.mobile.fullscreen_unsupported'
  : active.value ? 'nav.mobile.fullscreen_exit' : 'nav.mobile.fullscreen'))

function sync() {
  active.value = !!(document.fullscreenElement || fullscreenDocument.webkitFullscreenElement)
}

function supportsNative() {
  return typeof root.requestFullscreen === 'function' && typeof document.exitFullscreen === 'function'
    && document.fullscreenEnabled !== false
}

async function toggle() {
  if (!supported.value || busy.value) return
  busy.value = true
  try {
    if (document.fullscreenElement) await document.exitFullscreen()
    else if (fullscreenDocument.webkitFullscreenElement) await fullscreenDocument.webkitExitFullscreen?.()
    else if (supportsNative()) await root.requestFullscreen()
    else await root.webkitRequestFullscreen?.()
    sync()
  } catch {
    toast.error(t('nav.mobile.fullscreen_failed'))
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  supported.value = !!(supportsNative()
    || (root.webkitRequestFullscreen && fullscreenDocument.webkitExitFullscreen && fullscreenDocument.webkitFullscreenEnabled !== false))
  sync()
  document.addEventListener('fullscreenchange', sync)
  document.addEventListener('webkitfullscreenchange', sync)
})
onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', sync)
  document.removeEventListener('webkitfullscreenchange', sync)
})
</script>

<template>
  <button
    type="button"
    :title="label"
    :aria-label="label"
    :aria-pressed="active"
    :disabled="!supported || busy"
    class="cursor-pointer h-9 w-10 shrink-0 inline-flex items-center justify-center rounded-md border border-neutral-200 text-neutral-500 hover:bg-neutral-50 hover:text-neutral-700 disabled:cursor-default disabled:opacity-50"
    @click="toggle"
  >
    <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path v-if="active" stroke-linecap="round" stroke-linejoin="round" d="M9 3v6H3m12-6v6h6M3 15h6v6m12-6h-6v6" />
      <path v-else stroke-linecap="round" stroke-linejoin="round" d="M9 3H3v6m12-6h6v6M3 15v6h6m12-6v6h-6" />
    </svg>
  </button>
</template>
