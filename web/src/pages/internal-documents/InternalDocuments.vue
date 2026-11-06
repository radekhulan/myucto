<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  internalDocumentsApi, INTERNAL_DOCUMENT_KINDS,
  type InternalDocument, type InternalDocumentFilters, type InternalDocumentKind, type InternalDocumentStatus,
} from '@/api/internalDocuments'
import type { Client } from '@/api/clients'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { downloadApiFile } from '@/utils/downloadFile'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import type { SortPref } from '@/api/preferences'
import { useFillViewportHeight } from '@/composables/useFillViewportHeight'
import { useScrollLoadMore } from '@/composables/useScrollLoadMore'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(false)
const loadingMore = ref(false)
const exporting = ref(false)
const listBox = ref<HTMLElement | null>(null)
const loadMoreTarget = ref<HTMLElement | null>(null)
useFillViewportHeight(listBox)
const sort = ref<SortPref | null>(null)
const items = ref<InternalDocument[]>([])
const total = ref(0)
const postedAmount = ref(0)
const page = ref(1)
const perPage = ref(50)
const removingId = ref<number | null>(null)
const status = ref<'' | InternalDocumentStatus>('')
const kind = ref<'' | InternalDocumentKind>('')
const partnerId = ref<number | null>(null)
const partnerLabel = ref('')
const search = ref('')
const searchPending = ref(false)
const dateFrom = ref('')
const dateTo = ref('')
let searchTimer: ReturnType<typeof setTimeout> | null = null
let loadRequest = 0

const canWrite = computed(() => auth.canWrite('accounting.journal.write'))
const pages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const canLoadMore = computed(() => !loading.value && !loadingMore.value && !searchPending.value && page.value < pages.value)
useScrollLoadMore(loadMoreTarget, () => canLoadMore.value, () => load(true))

const filters = computed<InternalDocumentFilters>(() => ({
  ...(status.value ? { status: status.value } : { status: 'all' as const }),
  ...(kind.value ? { kind: kind.value } : {}),
  ...(partnerId.value ? { partner_id: partnerId.value } : {}),
  ...(search.value.trim() ? { q: search.value.trim() } : {}),
  ...(dateFrom.value ? { from: dateFrom.value } : {}),
  ...(dateTo.value ? { to: dateTo.value } : {}),
  ...(sort.value ? { sort_by: sort.value.key, sort_dir: sort.value.dir } : {}),
}))

const actions = computed<ActionItem[]>(() => [
  { key: 'new', label: t('internal_documents.new'), icon: 'plus', tier: 'primary', variant: 'primary',
    show: canWrite.value, to: { name: 'accounting-internal-document-new' } },
  { key: 'export', label: t('internal_documents.export_csv'), icon: 'download', tier: 'secondary', variant: 'neutral',
    disabled: exporting.value || total.value === 0, run: () => void exportCsv() },
])

function toggleSort(key: string) {
  sort.value = sort.value?.key !== key ? { key, dir: 'desc' }
    : sort.value.dir === 'desc' ? { key, dir: 'asc' } : null
}

function onListScroll(event: Event) {
  const el = event.currentTarget as HTMLElement
  if (canLoadMore.value && el.scrollHeight - el.scrollTop - el.clientHeight < 240) void load(true)
}

function badgeClass(doc: InternalDocument): string {
  if (doc.status === 'posted') return 'bg-success-50 text-success-700'
  if (doc.status === 'reversed') return 'bg-neutral-100 text-neutral-500'
  return 'bg-warning-50 text-warning-700'
}

function onPartnerSelected(client: Client | null) {
  partnerLabel.value = client?.company_name || ''
}

