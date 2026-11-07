<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, disabledTitle } from '@/components/ui/buttonStyles'
import { formControl } from '@/components/ui/formStyles'
import {
  captureFrame, listCameras, stopStream, webcamConstraints, webcamErrorKind, type WebcamErrorKind,
} from '@/composables/useWebcam'

/**
 * „Vyfotit“ na počítači: živý náhled z webkamery a snímek do JPEG souboru.
 * `<input capture>` na desktopu kameru nespustí, jen otevře výběr souboru, proto
 * vlastní dialog. Výsledek jde dál do ořezu dokladu stejně jako fotka z telefonu.
 * Stream se zastaví při každém odchodu z dialogu, aby kamera nezůstala svítit.
 */
const emit = defineEmits<{
  captured: [file: File]
  cancel: []
}>()

const ERROR_KEYS: Record<WebcamErrorKind, string> = {
  denied: 'capture_photo.webcam_denied',
  busy: 'capture_photo.webcam_busy',
  not_found: 'capture_photo.webcam_not_found',
  failed: 'capture_photo.webcam_failed',
}

const { t } = useI18n()
const video = ref<HTMLVideoElement | null>(null)
const cameras = ref<MediaDeviceInfo[]>([])
const cameraId = ref('')
const ready = ref(false)
const busy = ref(false)
const error = ref('')

let stream: MediaStream | null = null
/** Pořadí požadavku na kameru; starší odpověď (přepnutí kamery, zavření) se zahodí. */
let request = 0
let disposed = false

const waitReason = computed(() => (ready.value ? '' : t('capture_photo.webcam_starting')))

onMounted(() => { void start() })
onBeforeUnmount(() => {
  disposed = true
  request++
  stop()
})

function stop() {
  stopStream(stream)
  stream = null
  ready.value = false
  if (video.value) video.value.srcObject = null
}

async function start(deviceId?: string) {
  stop()
  const id = ++request
  error.value = ''
  try {
    const next = await navigator.mediaDevices.getUserMedia(webcamConstraints(deviceId))
    if (disposed || id !== request) {
      stopStream(next)
      return
    }
    stream = next
    cameraId.value = next.getVideoTracks()[0]?.getSettings?.().deviceId ?? deviceId ?? ''
    await nextTick()
    const el = video.value
    if (el) {
      el.srcObject = next
      try {
        await el.play()
      } catch { /* autoplay muted video poběží i bez play() */ }
    }
    const found = await listCameras()
    if (!disposed && id === request) cameras.value = found
  } catch (e) {
    if (disposed || id !== request) return
    error.value = t(ERROR_KEYS[webcamErrorKind(e)])
  }
}

function onCameraChange(e: Event) {
  const id = (e.target as HTMLSelectElement).value
  if (id && id !== cameraId.value) void start(id)
}

function cameraLabel(camera: MediaDeviceInfo, index: number): string {
  return camera.label || t('capture_photo.webcam_camera_n', { n: index + 1 })
}

async function capture() {
  const el = video.value
  if (!el || !ready.value || busy.value) return
  busy.value = true
  try {
    const file = await captureFrame(el)
    stop()
    emit('captured', file)
  } catch {
    error.value = t(ERROR_KEYS.failed)
    stop()
  } finally {
    busy.value = false
  }
}

function cancel() {
  stop()
  emit('cancel')
}
</script>

<template>
  <Modal :title="t('capture_photo.webcam_title')" width-class="max-w-2xl" @close="cancel">
    <div class="space-y-3">
      <div v-if="error" class="space-y-1" data-testid="webcam-error">
        <p class="text-sm text-danger-700" role="alert">{{ error }}</p>
        <p class="text-xs text-neutral-500">{{ t('capture_photo.webcam_error_hint') }}</p>
      </div>
      <template v-else>
        <div class="relative aspect-video w-full overflow-hidden rounded-md border border-neutral-200 bg-neutral-900">
          <video ref="video" class="absolute inset-0 h-full w-full object-contain" autoplay muted playsinline
            data-testid="webcam-video" @loadedmetadata="ready = true" />
          <p v-if="!ready" class="absolute inset-0 flex items-center justify-center text-sm text-neutral-200">
            {{ t('capture_photo.webcam_starting') }}
          </p>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <label v-if="cameras.length > 1" class="inline-flex items-center gap-2 text-sm text-neutral-700">
            {{ t('capture_photo.webcam_camera') }}
            <select :class="formControl" :value="cameraId" data-testid="webcam-camera" @change="onCameraChange">
              <option v-for="(camera, index) in cameras" :key="camera.deviceId || index" :value="camera.deviceId">
                {{ cameraLabel(camera, index) }}
              </option>
            </select>
          </label>
          <p class="text-xs text-neutral-500">{{ t('capture_photo.webcam_hint') }}</p>
        </div>
      </template>
    </div>
    <template #footer>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" data-testid="webcam-cancel" @click="cancel">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          {{ error ? t('capture_photo.webcam_close') : t('capture_photo.cancel') }}
        </button>
        <template v-if="!error">
          <button type="button" :class="btnFilled('primary')" :disabled="!ready || busy"
            :title="disabledTitle(!ready, waitReason)" data-testid="webcam-capture" @click="capture">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            {{ busy ? t('capture_photo.processing') : t('capture_photo.button') }}
          </button>
        </template>
      </div>
    </template>
  </Modal>
</template>
