<script setup lang="ts">
/**
 * Sdílené rozložení formulář + náhled originálu: vedle sebe s posuvným rozhraním,
 * nebo náhled pod formulářem. Používá ho detail a editor dokladu, kontrola vytěžení,
 * pokladna i fronta příchozích dokladů.
 *
 * O tom, co se vejde, rozhoduje změřená šířka rozložení, ne šířka okna: stejné okno
 * může mít pracovní plochu rozdělenou na panely a modál je širší než stránka s menu.
 * Pod `SIDE_MIN_PX` jde náhled pod formulář bez ohledu na uloženou volbu, ta se nemění.
 * Vedle sebe se tak vejde i okno 1280 CSS px, typický 16" notebook se škálováním.
 *
 * Sloupec s formulářem je vedle sebe vlastní kontejner `workspace`. Breakpointy `sm:`
 * až `2xl:` jsou v aplikaci kontejnerové (main.css), takže se mřížky polí poskládají
 * podle skutečné šířky sloupce a při zúžení se nemačkají.
 *
 * Náhled se vykresluje jen jednou. Skrytý kořen (např. mobilní varianta řádku) má šířku
 * 0 a náhled nevykreslí vůbec, jinak by prohlížeč dokument stahoval i pro něj.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DocumentSidePreview from '@/components/documents/DocumentSidePreview.vue'
import type { SidePreviewCtrl } from '@/composables/useSidePreview'

const FORM_MIN_PX = 480
const PREVIEW_MIN_PX = 352
const HANDLE_PX = 12
const SIDE_MIN_PX = FORM_MIN_PX + HANDLE_PX + PREVIEW_MIN_PX
/** Posuvník stránky mění šířku; bez rezervy by rozložení na hraně přeskakovalo tam a zpět. */
const FIT_HYSTERESIS_PX = 16
const DEFAULT_PERCENT = 46
const KEY_STEP_PERCENT = 2
const KEY_LARGE_STEP_PERCENT = 10

const props = withDefaults(defineProps<{
  ctrl: SidePreviewCtrl
  /** Vykreslit náhled? Rozhoduje volající podle `ctrl.open` a toho, zda je co ukázat. */
  show: boolean
  src: string | null
  fileName?: string | null
  docType?: string | null
  emptyText?: string
  /** Náhled uvnitř modálu; `Modal` od něj nemá odsazovat backdrop. */
  embedded?: boolean
  /** Třídy sloupce s formulářem. */
  formClass?: string
  /** Omezení šířky, když není náhled vedle formuláře. */
  narrowClass?: string
  /** Výška a přilepení panelu vedle formuláře. */
  sideClass?: string
  /** Klik v náhledu nesmí probublat k rodiči, který na něj reaguje (rozbalená karta). */
  isolateClicks?: boolean
}>(), {
  sideClass: 'sticky top-[calc(var(--instance-alert-h,0px)+3.5rem)] h-[calc(100vh-var(--instance-alert-h,0px)-5rem)]',
})

const { t } = useI18n()

const rootEl = ref<HTMLElement | null>(null)
const rootWidth = ref(0)
const fits = ref(false)
let observer: ResizeObserver | null = null

function measure(): void {
  const width = rootEl.value?.getBoundingClientRect().width ?? 0
  // Skrytí nic nemění: po návratu zůstane rozložení i načtený dokument jako předtím.
  if (width <= 0) return
  rootWidth.value = width
  fits.value = width >= SIDE_MIN_PX - (fits.value ? FIT_HYSTERESIS_PX : 0)
}

onMounted(() => {
  measure()
  if (typeof ResizeObserver !== 'undefined' && rootEl.value) {
    observer = new ResizeObserver(measure)
    observer.observe(rootEl.value)
  }
})
onBeforeUnmount(() => observer?.disconnect())

const previewRendered = computed(() => props.show && rootWidth.value > 0)
const sideBySide = computed(() => previewRendered.value && fits.value && props.ctrl.placement.value === 'side')

const maxPreviewPx = computed(() => Math.max(PREVIEW_MIN_PX, rootWidth.value - FORM_MIN_PX - HANDLE_PX))
const clampPreview = (px: number) => Math.min(maxPreviewPx.value, Math.max(PREVIEW_MIN_PX, px))
const toPercent = (px: number) => Math.round((px / rootWidth.value) * 1000) / 10

