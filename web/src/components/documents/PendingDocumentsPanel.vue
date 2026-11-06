<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { documentsApi, type DocItem, type EntityType } from '@/api/documents'
import { docTypeBadge, formatBytes } from './docFormat'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

// Dokumenty k dokladu, který ještě neexistuje: soubory k nahrání a existující
// dokumenty k připojení. Rodič po uložení dokladu zavolá `commitPendingDocuments`.
export interface PendingDocuments {
  files: File[]
  existing: DocItem[]
}

const model = defineModel<PendingDocuments>({ required: true })
defineProps<{ title?: string; folderPath?: string }>()

const { t } = useI18n()
const auth = useAuthStore()

const fileInput = ref<HTMLInputElement | null>(null)
const searchInput = ref<HTMLInputElement | null>(null)
const attaching = ref(false)
const query = ref('')
const candidates = ref<DocItem[]>([])
let debounce: ReturnType<typeof setTimeout> | null = null

const canUpload = computed(() => auth.canWrite('documents.upload') && auth.canWrite('documents.move'))
const canAttach = computed(() => auth.canWrite('documents.move'))
const count = computed(() => model.value.files.length + model.value.existing.length)

function onFiles(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  if (files.length) model.value = { ...model.value, files: [...model.value.files, ...files] }
}

function removeFile(index: number) {
  model.value = { ...model.value, files: model.value.files.filter((_, i) => i !== index) }
}

function toggleAttach() {
  attaching.value = !attaching.value
  if (attaching.value) nextTick(() => searchInput.value?.focus())
}

watch(query, (q) => {
  if (debounce) clearTimeout(debounce)
  if (q.trim().length < 2) { candidates.value = []; return }
  debounce = setTimeout(async () => {
    const chosen = new Set(model.value.existing.map(d => d.id))
    candidates.value = (await documentsApi.search(q.trim())).filter(d => !chosen.has(d.id))
  }, 220)
})

function attach(doc: DocItem) {
  model.value = { ...model.value, existing: [...model.value.existing, doc] }
  query.value = ''
  candidates.value = []
  attaching.value = false
}

function removeExisting(id: number) {
  model.value = { ...model.value, existing: model.value.existing.filter(d => d.id !== id) }
}
</script>

<script lang="ts">
/** Nahraje čekající soubory do složky a připojí je i vybrané dokumenty k uloženému dokladu. */
export async function commitPendingDocuments(
  pending: { files: File[]; existing: { id: number }[] },
  entityType: EntityType,
  entityId: number,
  folderPath?: string,
): Promise<{ failed: number }> {
  let failed = 0
  const ids = pending.existing.map(d => d.id)
  if (pending.files.length) {
    try {
      const r = await documentsApi.upload(pending.files, {
        zipMode: 'keep',
        ...(folderPath ? { relpaths: pending.files.map(() => folderPath) } : {}),
      })
      ids.push(...(r.root_ids ?? []))
      failed += r.errors.length
    } catch {
      failed += pending.files.length
    }
  }
  for (const id of ids) {
    try {
      await documentsApi.addLink(id, entityType, entityId)
    } catch {
      failed++
    }
  }
  return { failed }
}
</script>

<template>
  <section class="rounded-lg border border-neutral-200 bg-surface p-4" data-test="pending-documents">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="font-semibold">{{ title ?? t('documents.panel_title') }}<span v-if="count" class="ml-1 text-neutral-500">({{ count }})</span></h2>
        <p v-if="folderPath && model.files.length" class="mt-0.5 text-xs text-neutral-500" data-test="pending-documents-folder">{{ t('pending_documents.folder_hint', { folder: folderPath }) }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <template v-if="canUpload">
          <input ref="fileInput" type="file" multiple accept="application/pdf,image/*" class="hidden" data-test="pending-documents-file" @change="onFiles" />
          <button type="button" :class="[btnOutline('primary'), 'whitespace-nowrap']" data-test="pending-documents-upload" @click="fileInput?.click()">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            {{ t('linked_documents.upload') }}
          </button>
        </template>
        <button v-if="canAttach" type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" data-test="pending-documents-attach" @click="toggleAttach">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t('documents.panel_attach') }}
        </button>
      </div>
    </div>

    <div v-if="attaching" class="mb-3">
      <input ref="searchInput" v-model="query" type="text" data-test="pending-documents-search"
        class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('documents.search_placeholder')" />
      <ul v-if="candidates.length" class="mt-1 max-h-56 divide-y overflow-auto rounded-lg border border-neutral-200">
        <li v-for="c in candidates" :key="c.id" class="flex cursor-pointer items-center gap-2 px-2 py-1.5 hover:bg-neutral-50" @click="attach(c)">
          <span :class="['shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold', docTypeBadge(c.doc_type).class]">{{ docTypeBadge(c.doc_type).label }}</span>
          <span class="min-w-0 flex-1 truncate text-sm text-neutral-700">{{ c.title }}</span>
          <span class="max-w-[40%] shrink-0 truncate text-xs text-neutral-500">{{ c.folder_path || t('documents.root') }}</span>
          <span class="w-20 shrink-0 text-right font-mono text-xs text-neutral-500">{{ formatDate(c.created_at) }}</span>
        </li>
      </ul>
    </div>

    <ul v-if="count" class="space-y-1">
      <li v-for="(f, i) in model.files" :key="`f${i}`" class="flex items-center gap-3 rounded px-2 py-1.5 hover:bg-neutral-50" data-test="pending-documents-file-row">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-primary-50 text-[9px] font-semibold text-primary-700">{{ t('pending_documents.new_badge') }}</span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm text-neutral-800">{{ f.name }}</span>
          <span class="block text-xs text-neutral-400">{{ formatBytes(f.size) }}</span>
        </span>
        <button type="button" class="text-neutral-400 hover:text-danger-600" :title="t('common.remove')" @click="removeFile(i)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        </button>
      </li>
      <li v-for="d in model.existing" :key="`d${d.id}`" class="flex items-center gap-3 rounded px-2 py-1.5 hover:bg-neutral-50" data-test="pending-documents-existing-row">
        <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded text-[9px] font-semibold', docTypeBadge(d.doc_type).class]">{{ docTypeBadge(d.doc_type).label }}</span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm text-neutral-800">{{ d.title }}</span>
          <span class="block truncate text-xs text-neutral-400">{{ d.folder_path || t('documents.root') }}</span>
        </span>
        <button type="button" class="text-neutral-400 hover:text-danger-600" :title="t('common.remove')" @click="removeExisting(d.id)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        </button>
      </li>
    </ul>
    <p v-else class="text-sm text-neutral-500">{{ t('pending_documents.empty') }}</p>
  </section>
</template>
