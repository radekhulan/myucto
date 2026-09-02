<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnFilled, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

export type PayrollFileRejectReason = 'unsupported_file' | 'file_too_large'

const props = withDefaults(defineProps<{
  accept?: string
  allowedExtensions?: string[]
  maxSizeBytes?: number
  disabled?: boolean
  selectedFileName?: string
  dropHint: string
  dropActiveHint: string
  fileHint: string
  chooseFileText: string
  error?: string
  selectedText?: string
  dropzoneTestId?: string
  inputTestId?: string
  selectedTestId?: string
}>(), {
  accept: '.csv,.xlsx',
  allowedExtensions: () => ['csv', 'xlsx'],
  maxSizeBytes: 5_000_000,
  disabled: false,
  error: '',
  selectedFileName: '',
  selectedText: '',
  dropzoneTestId: undefined,
  inputTestId: undefined,
  selectedTestId: undefined,
})

const emit = defineEmits<{
  selected: [file: File]
  rejected: [reason: PayrollFileRejectReason, file: File]
}>()

const { t } = useI18n()

/**
 * Co se stalo s posledním pokusem o vložení souboru.
 *
 * Why: komponenta emitovala `rejected` a dál mlčela — vysvětlení psala
 * stránka jednou větou („Nepodporovaný soubor."), ve které nebyl ani název
 * souboru, ani jeho velikost, ani limit. Uživatel tedy nevěděl, jestli má
 * převést formát, nebo soubor zmenšit, a o kolik. Detail proto zůstává
 * v komponentě, která jediná zná soubor i vlastní meze.
 *
 * Druhá tichá díra: z přetažení víc souborů se bral bez upozornění první a
 * z přetažení složky (`files` prázdné) se nedělo vůbec nic — plocha jen
 * přestala svítit a vypadalo to jako zaseknutá aplikace.
 */
const notice = ref<
  | { kind: 'rejected'; reason: PayrollFileRejectReason; name: string; size: number }
  | { kind: 'multiple'; count: number; name: string }
  | { kind: 'no_file' }
  | null
>(null)

const fileInput = ref<HTMLInputElement | null>(null)
const dragDepth = ref(0)
const isDragging = computed(() => dragDepth.value > 0 && !props.disabled)
const descriptionId = `payroll-file-description-${useId()}`
const errorId = `payroll-file-error-${useId()}`
const selectedId = `payroll-file-selected-${useId()}`
const noticeId = `payroll-file-notice-${useId()}`
const describedBy = computed(() => [
  descriptionId,
  props.error ? errorId : '',
  notice.value ? noticeId : '',
  props.selectedFileName ? selectedId : '',
].filter(Boolean).join(' '))

function formatSize(bytes: number): string {
  const units = ['B', 'kB', 'MB', 'GB']
  let amount = bytes
  let unit = 0
  while (amount >= 1024 && unit < units.length - 1) {
    amount /= 1024
    unit += 1
  }
  return `${amount.toLocaleString(undefined, { maximumFractionDigits: unit === 0 ? 0 : 1 })} ${units[unit]}`
}

const noticeText = computed<string>(() => {
  const current = notice.value
  if (current === null) return ''
  if (current.kind === 'no_file') return t('payroll.file_dropzone.no_file')
  if (current.kind === 'multiple') {
    return t('payroll.file_dropzone.multiple', { count: current.count, name: current.name })
  }
  return current.reason === 'file_too_large'
    ? t('payroll.file_dropzone.too_large_detail', {
      name: current.name,
      size: formatSize(current.size),
      limit: formatSize(props.maxSizeBytes),
    })
    : t('payroll.file_dropzone.unsupported_detail', {
      name: current.name,
      extensions: props.allowedExtensions.join(', '),
    })
})

/** Chyba ze stránky zmizela → zmizí i detail k ní; jinak by strašil dál. */
watch(() => props.error, value => {
  if (value === '' && notice.value?.kind === 'rejected') notice.value = null
})

function openPicker() {
  if (!props.disabled) fileInput.value?.click()
}

function openPickerFromSurface(event: MouseEvent) {
  if ((event.target as HTMLElement).closest('button')) return
  openPicker()
}

function reject(reason: PayrollFileRejectReason, file: File) {
  notice.value = { kind: 'rejected', reason, name: file.name, size: file.size }
  emit('rejected', reason, file)
}

function handleFile(file: File) {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (!props.allowedExtensions.map(item => item.toLowerCase()).includes(extension)) {
    reject('unsupported_file', file)
    return
  }
  if (file.size > props.maxSizeBytes) {
    reject('file_too_large', file)
    return
  }
  emit('selected', file)
}