const dragPercent = ref<number | null>(null)
const dragging = ref(false)
const previewPx = computed(() => {
  const percent = dragPercent.value ?? props.ctrl.width.value ?? DEFAULT_PERCENT
  return clampPreview((rootWidth.value * percent) / 100)
})

/** Hodnota separátoru podle vzoru ARIA window splitter: velikost primárního panelu (formuláře). */
const formPercent = (previewWidth: number) => Math.round(100 - (previewWidth / rootWidth.value) * 100)

function onPointerDown(event: PointerEvent): void {
  if (event.button !== 0) return
  ;(event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId)
  dragging.value = true
}

function onPointerMove(event: PointerEvent): void {
  if (!dragging.value || !rootEl.value) return
  const right = rootEl.value.getBoundingClientRect().right
  dragPercent.value = toPercent(clampPreview(right - event.clientX - HANDLE_PX / 2))
}

function onPointerUp(): void {
  if (dragging.value && dragPercent.value !== null) props.ctrl.setWidth(dragPercent.value)
  dragging.value = false
  dragPercent.value = null
}

function onPointerCancel(): void {
  dragging.value = false
  dragPercent.value = null
}

function onKeydown(event: KeyboardEvent): void {
  const step = (rootWidth.value * (event.shiftKey ? KEY_LARGE_STEP_PERCENT : KEY_STEP_PERCENT)) / 100
  let next: number | null
  switch (event.key) {
    case 'ArrowLeft': next = clampPreview(previewPx.value + step); break
    case 'ArrowRight': next = clampPreview(previewPx.value - step); break
    case 'Home': next = maxPreviewPx.value; break
    case 'End': next = PREVIEW_MIN_PX; break
    case 'Enter': next = null; break
    default: return
  }
  event.preventDefault()
  event.stopPropagation()
  props.ctrl.setWidth(next === null ? null : toPercent(next))
}
</script>

<template>
  <div ref="rootEl" data-test="document-preview-split" :class="sideBySide ? 'flex items-start' : ''">
    <div
      class="min-w-0"
      :class="[formClass, sideBySide ? 'document-preview-split-form flex-1' : narrowClass]"
    >
      <slot />
    </div>
    <div
      v-if="previewRendered"
      :class="sideBySide ? ['flex shrink-0', sideClass] : ['mt-4', narrowClass]"
      :style="sideBySide ? { width: `${previewPx + HANDLE_PX}px` } : undefined"
      @click="isolateClicks && $event.stopPropagation()"
    >
      <div
        v-if="sideBySide"
        role="separator"
        tabindex="0"
        aria-orientation="vertical"
        :aria-label="t('common.preview_resize')"
        :aria-valuenow="formPercent(previewPx)"
        :aria-valuemin="formPercent(maxPreviewPx)"
        :aria-valuemax="formPercent(PREVIEW_MIN_PX)"
        :title="t('common.preview_resize_hint')"
        data-test="document-preview-splitter"
        class="group shrink-0 w-3 flex items-center justify-center rounded cursor-col-resize touch-none select-none focus-visible:outline-2 focus-visible:outline-primary-500"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerCancel"
        @keydown="onKeydown"
        @dblclick="ctrl.setWidth(null)"
      >
        <span
          class="h-12 w-1 rounded-full group-hover:bg-primary-500"
          :class="dragging ? 'bg-primary-500' : 'bg-neutral-300'"
        ></span>
      </div>
      <DocumentSidePreview
        :class="sideBySide ? 'flex-1 min-w-0' : 'w-full h-[70vh] min-h-[20rem]'"
        :src="src"
        :file-name="fileName"
        :doc-type="docType"
        :empty-text="emptyText"
        :embedded="embedded || !sideBySide"
        :placement="ctrl.placement.value"
        :can-place-side="fits"
        :resizing="dragging"
        @update:placement="ctrl.setPlacement"
        @close="ctrl.close()"
      />
    </div>
  </div>
</template>

<style scoped>
.document-preview-split-form {
  container-name: workspace;
  container-type: inline-size;
}
</style>
