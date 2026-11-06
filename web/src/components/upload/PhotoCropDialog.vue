<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import {
  CROP_WORK_MAX_SIDE, CROPPED_MAX_SIDE, compressPhoto, computeTargetSize, decodePhoto,
  photoFileNameFrom, pixelsSource, type PhotoSource,
} from '@/composables/usePhotoCapture'
import {
  DETECT_MAX_SIDE, detectDocumentQuad, isConvexQuad, nearestCornerIndex, rectifiedSize,
  scaleQuad, warpQuad, type Point, type Quad,
} from '@/composables/documentCrop'

/**
 * Ořez a narovnání fotky dokladu: dostane soubor, vrátí soubor.
 *
 * Obrazovka je jedna pro všechny cesty k fotce — „Vyfotit“ (fotoaparát) i fotka
 * vybraná ze souborů nebo přetažená do dropzony. Doklad se najde sám, rohy jdou
 * doladit prstem, výsledek se narovná perspektivní transformací a zkomprimuje
 * na JPEG. Bez nalezeného dokladu nebo s vypnutým ořezem jde celá fotka.
 *
 * Mobil je hlavní scénář (papírový doklad se fotí telefonem), proto:
 *   - roh se chytá s tolerancí {@link GRAB_RADIUS} px kdekoliv v ploše, ne na pixel,
 *   - roh drží odstup od prstu, takže neposkočí pod dotyk,
 *   - u taženého rohu je lupa — prst si ho jinak zakryje.
 *
 * Co prohlížeč nedekóduje (HEIC v Chrome), projde dál v originále: server to
 * zvládne přes Imagick, takže se nahrání kvůli ořezu nezastaví.
 */
const props = withDefaults(defineProps<{
  file: File
  /**
   * Fotka vybraná ze souborů: výřez zachová základ názvu souboru a bez ořezu jde
   * dál originál beze změny — zmenšení na `PHOTO_MAX_SIDE` by sken nebo fotku
   * z PC připravilo o rozlišení, které server pro vytěžení drží. Fotka
   * z fotoaparátu žádný použitelný název nemá, tam zůstává časová značka
   * `foto-YYYYMMDD-HHMMSS.jpg` a celá fotka se zmenší.
   */
  fromFile?: boolean
  /** Nabídnout „Vyfotit znovu“ — jen tam, odkud se fotoaparát otevírá. */
  allowRetake?: boolean
  /** Pořadí fotky v dávce; s `steps > 1` se ukáže v titulku. */
  step?: number
  steps?: number
}>(), { fromFile: false, allowRetake: false, step: 1, steps: 1 })

const emit = defineEmits<{
  /** Hotová fotka — ořezaná, celá, nebo originál, když ořez nešel. */
  done: [file: File]
  cancel: []
  retake: []
}>()

const PREVIEW_MAX_SIDE = 1000
/** Dosah prstu na roh. Dotyk má na mobilu průměr kolem 10 mm, ťuknutí vedle musí stačit. */
const GRAB_RADIUS = 44
const LOUPE_PX = 132
const LOUPE_ZOOM = 2.2
/** Výchozí ručně vyznačený ořez, když se doklad nenašel (10 % od okrajů). */
const DEFAULT_QUAD: Quad = [{ x: 0.1, y: 0.1 }, { x: 0.9, y: 0.1 }, { x: 0.9, y: 0.9 }, { x: 0.1, y: 0.9 }]

const { t } = useI18n()
const frame = ref<HTMLElement | null>(null)
const preview = ref<HTMLImageElement | null>(null)
const loupe = ref<HTMLCanvasElement | null>(null)
const busy = ref(false)
const error = ref('')
const previewUrl = ref('')
const aspect = ref(1)
/** Rohy ořezu v poměrných souřadnicích fotky (0–1). */
const quad = ref<Quad>(DEFAULT_QUAD)
const detected = ref(false)
const cropOn = ref(false)
const dragging = ref<number | null>(null)

let source: PhotoSource | null = null
/** Odstup prstu od rohu (v pixelech plochy) se drží po celé tažení. */
let grabOffset: Point = { x: 0, y: 0 }