function chooseFile(event: Event) {
  const input = event.target as HTMLInputElement
  const files = input.files
  const file = files?.[0]
  // Vstup je jednosouborový, ale prohlížeč umí (drag na `<input>`) doručit víc.
  notice.value = files && files.length > 1 && file
    ? { kind: 'multiple', count: files.length, name: file.name }
    : null
  if (file) handleFile(file)
  // `value` se čistí VŽDY: bez toho by výběr téhož souboru podruhé (typicky po
  // opravě obsahu) neodpálil `change` a tlačítko by vypadalo mrtvě.
  input.value = ''
}

function dragEnter(event: DragEvent) {
  event.preventDefault()
  if (props.disabled) return
  dragDepth.value += 1
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy'
}

function dragOver(event: DragEvent) {
  event.preventDefault()
  if (!props.disabled && event.dataTransfer) event.dataTransfer.dropEffect = 'copy'
}

function dragLeave(event: DragEvent) {
  event.preventDefault()
  dragDepth.value = Math.max(0, dragDepth.value - 1)
}

function dropFile(event: DragEvent) {
  event.preventDefault()
  dragDepth.value = 0
  if (props.disabled) return
  const files = event.dataTransfer?.files
  const file = files?.[0]
  if (!file) {
    // Složka, obrázek z jiné stránky, kus textu — `files` je prázdné. Dřív se
    // v tu chvíli nestalo NIC a plocha jen zhasla.
    notice.value = { kind: 'no_file' }
    return
  }
  notice.value = files !== undefined && files.length > 1
    ? { kind: 'multiple', count: files.length, name: file.name }
    : null
  handleFile(file)
}
</script>

<template>
  <div
    :data-testid="dropzoneTestId"
    role="group"
    :aria-disabled="disabled"
    :aria-describedby="describedBy"
    class="flex min-h-36 flex-col items-center justify-center rounded-xl border-2 border-dashed px-5 py-6 text-center transition-colors focus:outline-none focus:ring-2 focus:ring-payroll-500/30"
    :class="[
      disabled ? 'cursor-not-allowed border-neutral-200 bg-neutral-50 opacity-60' : 'cursor-pointer',
      isDragging
        ? 'border-payroll-500 bg-payroll-50'
        : error
          ? 'border-danger-500 bg-danger-50/50'
          : !disabled && 'border-neutral-300 bg-neutral-50 hover:border-payroll-400 hover:bg-payroll-50/50',
    ]"
    :aria-invalid="error ? 'true' : undefined"
    @click="openPickerFromSurface"
    @dragenter="dragEnter"
    @dragover="dragOver"
    @dragleave="dragLeave"
    @drop="dropFile"
  >
    <input
      ref="fileInput"
      :data-testid="inputTestId"
      type="file"
      :accept="accept"
      class="sr-only"
      :disabled="disabled"
      :aria-describedby="describedBy"
      @change="chooseFile"
    >
    <svg
      class="h-8 w-8 text-payroll-600"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <path :d="ICONS.upload" />
    </svg>
    <p class="mt-2 font-medium text-neutral-900">
      {{ isDragging ? dropActiveHint : dropHint }}
    </p>
    <button
      type="button"
      :class="`${btnFilled('primary')} mt-3`"
      :disabled="disabled"
      :aria-describedby="describedBy"
      @click="openPicker"
    >
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path :d="ICONS.upload" />
      </svg>
      {{ chooseFileText }}
    </button>
    <p :id="descriptionId" class="mt-2 text-xs text-neutral-500">{{ fileHint }}</p>
    <p v-if="error" :id="errorId" role="alert" class="mt-2 text-sm font-medium text-danger-600">
      {{ error }}
    </p>
    <!--
      Konkrétní důvod (který soubor, jak velký, jaký je limit / jaké přípony)
      a hned u něj cesta ven. Bez toho zbylo jen obecné „Nepodporovaný soubor."
      a uživatel nevěděl, co s tím.
    -->
    <div
      v-if="notice"
      :id="noticeId"
      role="status"
      class="mt-2 max-w-full"
      data-testid="payroll-file-notice"
    >
      <p class="text-xs" :class="notice.kind === 'rejected' ? 'text-danger-600' : 'text-neutral-600'">
        {{ noticeText }}
      </p>
      <button
        v-if="notice.kind !== 'multiple'"
        type="button"
        :class="`${btnOutlineSm('neutral')} mt-2`"
        :disabled="disabled"
        data-testid="payroll-file-retry"
        @click.stop="openPicker"
      >
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.upload" />
        </svg>
        {{ t('payroll.file_dropzone.pick_another') }}
      </button>
    </div>
    <p
      v-if="selectedFileName"
      :id="selectedId"
      :data-testid="selectedTestId"
      :title="selectedFileName"
      class="mt-3 max-w-full truncate rounded-full bg-payroll-100 px-3 py-1 text-xs font-medium text-payroll-700"
    >
      {{ selectedText || selectedFileName }}
    </p>
  </div>
</template>
