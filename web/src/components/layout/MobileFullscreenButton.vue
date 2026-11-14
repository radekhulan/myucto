<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const available = ref(false)
const active = ref(false)

function updateFullscreenState() {
  active.value = document.fullscreenElement === document.documentElement
  available.value = document.fullscreenEnabled && typeof document.documentElement.requestFullscreen === 'function'
}

onMounted(() => {
  updateFullscreenState()
  document.addEventListener('fullscreenchange', updateFullscreenState)
})

onBeforeUnmount(() => document.removeEventListener('fullscreenchange', updateFullscreenState))

async function toggleFullscreen() {
  try {
    if (active.value) await document.exitFullscreen()
    else await document.documentElement.requestFullscreen({ navigationUI: 'hide' })
  } catch {
    toast.error(t('nav.fullscreen_failed'))
  }
}
</script>

<template>
  <button
    v-if="available"
    type="button"
    class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full border border-neutral-200 text-neutral-700 hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-primary-400"
    :title="t(active ? 'nav.fullscreen_exit' : 'nav.fullscreen_enter')"
    :aria-label="t(active ? 'nav.fullscreen_exit' : 'nav.fullscreen_enter')"
    :aria-pressed="active"
    @click="toggleFullscreen"
  >
    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path :d="active ? 'M4 9h5V4m6 0v5h5M4 15h5v5m6 0v-5h5' : 'M9 4H4v5m11-5h5v5M4 15v5h5m6 0h5v-5'" />
    </svg>
  </button>
</template>