async function load(append = false) {
  if (append && !canLoadMore.value) return
  const request = ++loadRequest
  if (append) loadingMore.value = true
  else {
    loading.value = true
    loadingMore.value = false
  }
  const nextPage = append ? page.value + 1 : 1
  try {
    const result = await internalDocumentsApi.list({ ...filters.value, page: nextPage })
    if (request !== loadRequest) return
    items.value = append ? [...items.value, ...result.items] : result.items
    page.value = nextPage
    total.value = result.total
    perPage.value = result.per_page || 50
    postedAmount.value = result.totals?.posted_amount ?? 0
  } catch (error: any) {
    if (request !== loadRequest) return
    if (!append) {
      items.value = []
      total.value = 0
    }
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (request === loadRequest) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

async function exportCsv() {
  if (exporting.value) return
  exporting.value = true
  try {
    await downloadApiFile(internalDocumentsApi.exportPath(filters.value), 'interni-doklady.csv')
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

async function removeDraft(doc: InternalDocument) {
  if (doc.status !== 'draft' || !canWrite.value || removingId.value !== null
    || !window.confirm(t('internal_documents.confirm_delete'))) return
  removingId.value = doc.id
  try {
    await internalDocumentsApi.remove(doc.id)
    toast.success(t('common.deleted'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    removingId.value = null
  }
}

watch([status, kind, partnerId, dateFrom, dateTo, sort], () => void load())
watch(search, () => {
  loadRequest++
  loadingMore.value = false
  searchPending.value = true
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { searchPending.value = false; void load() }, 300)
})
onBeforeUnmount(() => { loadRequest++; if (searchTimer) clearTimeout(searchTimer) })
onMounted(() => void load())
// V KeepAlive se seznam po návratu z detailu (zaúčtování, storno) načte znovu.
let mounted = false
onActivated(() => { if (mounted) void load(); mounted = true })
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('internal_documents.title') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('internal_documents.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
      <label class="min-w-36 flex-1 text-xs font-medium text-neutral-500">
        {{ t('internal_documents.status_label') }}
        <select v-model="status" data-test="filter-status" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="">{{ t('common.all') }}</option>
          <option value="draft">{{ t('internal_documents.status.draft') }}</option>
          <option value="posted">{{ t('internal_documents.status.posted') }}</option>
          <option value="reversed">{{ t('internal_documents.status.reversed') }}</option>
        </select>
      </label>
      <label class="min-w-36 flex-1 text-xs font-medium text-neutral-500">
        {{ t('internal_documents.kind_label') }}
        <select v-model="kind" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="">{{ t('common.all') }}</option>
          <option v-for="k in INTERNAL_DOCUMENT_KINDS" :key="k" :value="k">{{ t(`internal_documents.kind.${k}`) }}</option>
        </select>
      </label>
      <div class="min-w-52 flex-1 text-xs font-medium text-neutral-500">
        <span>{{ t('internal_documents.partner') }}</span>
        <ClientSearchSelect v-model="partnerId" :selected-label="partnerLabel" class="mt-1 block" @selected="onPartnerSelected" />
      </div>
      <label class="min-w-52 flex-1 text-xs font-medium text-neutral-500">
        {{ t('internal_documents.search') }}
        <input v-model="search" type="search" data-test="filter-search" :placeholder="t('internal_documents.search_placeholder')"
          class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800" />
      </label>
      <label class="min-w-36 flex-1 text-xs font-medium text-neutral-500">
        {{ t('internal_documents.date_from') }}
        <DateInput v-model="dateFrom" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2 text-sm" />
      </label>
      <label class="min-w-36 flex-1 text-xs font-medium text-neutral-500">
        {{ t('internal_documents.date_to') }}
        <DateInput v-model="dateTo" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2 text-sm" />
      </label>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="items.length === 0" dense accent="primary" icon="doc" :title="t('internal_documents.empty')"
      :message="t('internal_documents.empty_hint')" />
    <template v-else>
      <div ref="listBox" class="hidden overflow-auto scrollbar-slim rounded-lg border border-neutral-200 bg-surface lg:block" @scroll.passive="onListScroll">
        <table class="w-full text-sm table-sticky-first">
          <thead class="sticky top-0 z-20 bg-neutral-50 text-xs text-neutral-500 shadow-sm">
            <tr>
              <SortableTh :label="t('internal_documents.document_no')" sort-key="document_no" :sort="sort" @toggle="toggleSort" />
              <SortableTh :label="t('internal_documents.document_date')" sort-key="document_date" :sort="sort" @toggle="toggleSort" />
              <SortableTh :label="t('internal_documents.item')" sort-key="title" :sort="sort" @toggle="toggleSort" />
              <SortableTh :label="t('internal_documents.partner')" sort-key="partner_name" :sort="sort" @toggle="toggleSort" />
              <SortableTh :label="t('internal_documents.amount')" sort-key="amount" :sort="sort" align="right" @toggle="toggleSort" />
              <SortableTh :label="t('internal_documents.status_label')" sort-key="status" :sort="sort" @toggle="toggleSort" />
              <th class="px-3 py-2 text-left font-medium">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="doc in items" :key="doc.id" class="hover:bg-neutral-50" data-test="internal-document-row">
              <td class="whitespace-nowrap px-3 py-3 font-mono text-xs">
                <RouterLink :to="{ name: 'accounting-internal-document-detail', params: { id: doc.id } }" class="font-medium text-primary-700 hover:underline">
                  {{ doc.document_no || t('internal_documents.no_number') }}
                </RouterLink>
              </td>
              <td class="whitespace-nowrap px-3 py-3">{{ formatDate(doc.document_date) }}</td>
              <td class="px-3 py-3">
                <span class="font-medium">{{ doc.title }}</span>
                <div class="mt-0.5 text-xs text-neutral-500">
                  {{ t(`internal_documents.kind.${doc.kind}`) }}
                  <span v-if="doc.document_count"> · {{ t('internal_documents.document_count', { count: doc.document_count }) }}</span>
                </div>
              </td>
              <td class="px-3 py-3">{{ doc.partner_name || '–' }}</td>
              <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ formatMoney(doc.amount) }}</td>
              <td class="px-3 py-3"><span class="rounded px-2 py-1 text-xs font-medium" :class="badgeClass(doc)">{{ t(`internal_documents.status.${doc.status}`) }}</span></td>
              <td class="px-3 py-3">
                <div class="flex flex-wrap gap-1.5">
                  <RouterLink :to="{ name: 'accounting-internal-document-detail', params: { id: doc.id } }" :class="btnOutlineSm('primary')">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                    {{ t('common.detail') }}
                  </RouterLink>
                  <RouterLink v-if="doc.status === 'draft' && canWrite" :to="{ name: 'accounting-internal-document-edit', params: { id: doc.id } }" :class="btnOutlineSm('neutral')">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                    {{ t('common.edit') }}
                  </RouterLink>
                  <button v-if="doc.status === 'draft' && canWrite" type="button" :disabled="removingId !== null" :class="btnOutlineSm('danger')" @click="removeDraft(doc)">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="space-y-2 lg:hidden">
        <div v-for="doc in items" :key="doc.id" class="rounded-lg border border-neutral-200 bg-surface p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <RouterLink :to="{ name: 'accounting-internal-document-detail', params: { id: doc.id } }" class="font-semibold text-primary-700">
                {{ doc.document_no || t('internal_documents.no_number') }}
              </RouterLink>
              <div class="truncate text-sm">{{ doc.title }}</div>
              <div class="text-xs text-neutral-500">{{ formatDate(doc.document_date) }} · {{ t(`internal_documents.kind.${doc.kind}`) }}</div>
            </div>
            <span class="rounded px-2 py-1 text-xs font-medium" :class="badgeClass(doc)">{{ t(`internal_documents.status.${doc.status}`) }}</span>
          </div>
          <div class="mt-2 flex flex-wrap justify-between gap-2 border-t border-neutral-100 pt-2 text-sm">
            <span>{{ doc.partner_name || '–' }}</span>
            <strong class="tabular-nums">{{ formatMoney(doc.amount) }}</strong>
          </div>
          <div class="mt-3 flex flex-wrap gap-1.5">
            <RouterLink :to="{ name: 'accounting-internal-document-detail', params: { id: doc.id } }" :class="btnOutlineSm('primary')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
              {{ t('common.detail') }}
            </RouterLink>
            <RouterLink v-if="doc.status === 'draft' && canWrite" :to="{ name: 'accounting-internal-document-edit', params: { id: doc.id } }" :class="btnOutlineSm('neutral')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              {{ t('common.edit') }}
            </RouterLink>
            <button v-if="doc.status === 'draft' && canWrite" type="button" :disabled="removingId !== null" :class="btnOutlineSm('danger')" @click="removeDraft(doc)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm text-neutral-500">
        <span>{{ t('internal_documents.summary', { count: total }) }}</span>
        <span>{{ t('internal_documents.posted_total') }}: <strong class="tabular-nums text-neutral-800">{{ formatMoney(postedAmount) }}</strong></span>
      </div>
      <div v-if="page < pages" ref="loadMoreTarget" class="flex flex-wrap items-center justify-center gap-3 text-sm pointer-fine-hidden">
        <button type="button" class="my-4" :class="btnOutlineSm('neutral')" :disabled="loadingMore" @click="load(true)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t(loadingMore ? 'common.loading' : 'common.load_more') }}
        </button>
        <span>{{ items.length }} / {{ total }}</span>
      </div>
    </template>
  </div>
</template>
