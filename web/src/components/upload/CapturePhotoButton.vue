<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import {
  CROP_WORK_MAX_SIDE, CROPPED_MAX_SIDE, compressPhoto, computeTargetSize, decodePhoto,
  isCaptureDevice, pixelsSource, type PhotoSource,
} from '@/composables/usePhotoCapture'
import {
  DETECT_MAX_SIDE, detectDocumentQuad, isConvexQuad, rectifiedSize, scaleQuad, warpQuad, type Quad,
} from '@/composables/documentCrop'

/**
 * Tlačítko „Vyfotit“: otevře fotoaparát (input capture), na fotce najde doklad
 * a navrhne ořez (rohy jdou posunout), doklad narovná a zkomprimuje na JPEG
 * (~150 kB). Bez nalezeného dokladu nebo s vypnutým ořezem jde celá fotka
 * (max 1200 px) jako dosud. Výsledek předá stávající upload funkci stránky
 * přes událost `captured`. Na zařízeních bez dotykového ovládání se neukazuje.
 */
withDefaults(defineProps<{
  disabled?: boolean
  /** `filled` pro hlavní akci, jinak outline. */
  filled?: boolean
}>(), { disabled: false, filled: false })

const emit = defineEmits<{ captured: [file: File] }>()

const PREVIEW_MAX_SIDE = 1000
/** Výchozí ručně vyznačený ořez, když se doklad nenašel (10 % od okrajů). */
const DEFAULT_QUAD: Quad = [{ x: 0.1, y: 0.1 }, { x: 0.9, y: 0.1 }, { x: 0.9, y: 0.9 }, { x: 0.1, y: 0.9 }]

const { t } = useI18n()
const input = ref<HTMLInputElement | null>(null)
const frame = ref<HTMLElement | null>(null)
const visible = ref(false)
const open = ref(false)
const busy = ref(false)
const error = ref('')
const original = ref<File | null>(null)
const previewUrl = ref('')
const aspect = ref(1)
/** Rohy ořezu v poměrných souřadnicích fotky (0–1). */
const quad = ref<Quad>(DEFAULT_QUAD)
const detected = ref(false)
const cropOn = ref(false)
const dragging = ref<number | null>(null)

let source: PhotoSource | null = null
let mql: MediaQueryList | null = null
const refreshVisible = () => { visible.value = isCaptureDevice() }

onMounted(() => {
  refreshVisible()
  mql = window.matchMedia?.('(pointer: coarse)') ?? null
  mql?.addEventListener?.('change', refreshVisible)
})
onBeforeUnmount(() => {
  mql?.removeEventListener?.('change', refreshVisible)
  revoke()
  closeSource()
})

function revoke() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
}

function closeSource() {
  source?.close?.()
  source = null
}

function reset() {
  revoke()
  closeSource()
  open.value = false
  busy.value = false
  error.value = ''
  original.value = null
  quad.value = DEFAULT_QUAD
  detected.value = false
  cropOn.value = false
  dragging.value = null
}

function trigger() {
  input.value?.click()
}

const ready = computed(() => !!previewUrl.value && !error.value)

const frameStyle = computed(() => ({
  aspectRatio: String(aspect.value),
  width: `min(100%, calc(60vh * ${aspect.value}))`,
}))

/** Ztmavení mimo ořez: celý rámeček minus čtyřúhelník (evenodd). */
const shadePath = computed(() => {
  const q = quad.value
  return `M0 0H1V1H0Z M${q[0].x} ${q[0].y}L${q[1].x} ${q[1].y}L${q[2].x} ${q[2].y}L${q[3].x} ${q[3].y}Z`
})
const cropHint = computed(() => {
  if (cropOn.value) return t(detected.value ? 'capture_photo.crop_hint' : 'capture_photo.crop_manual_hint')
  return t(detected.value ? 'capture_photo.crop_off_hint' : 'capture_photo.crop_not_found')
})
const quadPoints = computed(() => quad.value.map(p => `${p.x},${p.y}`).join(' '))

async function onPick(e: Event) {
  const el = e.target as HTMLInputElement
  const file = el.files?.[0] ?? null
  el.value = ''
  if (!file) return
  reset()
  original.value = file
  busy.value = true
  open.value = true
  try {
    source = await decodePhoto(file)
    aspect.value = source.width / source.height
    const display = computeTargetSize(source.width, source.height, PREVIEW_MAX_SIDE)
    const canvas = source.createCanvas(display.width, display.height)
    canvas.draw(display.width, display.height)
    const blob = await canvas.toBlob('image/jpeg', 0.8)
    if (!blob) throw new Error('preview')
    previewUrl.value = URL.createObjectURL(blob)
    detect(source)
  } catch {
    closeSource()
    error.value = t('capture_photo.decode_failed')
  } finally {
    busy.value = false
  }
}

function detect(src: PhotoSource) {
  if (!src.pixels) return
  try {
    const size = computeTargetSize(src.width, src.height, DETECT_MAX_SIDE)
    const found = detectDocumentQuad(src.pixels(size.width, size.height))
    if (found) {
      quad.value = scaleQuad(found, 1 / size.width, 1 / size.height)
      detected.value = true
      cropOn.value = true
    }
  } catch { /* bez návrhu ořezu, celá fotka */ }
}

