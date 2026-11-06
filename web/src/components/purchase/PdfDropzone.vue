<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PhotoCropDialog from '@/components/upload/PhotoCropDialog.vue'
import { isPhotoFile } from '@/composables/usePhotoCapture'

const props = withDefaults(defineProps<{
  uploading?: boolean
  maxSizeBytes?: number
  hint?: string
  /** Editor nového dokladu navíc přijímá ISDOC/ISDOCX k deterministickému importu. */
  acceptStructured?: boolean
  /** Když true, dropzone zmizí jakmile user začne psát do formuláře (anti-confusion) */
  hideWhenInteracted?: boolean
  /** Dávka: přijme víc souborů zaráz a vydá je jedním `files-dropped`. */
  multiple?: boolean
  /** Další povolené přípony bez tečky (podatelna bere i holé `xml`). */
  extraExtensions?: string[]
  /** Přepis druhého řádku o formátech a limitu — výchozí text mluví o 20 MiB. */
  sizeHint?: string
  /** Přepis atributu `accept` na file inputu. */
  accept?: string
  /**
   * Fotky projdou před vydáním ořezem dokladu ({@link PhotoCropDialog}) — stejná
   * obrazovka, jakou nabízí „Vyfotit“, jen pro fotku vybranou ze souborů nebo
   * přetaženou sem. Zapínat tam, kde se čeká doklad vyfocený mobilem; u příloh,
   * které fotkou být nemusí, zůstává vypnuto.
   */
  cropPhotos?: boolean
}>(), {
  uploading: false,
  maxSizeBytes: 20 * 1024 * 1024,
  acceptStructured: false,
  hideWhenInteracted: false,
  multiple: false,
  extraExtensions: () => [],
  cropPhotos: false,
})

const emit = defineEmits<{
  'file-dropped': [file: File]
  'files-dropped': [files: File[]]
  'error': [code: string, message: string]
}>()

const { t } = useI18n()
const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
/** Fotky z výběru čekající na ořez, zpracovávají se po jedné. */
const cropQueue = ref<File[]>([])
const cropIndex = ref(0)
const cropResults = ref<File[]>([])
/** Celý přijatý výběr; výřezy se do něj vrátí na svá místa a vydá se najednou. */
let pendingSelection: File[] = []

const cropCurrent = computed<File | null>(() => cropQueue.value[cropIndex.value] ?? null)

function onDragEnter(e: DragEvent) {
  e.preventDefault()
  if (props.uploading) return
  isDragging.value = true
}
function onDragOver(e: DragEvent) {
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
}
function onDragLeave(e: DragEvent) {
  // jen pokud opouštíme zónu, ne dítě
  if (e.target === e.currentTarget) isDragging.value = false
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  isDragging.value = false
  if (props.uploading) return
  handleSelection(Array.from(e.dataTransfer?.files ?? []))
}

function onPick() {
  if (props.uploading) return
  fileInput.value?.click()
}

function onFileInput(e: Event) {
  const target = e.target as HTMLInputElement
  handleSelection(Array.from(target.files ?? []))
  // reset, aby šlo vybrat ten samý soubor znovu po chybě
  target.value = ''
}

/**
 * Jednosouborový režim si bere první soubor (drop víc souborů na editor faktury
 * je omyl, ne dávka). Dávkový režim vydá všechny, které projdou validací —
 * jediný špatný soubor tak nezahodí zbytek výběru.
 */
function handleSelection(selected: File[]): void {
  if (selected.length === 0) return
  const candidates = props.multiple ? selected : selected.slice(0, 1)
  const accepted = candidates.filter(file => validate(file))
  if (accepted.length === 0) return

  const photos = props.cropPhotos ? accepted.filter(isPhotoFile) : []
  if (photos.length === 0) {
    emitSelection(accepted)
    return
  }
  // Výběr se vydá teprve po ořezu, aby PDF a fotky z jednoho dropu šly pohromadě.
  pendingSelection = accepted
  cropQueue.value = photos
  cropIndex.value = 0
  cropResults.value = []
}

function emitSelection(files: File[]): void {
  if (files.length === 0) return
  if (props.multiple) emit('files-dropped', files)
  else emit('file-dropped', files[0])
}

