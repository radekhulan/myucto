<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import PhotoCropDialog from '@/components/upload/PhotoCropDialog.vue'
import WebcamCaptureDialog from '@/components/upload/WebcamCaptureDialog.vue'
import { isCaptureDevice } from '@/composables/usePhotoCapture'
import { hasCamera, supportsWebcam } from '@/composables/useWebcam'

/**
 * Tlačítko „Vyfotit“: na telefonu a tabletu otevře fotoaparát (input capture),
 * na počítači s připojenou kamerou živý náhled z webkamery
 * ({@link WebcamCaptureDialog}). Vyfocenou fotku pošle do {@link PhotoCropDialog},
 * kde se navrhne ořez dokladu, narovná se a zkomprimuje na JPEG (~150 kB).
 * Výsledek předá stávající upload funkci stránky přes událost `captured`.
 * Na počítači bez kamery se neukazuje; kamera se zjišťuje výčtem zařízení,
 * o oprávnění se žádá až po kliknutí.
 */
withDefaults(defineProps<{
  disabled?: boolean
  /** `filled` pro hlavní akci, jinak outline. */
  filled?: boolean
}>(), { disabled: false, filled: false })

const emit = defineEmits<{ captured: [file: File] }>()

const { t } = useI18n()
const input = ref<HTMLInputElement | null>(null)
const touch = ref(false)
const webcam = ref(false)
const visible = computed(() => touch.value || webcam.value)
const webcamOpen = ref(false)
/** Fotka v ořezu; dokud je nastavená, je dialog otevřený. */
const photo = ref<File | null>(null)

let mql: MediaQueryList | null = null
const refreshTouch = () => { touch.value = isCaptureDevice() }
const refreshWebcam = async () => { webcam.value = await hasCamera() }

onMounted(() => {
  refreshTouch()
  mql = window.matchMedia?.('(pointer: coarse)') ?? null
  mql?.addEventListener?.('change', refreshTouch)
  void refreshWebcam()
  if (supportsWebcam()) navigator.mediaDevices.addEventListener?.('devicechange', refreshWebcam)
})
onBeforeUnmount(() => {
  mql?.removeEventListener?.('change', refreshTouch)
  if (supportsWebcam()) navigator.mediaDevices.removeEventListener?.('devicechange', refreshWebcam)
})

function trigger() {
  if (touch.value) input.value?.click()
  else webcamOpen.value = true
}

function onPick(e: Event) {
  const el = e.target as HTMLInputElement
  const file = el.files?.[0] ?? null
  el.value = ''
  if (file) photo.value = file
}

function onWebcamCaptured(file: File) {
  webcamOpen.value = false
  photo.value = file
}

function onDone(file: File) {
  photo.value = null
  emit('captured', file)
}

function onRetake() {
  photo.value = null
  trigger()
}
</script>

<template>
  <span v-if="visible" class="contents">
    <button type="button" :class="filled ? btnFilled('primary') : btnOutline('neutral')" :disabled="disabled"
      data-testid="capture-photo" @click="trigger">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      {{ t('capture_photo.button') }}
    </button>
    <input ref="input" type="file" accept="image/*" capture="environment" class="hidden" @change="onPick" />

    <WebcamCaptureDialog v-if="webcamOpen" @captured="onWebcamCaptured" @cancel="webcamOpen = false" />
    <PhotoCropDialog v-if="photo" :file="photo" allow-retake
      @done="onDone" @retake="onRetake" @cancel="photo = null" />
  </span>
</template>
