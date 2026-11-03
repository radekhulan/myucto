<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnIconSm, btnOutlineSm } from '@/components/ui/buttonStyles'
import { MERGE_ACCEPT, MergeError, isMergeable, mergeToPdf } from '@/utils/mergeDocuments'

// Jeden doklad nahraný po částech (každá strana jako samostatné PDF nebo fotka):
// části se seřadí, spojí v prohlížeči do jednoho PDF a předají rodiči k vytěžení
// jako jediný doklad.
const props = defineProps<{ disabled?: boolean; busy?: boolean }>()
const emit = defineEmits<{ import: [file: File] }>()

const { t } = useI18n()
const toast = useToast()

const parts = ref<File[]>([])
const merging = ref(false)
const dragOver = ref(false)

function add(files: File[]) {
  if (props.disabled || merging.value || files.length === 0) return
  const accepted = files.filter(isMergeable)
  if (accepted.length < files.length) toast.error(t('integrations.ai.merge.only_pdf_images'))
  parts.value = [...parts.value, ...accepted]
}

function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  add(Array.from(input.files ?? []))
  input.value = ''
}
function onDragEnter(e: DragEvent) { e.preventDefault(); dragOver.value = true }
function onDragOver(e: DragEvent) {
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
}
function onDragLeave(e: DragEvent) {
  if (e.target === e.currentTarget) dragOver.value = false
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  dragOver.value = false
  add(Array.from(e.dataTransfer?.files ?? []))
}

function move(index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= parts.value.length) return
  const next = [...parts.value]
  ;[next[index], next[target]] = [next[target], next[index]]
  parts.value = next
}
// Přeřazení tažením řádku. Soubory přetažené z počítače na seznam se jen přidají na konec.
const dragIndex = ref<number | null>(null)
const dropIndex = ref<number | null>(null)
function onRowDragStart(e: DragEvent, index: number) {
  if (merging.value) { e.preventDefault(); return }
  dragIndex.value = index
  if (e.dataTransfer) {
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('text/plain', String(index))
  }
}
function onRowDragOver(e: DragEvent, index: number) {
  e.preventDefault()
  if (dragIndex.value === null) return
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  dropIndex.value = index
}
function onRowDrop(e: DragEvent, index: number) {
  e.preventDefault()
  const from = dragIndex.value
  onRowDragEnd()
  if (from === null) {
    add(Array.from(e.dataTransfer?.files ?? []))
    return
  }
  if (from === index) return
  const next = [...parts.value]
  const [moved] = next.splice(from, 1)
  next.splice(index, 0, moved)
  parts.value = next
}
function onRowDragEnd() {
  dragIndex.value = null
  dropIndex.value = null
}

function remove(index: number) {
  parts.value = parts.value.filter((_, i) => i !== index)
}
function clear() {
  parts.value = []
}

async function mergeAndImport() {
  if (props.disabled || props.busy || merging.value || parts.value.length < 2) return
  merging.value = true
  try {
    const file = await mergeToPdf(parts.value)
    emit('import', file)
    parts.value = []
  } catch (e) {
    if (e instanceof MergeError) toast.error(t(`integrations.ai.merge.error_${e.code}`, { file: e.fileName }))
    else toast.error(t('integrations.ai.merge.error_unreadable', { file: '' }))
  } finally {
    merging.value = false
  }
}
</script>

<template>
  <div data-test="ai-merge-upload" class="mt-4 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
    <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.ai.merge.title') }}</h2>
    <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.ai.merge.hint') }}</p>

    <div class="space-y-3">
      <label class="block border-2 border-dashed rounded-lg p-6 text-center transition"
        :class="[
          disabled ? 'cursor-not-allowed opacity-60 border-neutral-300' : 'cursor-pointer',
          !disabled && dragOver ? 'border-primary-500 bg-primary-50' : '',
          !disabled && !dragOver ? 'border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30' : '',
        ]"
        @dragenter="onDragEnter" @dragover="onDragOver" @dragleave="onDragLeave" @drop="onDrop">
        <input type="file" multiple :disabled="disabled || merging" :accept="MERGE_ACCEPT" @change="onPick" class="hidden" />
        <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" />
        </svg>
        <div class="text-sm font-medium text-neutral-700">{{ t('integrations.ai.merge.drop') }}</div>
        <div class="text-xs text-neutral-500 mt-1">{{ t('integrations.ai.merge.formats') }}</div>
      </label>

      <div v-if="parts.length > 0">
        <div class="flex flex-wrap gap-2 items-center justify-between mb-2">
          <h3 class="text-sm font-medium text-neutral-700">{{ t('integrations.ai.merge.parts_title', { n: parts.length }) }}</h3>
          <button type="button" @click="clear" :disabled="merging" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
        </div>
        <ol class="border border-neutral-200 rounded-md divide-y divide-neutral-100 text-xs">
          <li v-for="(file, idx) in parts" :key="`${idx}-${file.name}`" data-test="ai-merge-part"
              :draggable="!merging" :title="t('integrations.ai.merge.drag_hint')"
              class="flex items-center gap-2 px-2 py-1.5 transition-colors"
              :class="[
                merging ? '' : 'cursor-grab active:cursor-grabbing',
                dragIndex === idx ? 'opacity-40' : '',
                dropIndex === idx && dragIndex !== null && dragIndex > idx ? 'bg-primary-50 shadow-[inset_0_2px_0_var(--color-primary-500)]' : '',
                dropIndex === idx && dragIndex !== null && dragIndex < idx ? 'bg-primary-50 shadow-[inset_0_-2px_0_var(--color-primary-500)]' : '',
              ]"
              @dragstart="onRowDragStart($event, idx)" @dragover="onRowDragOver($event, idx)"
              @drop="onRowDrop($event, idx)" @dragend="onRowDragEnd">
            <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
              <path stroke-linecap="round" d="M9 6h.01M9 12h.01M9 18h.01M15 6h.01M15 12h.01M15 18h.01" />
            </svg>
            <span class="w-5 text-right text-neutral-400 shrink-0">{{ idx + 1 }}.</span>
            <span class="font-mono truncate flex-1 min-w-0" :title="file.name">{{ file.name }}</span>
            <span class="text-neutral-400 whitespace-nowrap">{{ Math.round(file.size / 1024) }} kB</span>
            <button type="button" :class="btnIconSm('neutral')" :disabled="merging || idx === 0"
                    :title="t('integrations.ai.merge.move_up')" :aria-label="t('integrations.ai.merge.move_up')" @click="move(idx, -1)">
              <svg class="w-4 h-4 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevron" /></svg>
            </button>
            <button type="button" :class="btnIconSm('neutral')" :disabled="merging || idx === parts.length - 1"
                    :title="t('integrations.ai.merge.move_down')" :aria-label="t('integrations.ai.merge.move_down')" @click="move(idx, 1)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevron" /></svg>
            </button>
            <button type="button" :class="btnIconSm('danger')" :disabled="merging"
                    :title="t('common.delete')" :aria-label="t('common.delete')" @click="remove(idx)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
            </button>
          </li>
        </ol>
      </div>

      <button type="button" data-test="ai-merge-run" @click="mergeAndImport"
              :disabled="disabled || busy || merging || parts.length < 2"
              :class="[btnFilled('primary'), 'w-full whitespace-nowrap']">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
        {{ merging ? t('integrations.ai.merge.merging') : busy ? t('integrations.ai.extracting') : t('integrations.ai.merge.run') }}
      </button>
    </div>
  </div>
</template>