watch(() => props.file, load, { immediate: true })
onBeforeUnmount(() => {
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

const ready = computed(() => !!previewUrl.value && !error.value)
const title = computed(() => props.steps > 1
  ? `${t('capture_photo.title')} (${props.step}/${props.steps})`
  : t('capture_photo.title'))
/** Název výstupu; `undefined` = časová značka z {@link compressPhoto}. */
const outputName = computed(() => (props.fromFile ? photoFileNameFrom(props.file.name) : undefined))

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

/** Lupa patří do protějšího kvadrantu, aby nebyla pod rukou. */
const loupeStyle = computed(() => {
  const corner = quad.value[dragging.value ?? 0]
  return {
    width: `${LOUPE_PX}px`,
    height: `${LOUPE_PX}px`,
    left: corner.x > 0.5 ? '0.5rem' : 'auto',
    right: corner.x > 0.5 ? 'auto' : '0.5rem',
    top: corner.y > 0.5 ? '0.5rem' : 'auto',
    bottom: corner.y > 0.5 ? 'auto' : '0.5rem',
  }
})

async function load() {
  revoke()
  closeSource()
  busy.value = true
  error.value = ''
  quad.value = DEFAULT_QUAD
  detected.value = false
  cropOn.value = false
  dragging.value = null
  try {
    source = await decodePhoto(props.file)
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

/** Bod dotyku v pixelech plochy a tatáž plocha pro přepočet rohů. */
function surface(): DOMRect | null {
  return frame.value?.getBoundingClientRect() ?? null
}

function onPointerDown(e: PointerEvent) {
  if (!cropOn.value || busy.value) return
  const rect = surface()
  if (!rect || rect.width < 1 || rect.height < 1) return
  const at = { x: e.clientX - rect.left, y: e.clientY - rect.top }
  const corners = scaleQuad(quad.value, rect.width, rect.height)
  const index = nearestCornerIndex(corners, at, GRAB_RADIUS)
  if (index === null) return
  dragging.value = index
  grabOffset = { x: corners[index].x - at.x, y: corners[index].y - at.y }
  try {
    // Zachycení ukazatele drží tažení i mimo plochu; když ho prohlížeč odmítne
    // (uvolněný pointer v Safari), tažení běží dál bez něj.
    frame.value?.setPointerCapture?.(e.pointerId)
  } catch { /* nevadí */ }
  e.preventDefault()
  drawLoupe()
}

function onPointerMove(e: PointerEvent) {
  const index = dragging.value
  const rect = surface()
  if (index === null || !rect || rect.width < 1 || rect.height < 1) return
  const x = Math.min(1, Math.max(0, (e.clientX - rect.left + grabOffset.x) / rect.width))
  const y = Math.min(1, Math.max(0, (e.clientY - rect.top + grabOffset.y) / rect.height))
  const next = quad.value.map((p, i) => (i === index ? { x, y } : p)) as Quad
  // Překřížený nebo zdegenerovaný čtyřúhelník nejde narovnat — takový posun se nepřijme.
  if (isConvexQuad(next)) quad.value = next
  e.preventDefault()
  drawLoupe()
}

function onPointerUp(e: PointerEvent) {
  dragging.value = null
  try {
    frame.value?.releasePointerCapture?.(e.pointerId)
  } catch { /* nevadí */ }
}

/** Výřez okolo taženého rohu ze náhledu, zvětšený, s křížem ve středu. */
function drawLoupe() {
  const canvas = loupe.value
  const image = preview.value
  const rect = surface()
  const index = dragging.value
  if (!canvas || !image || !rect || index === null || !image.naturalWidth) return
  const dpr = Math.min(2, window.devicePixelRatio || 1)
  canvas.width = Math.round(LOUPE_PX * dpr)
  canvas.height = Math.round(LOUPE_PX * dpr)
  const ctx = canvas.getContext('2d')
  if (!ctx) return
  // Okno v náhledu: kolik jeho pixelů se při daném zvětšení vejde do lupy.
  const window_ = (LOUPE_PX / LOUPE_ZOOM) * (image.naturalWidth / Math.max(1, rect.width))
  const center = {
    x: quad.value[index].x * image.naturalWidth,
    y: quad.value[index].y * image.naturalHeight,
  }
  ctx.fillStyle = '#111827'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(
    image,
    center.x - window_ / 2, center.y - window_ / 2, window_, window_,
    0, 0, canvas.width, canvas.height,
  )
  ctx.strokeStyle = 'rgba(255, 255, 255, 0.9)'
  ctx.lineWidth = Math.max(1, dpr)
  ctx.beginPath()
  ctx.moveTo(canvas.width / 2, 0)
  ctx.lineTo(canvas.width / 2, canvas.height)
  ctx.moveTo(0, canvas.height / 2)
  ctx.lineTo(canvas.width, canvas.height / 2)
  ctx.stroke()
}

/**
 * Počká na jedno překreslení, aby se stihlo ukázat „Zpracovávám…“, než narovnání
 * zablokuje vlákno. Samotný `requestAnimationFrame` nestačí: na skryté kartě
 * (uživatel přepne appku hned po ťuknutí) ho prohlížeč nezavolá a dialog by
 * zůstal viset na spinneru. Časovač je pojistka, která to dotlačí dál.
 */
function nextPaint(): Promise<void> {
  return new Promise(resolve => {
    let settled = false
    const finish = () => {
      if (settled) return
      settled = true
      resolve()
    }
    if (typeof requestAnimationFrame === 'function') requestAnimationFrame(finish)
    setTimeout(finish, 120)
  })
}

/**
 * Narovnaný výřez dokladu, nebo celá fotka; chyba narovnání spadne na celou fotku.
 * Celá fotka ze souborů (`fromFile`) jde v originále.
 */
async function buildFile(src: PhotoSource): Promise<File> {
  if (cropOn.value && src.pixels) {
    try {
      const work = computeTargetSize(src.width, src.height, CROP_WORK_MAX_SIDE)
      const q = scaleQuad(quad.value, work.width, work.height)
      const size = rectifiedSize(q, CROPPED_MAX_SIDE)
      const warped = warpQuad(src.pixels(work.width, work.height), q, size.width, size.height)
      return await compressPhoto(pixelsSource(warped), { maxSide: CROPPED_MAX_SIDE, name: outputName.value })
    } catch { /* celá fotka níže */ }
  }
  if (props.fromFile) return props.file
  return compressPhoto(src, { name: outputName.value })
}

async function confirm() {
  if (!source || busy.value) return
  busy.value = true
  await nextPaint()
  try {
    emit('done', await buildFile(source))
  } catch {
    busy.value = false
    error.value = t('capture_photo.decode_failed')
  }
}

function uploadOriginal() {
  emit('done', props.file)
}
</script>

<template>
  <Modal :title="title" width-class="max-w-md" @close="emit('cancel')">
    <div class="space-y-3">
      <p v-if="busy && !ready" class="text-sm text-neutral-600">{{ t('capture_photo.processing') }}</p>
      <template v-else-if="error">
        <p class="text-sm text-danger-700" role="alert">{{ error }}</p>
        <p class="text-xs text-neutral-500">
          {{ t(allowRetake ? 'capture_photo.fallback_hint' : 'capture_photo.fallback_hint_file') }}
        </p>
      </template>
      <template v-else-if="ready">
        <div ref="frame" class="relative mx-auto select-none touch-none rounded-md border border-neutral-200 bg-neutral-50 overflow-hidden"
          :style="frameStyle" data-testid="capture-photo-frame"
          @pointerdown="onPointerDown" @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="onPointerUp">
          <img ref="preview" :src="previewUrl" :alt="t('capture_photo.title')" class="absolute inset-0 w-full h-full" draggable="false" />
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
              :data-testid="`capture-photo-corner-${index}`" />
            <!-- Lupa u taženého rohu: prst si roh zakryje, bez ní se hrana trefuje naslepo. -->
            <canvas v-show="dragging !== null" ref="loupe" aria-hidden="true"
              class="absolute rounded-md border-2 border-white shadow-lg pointer-events-none"
              :style="loupeStyle" data-testid="capture-photo-loupe" />
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
        <button type="button" :class="btnOutline('neutral')" @click="emit('cancel')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          {{ t('capture_photo.cancel') }}
        </button>
        <button v-if="allowRetake" type="button" :class="btnOutline('warning')" :disabled="busy" @click="emit('retake')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M5.6 15A8 8 0 0018.4 9M18.4 9A8 8 0 005.6 15" /></svg>
          {{ t('capture_photo.retake') }}
        </button>
        <button v-if="error" type="button" :class="btnFilled('primary')" data-testid="capture-photo-original" @click="uploadOriginal">
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
</template>