/** Hotová fotka; po poslední se výřezy vrátí na svá místa v původním výběru. */
function onCropDone(file: File): void {
  cropResults.value = [...cropResults.value, file]
  if (cropIndex.value + 1 < cropQueue.value.length) {
    cropIndex.value += 1
    return
  }
  const queue = [...cropResults.value]
  const merged = pendingSelection.map(file => (isPhotoFile(file) ? queue.shift() ?? file : file))
  resetCrop()
  emitSelection(merged)
}

/** Zrušení ořezu zahodí celý výběr — uživatel chtěl začít znovu, ne nahrát rozdělanou dávku. */
function onCropCancel(): void {
  resetCrop()
}

function resetCrop(): void {
  pendingSelection = []
  cropQueue.value = []
  cropIndex.value = 0
  cropResults.value = []
}

/** Klient-side validace; server samozřejmě udělá magic-bytes check znovu. */
function validate(file: File): boolean {
  if (file.size > props.maxSizeBytes) {
    emit('error', 'file_too_large',
      t('purchase_invoice.pdf.upload_error', { error: `> ${Math.round(props.maxSizeBytes / 1024 / 1024)} MiB` }))
    return false
  }
  // PDF/obrázky jsou přílohy. ISDOC/ISDOCX patří jen do editoru NOVÉ faktury,
  // kde se zpracují přes import-structured; na detailu/editaci by je /pdf odmítl.
  // Empty MIME některé browsery posílají i pro běžné soubory, proto rozhoduje i přípona.
  const isStructured = /\.isdocx?$/i.test(file.name)
  const extraExtension = props.extraExtensions.length > 0
    && new RegExp('\\.(' + props.extraExtensions.join('|') + ')$', 'i').test(file.name)
  const accepted = file.type === 'application/pdf'
    || file.type.startsWith('image/')
    || /\.(pdf|jpe?g|png|webp|heic|heif|gif|bmp)$/i.test(file.name)
    || (props.acceptStructured && isStructured)
    || extraExtension
  if (!accepted) {
    emit('error', 'invalid_pdf', t(props.acceptStructured
      ? 'purchase_invoice.pdf.invalid_document'
      : 'purchase_invoice.pdf.invalid_pdf'))
    return false
  }
  return true
}

const cls = computed(() => {
  if (props.uploading) return 'border-primary-300 bg-primary-50/50 cursor-wait'
  if (isDragging.value) return 'border-primary-500 bg-primary-50'
  return 'border-neutral-300 bg-neutral-50 hover:border-primary-400 hover:bg-primary-50/30 cursor-pointer'
})
</script>

<template>
  <div
    class="rounded-lg border-2 border-dashed transition-colors px-4 py-6 text-center"
    :class="cls"
    @dragenter="onDragEnter"
    @dragover="onDragOver"
    @dragleave="onDragLeave"
    @drop="onDrop"
    @click="onPick"
    role="button"
    tabindex="0"
    @keydown.enter.prevent="onPick"
    @keydown.space.prevent="onPick"
  >
    <input
      ref="fileInput"
      type="file"
      :accept="accept || (acceptStructured ? 'application/pdf,.pdf,image/*,.isdoc,.isdocx' : 'application/pdf,.pdf,image/*')"
      :multiple="multiple"
      class="hidden"
      @change="onFileInput"
      :disabled="uploading"
    />

    <div v-if="uploading" class="flex items-center justify-center gap-2 text-sm text-primary-700">
      <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
        <path d="M4 12a8 8 0 0 1 8-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
      </svg>
      <span>{{ t('purchase_invoice.pdf.uploading') }}</span>
    </div>
    <div v-else>
      <svg class="w-8 h-8 mx-auto mb-2 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6m-7-2a4 4 0 0 1 0-8 5 5 0 0 1 9.584-1.06A4 4 0 0 1 19 14" />
      </svg>
      <p class="text-sm text-neutral-700">
        {{ isDragging ? t('purchase_invoice.pdf.dropzone_active') : (hint || t(acceptStructured
          ? 'purchase_invoice.pdf.dropzone_hint_structured'
          : 'purchase_invoice.pdf.dropzone_hint')) }}
      </p>
      <p class="text-xs text-neutral-500 mt-1">{{ sizeHint || t(acceptStructured
        ? 'purchase_invoice.pdf.max_size_hint_structured'
        : 'purchase_invoice.pdf.max_size_hint') }}</p>
    </div>

    <PhotoCropDialog v-if="cropCurrent" :key="cropIndex" :file="cropCurrent" keep-name
      :step="cropIndex + 1" :steps="cropQueue.length"
      @done="onCropDone" @cancel="onCropCancel" />
  </div>
</template>
