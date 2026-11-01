<script setup lang="ts">
import DateInput from '@/components/ui/DateInput.vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  PERSONNEL_DOCUMENT_CATEGORIES,
  payrollPersonnelApi,
  type PersonnelDocument,
  type PersonnelDocumentCategory,
  type PersonnelDocumentMeta,
  type PersonnelFile,
  type PersonnelNote,
} from '@/api/payrollPersonnel'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import { formatBytes } from '@/components/documents/docFormat'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import { BTN_DISABLED_NOTE, ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { appIsoDate } from '@/utils/date'
import { payrollQueryId } from './payrollAgendaLinks'

/**
 * Personální spis zaměstnance — nahrané dokumenty (pracovní smlouvy, dodatky,
 * …) a poznámky personalisty.
 *
 * Soubory neleží v sekci Dokumenty: jsou to soukromá data, která server drží
 * šifrovaně ve vlastním úložišti. Náhled i stažení proto jdou přes API jako
 * blob, ne odkazem, který by šel přeposlat.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const employeeId = ref<number | null>(payrollQueryId(route.query, 'person'))
const file = ref<PersonnelFile | null>(null)
const loading = ref(false)
const loadFailed = ref(false)
const busy = ref(false)
const canWrite = computed(() => auth.canWrite('payroll.personnel'))
let loadSequence = 0

const FIELD = 'mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm'
const LABEL = 'block text-xs font-medium text-neutral-600'

function emptyMeta(): PersonnelDocumentMeta {
  return { category: 'employment_contract', title: '', document_date: null, valid_until: null, note: null }
}

// ── načtení ─────────────────────────────────────────────────────────────────

async function load() {
  const selected = employeeId.value
  const sequence = ++loadSequence
  file.value = null
  loadFailed.value = false
  if (selected === null) return
  loading.value = true
  try {
    const loaded = await payrollPersonnelApi.show(selected)
    if (sequence === loadSequence) file.value = loaded
  } catch {
    if (sequence === loadSequence) loadFailed.value = true
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

function errorMessage(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { error?: { message?: unknown } } } })
    ?.response?.data?.error?.message
  return typeof message === 'string' && message !== '' ? message : fallback
}

// ── nahrání ─────────────────────────────────────────────────────────────────

const uploadFiles = ref<File[]>([])
const uploadMeta = ref<PersonnelDocumentMeta>(emptyMeta())
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const acceptAttribute = computed(() =>
  (file.value?.allowed_extensions ?? []).map(extension => `.${extension}`).join(','))
const maxMb = computed(() => Math.floor((file.value?.max_file_bytes ?? 0) / 1024 / 1024))

function resetUpload() {
  uploadFiles.value = []
  uploadMeta.value = emptyMeta()
  if (fileInput.value) fileInput.value.value = ''
}

function pickFiles(list: FileList | null) {
  if (list === null || list.length === 0) return
  uploadFiles.value = Array.from(list)
  if (uploadFiles.value.length === 1 && uploadMeta.value.title.trim() === '') {
    uploadMeta.value.title = uploadFiles.value[0].name.replace(/\.[^.]+$/, '')
  }
}

function onFileChange(event: Event) {
  pickFiles((event.target as HTMLInputElement).files)
}

function onDrop(event: DragEvent) {
  dragOver.value = false
  if (!canWrite.value || busy.value) return
  pickFiles(event.dataTransfer?.files ?? null)
}

const uploadBlockedReason = computed<string | null>(() => {
  if (!canWrite.value) return t('payroll.personnel_file.read_only')
  if (uploadFiles.value.length === 0) return t('payroll.personnel_file.upload.pick_first')
  if (uploadMeta.value.document_date && uploadMeta.value.valid_until
    && uploadMeta.value.valid_until < uploadMeta.value.document_date) {
    return t('payroll.personnel_file.validity_before_date')
  }
  return null
})

async function upload() {
  const selected = employeeId.value
  if (selected === null || uploadBlockedReason.value !== null) return
  busy.value = true
  const files = [...uploadFiles.value]
  let uploaded = 0
  try {
    for (const item of files) {
      const meta: PersonnelDocumentMeta = {
        ...uploadMeta.value,
        // Víc souborů naráz: název dokumentu se vezme z názvu souboru.
        title: files.length === 1 ? uploadMeta.value.title.trim() : item.name.replace(/\.[^.]+$/, ''),
        note: uploadMeta.value.note?.trim() || null,
      }
      try {
        await payrollPersonnelApi.upload(selected, item, meta)
        uploaded++
      } catch (error) {
        toast.error(`${item.name}: ${errorMessage(error, t('payroll.personnel_file.upload.failed'))}`)
      }
    }
    if (uploaded > 0) {
      toast.success(t('payroll.personnel_file.upload.done', { count: uploaded }, uploaded))
      resetUpload()
    }
  } finally {
    busy.value = false
    await load()
  }
}

// ── náhled a stažení ────────────────────────────────────────────────────────

const preview = ref<{ document: PersonnelDocument, url: string, isImage: boolean } | null>(null)

function closePreview() {
  if (preview.value !== null) URL.revokeObjectURL(preview.value.url)
  preview.value = null
}
onBeforeUnmount(closePreview)

async function openPreview(document: PersonnelDocument) {
  if (employeeId.value === null) return
  busy.value = true
  try {
    const blob = await payrollPersonnelApi.content(employeeId.value, document.id, true)
    closePreview()
    preview.value = {
      document,
      url: URL.createObjectURL(blob),
      isImage: blob.type.startsWith('image/'),
    }
  } catch {
    toast.error(t('payroll.personnel_file.preview_failed'))
  } finally {
    busy.value = false
  }
}

async function download(document: PersonnelDocument) {
  if (employeeId.value === null) return
  busy.value = true
  try {
    const blob = await payrollPersonnelApi.content(employeeId.value, document.id, false)
    const url = URL.createObjectURL(blob)
    const link = window.document.createElement('a')
    link.href = url
    link.download = document.original_name
    window.document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch {
    toast.error(t('payroll.personnel_file.download_failed'))
  } finally {
    busy.value = false
  }
}

// ── úprava údajů dokumentu ──────────────────────────────────────────────────

const editing = ref<{ id: number, meta: PersonnelDocumentMeta } | null>(null)

function startEdit(document: PersonnelDocument) {
  editing.value = {
    id: document.id,
    meta: {
      category: document.category,
      title: document.title,
      document_date: document.document_date,
      valid_until: document.valid_until,
      note: document.note,
    },
  }
}

async function saveEdit() {
  const current = editing.value
  if (current === null || employeeId.value === null) return
  busy.value = true
  try {
    await payrollPersonnelApi.updateDocument(employeeId.value, current.id, {
      ...current.meta,
      title: current.meta.title.trim(),
      document_date: current.meta.document_date || null,
      valid_until: current.meta.valid_until || null,
      note: current.meta.note?.trim() || null,
    })
    editing.value = null
    toast.success(t('payroll.personnel_file.saved'))
    await load()
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.personnel_file.save_failed')))
  } finally {
    busy.value = false
  }
}

// ── mazání (dokument i poznámka) ────────────────────────────────────────────

const deleting = ref<{ kind: 'document' | 'note', id: number, label: string } | null>(null)

async function confirmDelete() {
  const target = deleting.value
  if (target === null || employeeId.value === null) return
  busy.value = true
  try {
    if (target.kind === 'document') {
      await payrollPersonnelApi.deleteDocument(employeeId.value, target.id)
      await load()
    } else {
      file.value = await payrollPersonnelApi.deleteNote(employeeId.value, target.id)
    }
    deleting.value = null
    toast.success(t('payroll.personnel_file.deleted'))
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.personnel_file.delete_failed')))
  } finally {
    busy.value = false
  }
}

// ── poznámky ────────────────────────────────────────────────────────────────

const newNote = ref({ body: '', pinned: false })
const editingNote = ref<{ id: number, body: string, pinned: boolean } | null>(null)

async function addNote() {
  if (employeeId.value === null || newNote.value.body.trim() === '') return
  busy.value = true
  try {
    file.value = await payrollPersonnelApi.createNote(employeeId.value, {
      body: newNote.value.body.trim(),
      pinned: newNote.value.pinned,
    })
    newNote.value = { body: '', pinned: false }
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.personnel_file.save_failed')))
  } finally {
    busy.value = false
  }
}

function startNoteEdit(note: PersonnelNote) {
  if (note.body === null) return
  editingNote.value = { id: note.id, body: note.body, pinned: note.pinned }
}

async function saveNote(note: { id: number, body: string, pinned: boolean }) {
  if (employeeId.value === null || note.body.trim() === '') return
  busy.value = true
  try {
    file.value = await payrollPersonnelApi.updateNote(employeeId.value, note.id, {
      body: note.body.trim(),
      pinned: note.pinned,
    })
    editingNote.value = null
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.personnel_file.save_failed')))
  } finally {
    busy.value = false
  }
}

async function togglePin(note: PersonnelNote) {
  if (note.body === null) return
  await saveNote({ id: note.id, body: note.body, pinned: !note.pinned })
}

// ── zobrazení ───────────────────────────────────────────────────────────────

const today = appIsoDate()

function categoryLabel(category: PersonnelDocumentCategory): string {
  return t(`payroll.personnel_file.category.${category}`)
}

function isExpired(document: PersonnelDocument): boolean {
  return document.valid_until !== null && document.valid_until < today
}

// Až na konci: okamžité spuštění sahá na stav nahrávání deklarovaný výše.
watch(employeeId, (value) => {
  const current = payrollQueryId(route.query, 'person')
  if (current !== value) {
    const query = { ...route.query }
    if (value === null) delete query.person
    else query.person = String(value)
    void router.replace({ query })
  }
  resetUpload()
  void load()
}, { immediate: true })
</script>

<template>
  <div class="space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.personnel_file.title') }}</h1>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.personnel_file.subtitle') }}</p>
    </header>

    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <label :class="['min-w-64 max-w-xl', LABEL]">
        {{ t('payroll.personnel_file.employee') }}
        <PayrollPersonSearchSelect
          v-model="employeeId"
          class="mt-1"
          :label="t('payroll.personnel_file.employee')"
          :disabled="busy"
          data-test="personnel-file-person"
        />
      </label>
    </section>

    <EmptyState
      v-if="employeeId === null"
      boxed
      icon="user"
      :title="t('payroll.personnel_file.empty_title')"
      :message="t('payroll.personnel_file.empty_message')"
    />
    <div v-else-if="loading && file === null" class="rounded-lg border border-neutral-200 bg-surface p-6 text-sm text-neutral-500">
      {{ t('common.loading') }}
    </div>
    <EmptyState
      v-else-if="loadFailed"
      boxed
      variant="failed"
      :message="t('payroll.personnel_file.load_failed')"
      :cta="t('common.refresh')"
      cta-icon="cycle"
      @action="load"
    />

    <div v-else-if="file" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
      <!-- Dokumenty -->
      <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4 xl:col-span-2" data-test="personnel-documents">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="text-base font-semibold text-neutral-900">
            {{ t('payroll.personnel_file.documents_title') }}
            <span class="ml-1 text-sm font-normal text-neutral-400">{{ file.documents.length }}</span>
          </h2>
        </div>

        <!-- Nahrání -->
        <div
          v-if="canWrite"
          :class="['rounded-lg border-2 border-dashed p-4 transition-colors',
                   dragOver ? 'border-payroll-500 bg-payroll-50' : 'border-neutral-200 bg-neutral-50/60']"
          data-test="personnel-upload"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop.prevent="onDrop"
        >
          <div class="flex flex-wrap items-center gap-3">
            <label :class="[btnOutline('primary'), 'cursor-pointer whitespace-nowrap']">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.upload" /></svg>
              {{ t('payroll.personnel_file.upload.choose') }}
              <input
                ref="fileInput"
                type="file"
                multiple
                class="sr-only"
                :accept="acceptAttribute"
                :disabled="busy"
                data-test="personnel-upload-input"
                @change="onFileChange"
              >
            </label>
            <span class="text-sm text-neutral-500">
              <template v-if="uploadFiles.length === 0">{{ t('payroll.personnel_file.upload.drop_hint', { mb: maxMb }) }}</template>
              <template v-else>{{ uploadFiles.map(item => `${item.name} (${formatBytes(item.size)})`).join(', ') }}</template>
            </span>
          </div>

          <div v-if="uploadFiles.length > 0" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
            <label :class="LABEL">
              {{ t('payroll.personnel_file.field.category') }}
              <select v-model="uploadMeta.category" :class="FIELD" :disabled="busy" data-test="personnel-upload-category">
                <option v-for="category in PERSONNEL_DOCUMENT_CATEGORIES" :key="category" :value="category">{{ categoryLabel(category) }}</option>
              </select>
            </label>
            <label v-if="uploadFiles.length === 1" :class="LABEL">
              {{ t('payroll.personnel_file.field.title') }}
              <input v-model="uploadMeta.title" type="text" maxlength="255" :class="FIELD" :disabled="busy" data-test="personnel-upload-title">
            </label>
            <p v-else class="self-end text-xs text-neutral-500">{{ t('payroll.personnel_file.upload.multi_title_hint') }}</p>
            <label :class="LABEL">
              {{ t('payroll.personnel_file.field.document_date') }}
              <DateInput v-model="uploadMeta.document_date" :class="FIELD" :disabled="busy" />
            </label>
            <label :class="LABEL">
              {{ t('payroll.personnel_file.field.valid_until') }}
              <DateInput v-model="uploadMeta.valid_until" :class="FIELD" :disabled="busy" />
            </label>
            <label :class="[LABEL, 'md:col-span-2']">
              {{ t('payroll.personnel_file.field.note') }}
              <input v-model="uploadMeta.note" type="text" maxlength="1000" :class="FIELD" :disabled="busy">
            </label>
            <div class="flex flex-wrap items-center gap-2 md:col-span-2">
              <button
                type="button"
                :class="[btnFilled('primary'), 'whitespace-nowrap']"
                :disabled="busy || uploadBlockedReason !== null"
                data-test="personnel-upload-submit"
                @click="upload"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.upload" /></svg>
                {{ t('payroll.personnel_file.upload.submit', { count: uploadFiles.length }, uploadFiles.length) }}
              </button>
              <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="resetUpload">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
                {{ t('common.cancel') }}
              </button>
              <p v-if="uploadBlockedReason && uploadFiles.length > 0" :class="[BTN_DISABLED_NOTE, 'w-full']">{{ uploadBlockedReason }}</p>
            </div>
          </div>
        </div>
        <p v-else :class="BTN_DISABLED_NOTE">{{ t('payroll.personnel_file.read_only') }}</p>

        <!-- Seznam -->
        <EmptyState
          v-if="file.documents.length === 0"
          dense
          icon="doc"
          :title="t('payroll.personnel_file.documents_empty_title')"
          :message="t('payroll.personnel_file.documents_empty_message')"
        />
        <ul v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200" data-test="personnel-document-list">
          <li
            v-for="document in file.documents"
            :key="document.id"
            class="flex flex-wrap items-start gap-3 px-3 py-3"
            :data-test="`personnel-document-${document.id}`"
          >
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-payroll-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
            </svg>
            <div class="min-w-0 flex-1 basis-64">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-neutral-900">{{ document.title }}</span>
                <span class="rounded-full bg-payroll-50 px-2 py-0.5 text-xs font-medium text-payroll-700">{{ categoryLabel(document.category) }}</span>
                <span
                  v-if="isExpired(document)"
                  class="rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700"
                >{{ t('payroll.personnel_file.expired') }}</span>
              </div>
              <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-neutral-500">
                <span v-if="document.document_date">{{ t('payroll.personnel_file.dated', { date: formatDate(document.document_date) }) }}</span>
                <span v-if="document.valid_until">{{ t('payroll.personnel_file.valid_until_short', { date: formatDate(document.valid_until) }) }}</span>
                <span class="truncate">{{ document.original_name }} · {{ formatBytes(document.size_bytes) }}</span>
                <span>{{ t('payroll.personnel_file.uploaded', { date: formatDateTime(document.created_at), name: document.uploaded_by_name ?? '—' }) }}</span>
              </div>
              <p v-if="document.note" class="mt-1 whitespace-pre-line text-sm text-neutral-600">{{ document.note }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
              <button
                v-if="document.previewable"
                type="button"
                :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
                :disabled="busy"
                :data-test="`personnel-document-preview-${document.id}`"
                @click="openPreview(document)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>
                {{ t('payroll.personnel_file.action.preview') }}
              </button>
              <button
                type="button"
                :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                :disabled="busy"
                :data-test="`personnel-document-download-${document.id}`"
                @click="download(document)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
                {{ t('payroll.personnel_file.action.download') }}
              </button>
              <button
                v-if="canWrite"
                type="button"
                :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                :disabled="busy"
                @click="startEdit(document)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
              <button
                v-if="canWrite"
                type="button"
                :class="[btnOutlineSm('danger'), 'whitespace-nowrap']"
                :disabled="busy"
                :data-test="`personnel-document-delete-${document.id}`"
                @click="deleting = { kind: 'document', id: document.id, label: document.title }"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Poznámky -->
      <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4" data-test="personnel-notes">
        <h2 class="text-base font-semibold text-neutral-900">
          {{ t('payroll.personnel_file.notes_title') }}
          <span class="ml-1 text-sm font-normal text-neutral-400">{{ file.notes.length }}</span>
        </h2>

        <div v-if="canWrite" class="space-y-2">
          <textarea
            v-model="newNote.body"
            rows="3"
            maxlength="20000"
            :placeholder="t('payroll.personnel_file.note_placeholder')"
            :class="FIELD"
            :disabled="busy"
            data-test="personnel-note-input"
          />
          <div class="flex flex-wrap items-center justify-between gap-2">
            <label class="inline-flex items-center gap-2 text-sm text-neutral-600">
              <input v-model="newNote.pinned" type="checkbox" class="rounded border-neutral-300" :disabled="busy">
              {{ t('payroll.personnel_file.pin') }}
            </label>
            <button
              type="button"
              :class="[btnFilled('primary'), 'whitespace-nowrap']"
              :disabled="busy || newNote.body.trim() === ''"
              data-test="personnel-note-add"
              @click="addNote"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.personnel_file.add_note') }}
            </button>
          </div>
        </div>

        <p v-if="file.notes.length === 0" class="text-sm text-neutral-500">{{ t('payroll.personnel_file.notes_empty') }}</p>
        <ul v-else class="space-y-2" data-test="personnel-note-list">
          <li
            v-for="note in file.notes"
            :key="note.id"
            :class="['rounded-md border p-3 text-sm', note.pinned ? 'border-warning-500/40 bg-warning-50/60' : 'border-neutral-200']"
            :data-test="`personnel-note-${note.id}`"
          >
            <template v-if="editingNote?.id === note.id">
              <textarea v-model="editingNote.body" rows="4" maxlength="20000" :class="FIELD" :disabled="busy" />
              <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <label class="inline-flex items-center gap-2 text-sm text-neutral-600">
                  <input v-model="editingNote.pinned" type="checkbox" class="rounded border-neutral-300" :disabled="busy">
                  {{ t('payroll.personnel_file.pin') }}
                </label>
                <div class="flex flex-wrap gap-1.5">
                  <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="editingNote = null">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
                    {{ t('common.cancel') }}
                  </button>
                  <button type="button" :class="[btnOutlineSm('success'), 'whitespace-nowrap']" :disabled="busy || editingNote.body.trim() === ''" @click="saveNote(editingNote)">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                    {{ t('common.save') }}
                  </button>
                </div>
              </div>
            </template>
            <template v-else>
              <p v-if="note.erased" class="italic text-neutral-400">{{ t('payroll.personnel_file.note_erased') }}</p>
              <p v-else class="whitespace-pre-line text-neutral-800">{{ note.body }}</p>
              <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <span class="text-xs text-neutral-500">
                  <template v-if="note.pinned">{{ t('payroll.personnel_file.pinned') }} · </template>{{ formatDateTime(note.created_at) }} · {{ note.created_by_name ?? '—' }}
                  <template v-if="note.updated_by_name"> · {{ t('payroll.personnel_file.edited_by', { name: note.updated_by_name, date: formatDateTime(note.updated_at) }) }}</template>
                </span>
                <div v-if="canWrite && !note.erased" class="flex flex-wrap gap-1">
                  <button type="button" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" :disabled="busy" :title="note.pinned ? t('payroll.personnel_file.unpin') : t('payroll.personnel_file.pin')" @click="togglePin(note)">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.pin" /></svg>
                    {{ note.pinned ? t('payroll.personnel_file.unpin') : t('payroll.personnel_file.pin_short') }}
                  </button>
                  <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="startNoteEdit(note)">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                    {{ t('common.edit') }}
                  </button>
                  <button
                    type="button"
                    :class="[btnOutlineSm('danger'), 'whitespace-nowrap']"
                    :disabled="busy"
                    @click="deleting = { kind: 'note', id: note.id, label: (note.body ?? '').slice(0, 60) }"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                    {{ t('common.delete') }}
                  </button>
                </div>
              </div>
            </template>
          </li>
        </ul>
      </section>
    </div>

    <!-- Náhled -->
    <Modal
      v-if="preview"
      :title="preview.document.title"
      width-class="max-w-6xl"
      @close="closePreview"
    >
      <img v-if="preview.isImage" :src="preview.url" :alt="preview.document.title" class="mx-auto max-h-[75vh] max-w-full object-contain">
      <iframe
        v-else
        :src="`${preview.url}#view=FitH`"
        :title="preview.document.title"
        class="h-[75vh] w-full rounded-md border border-neutral-200"
        data-test="personnel-preview-frame"
      />
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="download(preview.document)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
            {{ t('payroll.personnel_file.action.download') }}
          </button>
          <button type="button" :class="[btnFilled('neutral'), 'whitespace-nowrap']" @click="closePreview">
            {{ t('common.close') }}
          </button>
        </div>
      </template>
    </Modal>

    <!-- Úprava údajů dokumentu -->
    <Modal v-if="editing" :title="t('payroll.personnel_file.edit_title')" width-class="max-w-xl" @close="editing = null">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <label :class="LABEL">
          {{ t('payroll.personnel_file.field.category') }}
          <select v-model="editing.meta.category" :class="FIELD" :disabled="busy">
            <option v-for="category in PERSONNEL_DOCUMENT_CATEGORIES" :key="category" :value="category">{{ categoryLabel(category) }}</option>
          </select>
        </label>
        <label :class="LABEL">
          {{ t('payroll.personnel_file.field.title') }}
          <input v-model="editing.meta.title" type="text" maxlength="255" :class="FIELD" :disabled="busy">
        </label>
        <label :class="LABEL">
          {{ t('payroll.personnel_file.field.document_date') }}
          <DateInput v-model="editing.meta.document_date" :class="FIELD" :disabled="busy" />
        </label>
        <label :class="LABEL">
          {{ t('payroll.personnel_file.field.valid_until') }}
          <DateInput v-model="editing.meta.valid_until" :class="FIELD" :disabled="busy" />
        </label>
        <label :class="[LABEL, 'md:col-span-2']">
          {{ t('payroll.personnel_file.field.note') }}
          <textarea v-model="editing.meta.note" rows="3" maxlength="1000" :class="FIELD" :disabled="busy" />
        </label>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="editing = null">
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="[btnFilled('primary'), 'whitespace-nowrap']" :disabled="busy" @click="saveEdit">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
        </div>
      </template>
    </Modal>

    <!-- Potvrzení smazání -->
    <Modal v-if="deleting" :title="t('payroll.personnel_file.delete_title')" width-class="max-w-md" @close="deleting = null">
      <p class="text-sm text-neutral-700">
        {{ deleting.kind === 'document'
          ? t('payroll.personnel_file.delete_document_confirm', { title: deleting.label })
          : t('payroll.personnel_file.delete_note_confirm') }}
      </p>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="deleting = null">
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="[btnFilled('danger'), 'whitespace-nowrap']" :disabled="busy" data-test="personnel-delete-confirm" @click="confirmDelete">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
            {{ t('common.delete') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