function toggleCrop() {
  cropOn.value = !cropOn.value
}

function onHandleDown(index: number, e: PointerEvent) {
  dragging.value = index
  ;(e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId)
}

function onHandleMove(index: number, e: PointerEvent) {
  if (dragging.value !== index || !frame.value) return
  const rect = frame.value.getBoundingClientRect()
  const x = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width))
  const y = Math.min(1, Math.max(0, (e.clientY - rect.top) / rect.height))
  const next = quad.value.map((p, i) => (i === index ? { x, y } : p)) as Quad
  // Překřížený nebo zdegenerovaný čtyřúhelník nejde narovnat — takový posun se nepřijme.
  if (isConvexQuad(next)) quad.value = next
}

function onHandleUp(e: PointerEvent) {
  dragging.value = null
  ;(e.currentTarget as HTMLElement).releasePointerCapture?.(e.pointerId)
}

/** Narovnaný výřez dokladu, nebo celá fotka; chyba narovnání spadne na celou fotku. */
async function buildFile(src: PhotoSource): Promise<File> {
  if (cropOn.value && src.pixels) {
    try {
      const work = computeTargetSize(src.width, src.height, CROP_WORK_MAX_SIDE)
      const q = scaleQuad(quad.value, work.width, work.height)
      const size = rectifiedSize(q, CROPPED_MAX_SIDE)
      const warped = warpQuad(src.pixels(work.width, work.height), q, size.width, size.height)
      return await compressPhoto(pixelsSource(warped), { maxSide: CROPPED_MAX_SIDE })
    } catch { /* celá fotka níže */ }
  }
  return compressPhoto(src)
}

async function confirm() {
  if (!source || busy.value) return
  busy.value = true
  try {
    const file = await buildFile(source)
    reset()
    emit('captured', file)
  } catch {
    busy.value = false
    error.value = t('capture_photo.decode_failed')
  }
}

function uploadOriginal() {
  if (!original.value) return
  const file = original.value
  reset()
  emit('captured', file)
}

function retake() {
  reset()
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

    <Modal v-if="open" :title="t('capture_photo.title')" width-class="max-w-md" @close="reset">
      <div class="space-y-3">
        <p v-if="busy && !ready" class="text-sm text-neutral-600">{{ t('capture_photo.processing') }}</p>
        <template v-else-if="error">
          <p class="text-sm text-danger-700" role="alert">{{ error }}</p>
          <p class="text-xs text-neutral-500">{{ t('capture_photo.fallback_hint') }}</p>
        </template>
        <template v-else-if="ready">
          <div ref="frame" class="relative mx-auto select-none touch-none rounded-md border border-neutral-200 bg-neutral-50 overflow-hidden"
            :style="frameStyle" data-testid="capture-photo-frame">
            <img :src="previewUrl" :alt="t('capture_photo.title')" class="absolute inset-0 w-full h-full" draggable="false" />
            <template v-if="cropOn">
              <svg class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 1 1" preserveAspectRatio="none" aria-hidden="true">
                <path :d="shadePath" fill="black" fill-opacity="0.45" fill-rule="evenodd" />
                <polygon :points="quadPoints" fill="none" stroke="white" stroke-width="2" vector-effect="non-scaling-stroke" />
              </svg>
              <button v-for="(point, index) in quad" :key="index" type="button"
                class="absolute w-9 h-9 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-primary-600/80 shadow touch-none cursor-grab"
                :class="{ 'scale-110 cursor-grabbing': dragging === index }"
                :style="{ left: `${point.x * 100}%`, top: `${point.y * 100}%` }"
                :aria-label="t('capture_photo.corner', { n: index + 1 })"
                :data-testid="`capture-photo-corner-${index}`"
                @pointerdown.prevent="onHandleDown(index, $event)"
                @pointermove="onHandleMove(index, $event)"
                @pointerup="onHandleUp"
                @pointercancel="onHandleUp" />
            </template>
          </div>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
              <input type="checkbox" :checked="cropOn" data-testid="capture-photo-crop" @change="toggleCrop" />
              {{ t('capture_photo.crop_toggle') }}
            </label>
            <p class="text-xs text-neutral-500">{{ cropHint }}</p>
          </div>
        </template>
      </div>
      <template #footer>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="reset">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            {{ t('capture_photo.cancel') }}
          </button>
          <button type="button" :class="btnOutline('warning')" :disabled="busy" @click="retake">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M5.6 15A8 8 0 0018.4 9M18.4 9A8 8 0 005.6 15" /></svg>
            {{ t('capture_photo.retake') }}
          </button>
          <button v-if="error && original" type="button" :class="btnFilled('primary')" @click="uploadOriginal">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" /></svg>
            {{ t('capture_photo.upload_original') }}
          </button>
          <button v-else type="button" :class="btnFilled('success')" :disabled="busy || !ready" data-testid="capture-photo-confirm" @click="confirm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ busy ? t('capture_photo.processing') : t('capture_photo.upload') }}
          </button>
        </div>
      </template>
    </Modal>
  </span>
</template>
