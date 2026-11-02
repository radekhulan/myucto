<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { otherItemKindsApi, otherItemsApi, type OtherItem, type OtherItemKind, type OtherItemSide } from '@/api/otherItems'
import { kindSettingLabel, otherItemKindLabel } from '@/utils/otherItemKind'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import OtherItemKindsModal from '@/components/accounting/OtherItemKindsModal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import type { SortPref } from '@/api/preferences'
import { useFillViewportHeight } from '@/composables/useFillViewportHeight'
import { useScrollLoadMore } from '@/composables/useScrollLoadMore'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(false)
const loadingMore = ref(false)
const listBox = ref<HTMLElement | null>(null)
const loadMoreTarget = ref<HTMLElement | null>(null)
const sort = ref<SortPref | null>(null)
useFillViewportHeight(listBox)
const removingId = ref<number | null>(null)
const showKinds = ref(false)
const items = ref<OtherItem[]>([])
const sources = ref<OtherItem[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const side = ref<'' | OtherItemSide>(route.query.side === 'receivable' || route.query.side === 'payable' ? route.query.side : '')
const status = ref<'open' | 'all'>('open')
const source = ref('')
/** Filtr druhu `strana|kód` vestavěného druhu nebo `strana|custom:<id>` vlastního — druh patří vždy jedné straně. */
const kindFilter = ref('')
const kinds = ref<OtherItemKind[]>([])
const search = ref('')
const searchPending = ref(false)
const dueFrom = ref('')
const dueTo = ref('')
let searchTimer: ReturnType<typeof setTimeout> | null = null
let loadRequest = 0

const pages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const canLoadMore = computed(() => !loading.value && !loadingMore.value && !searchPending.value && page.value < pages.value)
useScrollLoadMore(loadMoreTarget, () => canLoadMore.value, () => load(true))

function toggleSort(key: string) {
  sort.value = sort.value?.key !== key ? { key, dir: 'desc' }
    : sort.value.dir === 'desc' ? { key, dir: 'asc' } : null
}

function onListScroll(event: Event) {
  const el = event.currentTarget as HTMLElement
  if (canLoadMore.value && el.scrollHeight - el.scrollTop - el.clientHeight < 240) void load(true)
}
const CUSTOM_PREFIX = 'custom:'
const BUILTIN_KINDS = ['rent', 'deposit', 'loan', 'insurance', 'fee', 'claim', 'other'] as const
// Druhy po stranách se stejnými názvy jako v editoru; se zvoleným směrem jen jeho strana.
// Skryté druhy se nabízejí také, aby šly dohledat jejich starší doklady.
const kindGroups = computed(() => (side.value ? [side.value] : (['payable', 'receivable'] as OtherItemSide[])).map(groupSide => ({
  side: groupSide,
  options: [
    ...BUILTIN_KINDS.map(code => ({ value: `${groupSide}|${code}`, label: t(`other_items.kind_by_side.${groupSide}.${code}`) })),
    ...kinds.value.filter(kind => !kind.builtin && kind.side === groupSide)
      .map(kind => ({ value: `${groupSide}|${CUSTOM_PREFIX}${kind.id}`, label: kindSettingLabel(t, kind) })),
  ],
})))
const kindQuery = computed(() => {
  if (!kindFilter.value) return {}
  const [kindSide, kind] = kindFilter.value.split('|') as [OtherItemSide, string]
  return {
    side: kindSide,
    ...(kind.startsWith(CUSTOM_PREFIX) ? { kind_id: Number(kind.slice(CUSTOM_PREFIX.length)) } : { kind }),
  }
})
const visibleItems = computed(() => source.value === '' || source.value === 'manual' ? items.value : [])
const visibleSources = computed(() => source.value === '' ? sources.value : sources.value.filter(item =>
  source.value === 'tax'
    ? ['tax_advance', 'vat_forecast', 'dppo_forecast'].includes(item.source_kind)
    : ['payroll', 'payroll_forecast'].includes(item.source_kind) && source.value === 'payroll',
))
const actions = computed<ActionItem[]>(() => [
  { key: 'new', label: t('other_items.new'), icon: 'plus', tier: 'primary', variant: 'primary',
    show: auth.canWrite('other_items'), to: { name: 'other-item-new', query: side.value ? { side: side.value } : {} } },
  { key: 'kinds', label: t('other_items.kinds.title'), icon: 'tag', variant: 'neutral', run: () => { showKinds.value = true } },
])

function internalSourceUrl(item: OtherItem): string | null {
  const url = item.source_url
  return url && url.startsWith('/') && !url.startsWith('//') ? url : null
}

function itemRoute(item: OtherItem) {
  if (item.source_kind === 'manual') return { name: 'other-item-detail', params: { id: item.source_id } }
  return internalSourceUrl(item)
}

function label(group: 'kind' | 'source' | 'status', value: string): string {
  const key = `other_items.${group}.${value}`
  return t(key) === key ? value.replaceAll('_', ' ') : t(key)
}

function badgeClass(item: OtherItem): string {
  if (item.status === 'paid' || item.status === 'confirmed' || item.status === 'posted') return 'bg-success-50 text-success-700'
  if (item.status === 'reversed' || item.status === 'cancelled') return 'bg-neutral-100 text-neutral-500'
  if (item.status === 'draft') return 'bg-warning-50 text-warning-700'
  return 'bg-primary-50 text-primary-700'
}

function isEstimate(item: OtherItem): boolean {
  return item.certainty === 'estimate' || item.status === 'forecast'
}

async function load(append = false) {
  if (append && !canLoadMore.value) return
  const request = ++loadRequest
  if (append) loadingMore.value = true
  else {
    loading.value = true
    loadingMore.value = false
    page.value = 1
  }
  const nextPage = append ? page.value + 1 : 1
  try {
    const result = await otherItemsApi.list({
      ...(side.value ? { side: side.value } : {}), status: status.value, ...kindQuery.value,
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
      ...(dueFrom.value ? { from: dueFrom.value } : {}),
      ...(dueTo.value ? { to: dueTo.value } : {}),
      page: nextPage,
      ...(sort.value ? { sort_by: sort.value.key, sort_dir: sort.value.dir } : {}),
    })
    if (request !== loadRequest) return
    items.value = append ? [...items.value, ...result.items] : result.items
    page.value = nextPage
    sources.value = result.sources || []
    total.value = result.total
    perPage.value = result.per_page || 50
  } catch (error: any) {
    if (request !== loadRequest) return
    if (!append) {
      items.value = []
      sources.value = []
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

function reloadFirstPage() {
  void load()
}

async function removeItem(item: OtherItem) {
  const id = Number(item.source_id)
  if (item.status !== 'draft' || !auth.canWrite('other_items') || removingId.value !== null
    || !window.confirm(t('other_items.confirm_delete'))) return
  removingId.value = id
  try {
    await otherItemsApi.remove(id)
    toast.success(t('common.deleted'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    removingId.value = null
  }
}

// Druh druhé strany po změně směru nedává smysl.
watch(side, () => {
  if (side.value && kindFilter.value && !kindFilter.value.startsWith(`${side.value}|`)) kindFilter.value = ''
})
watch([side, status, kindFilter, dueFrom, dueTo, sort], reloadFirstPage)
watch(search, () => {
  loadRequest++
  loadingMore.value = false
  searchPending.value = true
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { searchPending.value = false; reloadFirstPage() }, 300)
})
onBeforeUnmount(() => { loadRequest++; if (searchTimer) clearTimeout(searchTimer) })
async function loadKinds() {
  try {
    kinds.value = await otherItemKindsApi.list()
  } catch {
    kinds.value = []
  }
}
// Nový nebo přejmenovaný druh se má hned objevit i ve filtru.
function onKindsChanged() {
  void loadKinds()
  reloadFirstPage()
}
onMounted(() => { void load(); void loadKinds() })
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('other_items.title') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('other_items.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.side_label') }}
        <select v-model="side" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="">{{ t('common.all') }}</option>
          <option value="receivable">{{ t('other_items.side.receivable') }}</option>
          <option value="payable">{{ t('other_items.side.payable') }}</option>
        </select>
      </label>
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.status_label') }}
        <select v-model="status" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="open">{{ t('other_items.open') }}</option>
          <option value="all">{{ t('common.all') }}</option>
        </select>
      </label>
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.kind_label') }}
        <select v-model="kindFilter" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="">{{ t('common.all') }}</option>
          <optgroup v-for="group in kindGroups" :key="group.side" :label="t(`other_items.side.${group.side}`)">
            <option v-for="option in group.options" :key="option.value" :value="option.value">{{ option.label }}</option>
          </optgroup>
        </select>
      </label>
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.source_label') }}
        <select v-model="source" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
          <option value="">{{ t('common.all') }}</option>
          <option value="manual">{{ t('other_items.source.manual') }}</option>
          <option value="payroll">{{ t('other_items.source.payroll') }}</option>
          <option value="tax">{{ t('other_items.source.tax') }}</option>
        </select>
      </label>
      <label class="min-w-52 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.search') }}
        <input v-model="search" type="search" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800" />
      </label>
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.due_from') }}
        <DateInput v-model="dueFrom" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2 text-sm" />
      </label>
      <label class="min-w-40 flex-1 text-xs font-medium text-neutral-500">
        {{ t('other_items.due_to') }}
        <DateInput v-model="dueTo" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2 text-sm" />
      </label>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="visibleItems.length === 0 && visibleSources.length === 0" dense accent="primary" icon="doc" :title="t('other_items.empty')" />
    <template v-else>
      <h2 v-if="visibleSources.length && source !== 'payroll' && source !== 'tax'" class="mb-2 text-sm font-semibold">{{ t('other_items.manual_section') }}</h2>
      <EmptyState v-if="visibleItems.length === 0 && source === ''" dense accent="neutral" icon="doc" :title="t('other_items.no_manual')" />
      <div v-if="visibleItems.length" ref="listBox" class="hidden overflow-auto scrollbar-slim rounded-lg border border-neutral-200 bg-surface lg:block" @scroll.passive="onListScroll">
        <table class="w-full text-sm table-sticky-first">
          <thead class="sticky top-0 z-20 bg-neutral-50 text-xs text-neutral-500 shadow-sm">
            <tr>
              <SortableTh :label="t('other_items.document_no')" sort-key="document_no" :sort="sort" @toggle="toggleSort" />
              <SortableTh :label="t('other_items.item')" sort-key="title" :sort="sort" @toggle="toggleSort" />
              <SortableTh :label="t('other_items.partner')" sort-key="partner_name" :sort="sort" @toggle="toggleSort" />
              <SortableTh :label="t('other_items.due_on')" sort-key="due_on" :sort="sort" @toggle="toggleSort" />
              <th class="px-3 py-2 text-left font-medium">{{ t('other_items.source_label') }}</th>
              <SortableTh :label="t('other_items.amount')" sort-key="amount" :sort="sort" align="right" @toggle="toggleSort" />
              <SortableTh :label="t('other_items.remaining')" sort-key="remaining_amount" :sort="sort" align="right" @toggle="toggleSort" />
              <SortableTh :label="t('other_items.status_label')" sort-key="status" :sort="sort" @toggle="toggleSort" />
              <th class="px-3 py-2 text-left font-medium">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in visibleItems" :key="item.id" class="hover:bg-neutral-50">
              <td class="whitespace-nowrap px-3 py-3 font-mono text-xs">{{ item.document_no || '–' }}</td>
              <td class="px-3 py-3">
                <RouterLink v-if="itemRoute(item)" :to="itemRoute(item)!" class="font-medium text-primary-700 hover:underline">{{ item.title }}</RouterLink>
                <span v-else class="font-medium">{{ item.title }}</span>
                <div class="mt-0.5 text-xs text-neutral-500">{{ t(`other_items.side.${item.side}`) }} · {{ otherItemKindLabel(t, item) }}</div>
              </td>
              <td class="px-3 py-3">{{ item.partner_name || t('other_items.no_partner') }}</td>
              <td class="whitespace-nowrap px-3 py-3">{{ item.due_on ? formatDate(item.due_on) : '–' }}</td>
              <td class="px-3 py-3">{{ label('source', item.source_kind) }}<span v-if="item.document_count" class="ml-1 text-neutral-500">· {{ item.document_count }} {{ t('other_items.documents') }}</span></td>
              <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ formatMoney(item.amount, item.currency) }}</td>
              <td class="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums">{{ formatMoney(item.remaining_amount, item.currency) }}</td>
              <td class="px-3 py-3"><span class="rounded px-2 py-1 text-xs font-medium" :class="badgeClass(item)">{{ label('status', item.status) }}</span><span v-if="isEstimate(item)" class="ml-1 text-xs text-neutral-500">{{ t('other_items.forecast') }}</span></td>
              <td class="px-3 py-3">
                <div class="flex flex-wrap gap-1.5">
                  <RouterLink :to="{ name: 'other-item-detail', params: { id: item.source_id } }" :class="btnOutlineSm('primary')">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                    {{ t('common.detail') }}
                  </RouterLink>
                  <RouterLink v-if="item.status === 'draft' && auth.canWrite('other_items')" :to="{ name: 'other-item-edit', params: { id: item.source_id } }" :class="btnOutlineSm('neutral')">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                    {{ t('common.edit') }}
                  </RouterLink>
                  <button v-if="item.status === 'draft' && auth.canWrite('other_items')" type="button" :disabled="removingId !== null" :class="btnOutlineSm('danger')" @click="removeItem(item)">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="visibleItems.length" class="space-y-2 lg:hidden">
        <div v-for="item in visibleItems" :key="item.id" class="rounded-lg border border-neutral-200 bg-surface p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <RouterLink v-if="itemRoute(item)" :to="itemRoute(item)!" class="font-semibold text-primary-700">{{ item.title }}</RouterLink>
              <span v-else class="font-semibold">{{ item.title }}</span>
              <div class="text-xs text-neutral-500"><span v-if="item.document_no" class="font-mono">{{ item.document_no }} · </span>{{ t(`other_items.side.${item.side}`) }} · {{ otherItemKindLabel(t, item) }}</div>
              <span v-if="isEstimate(item)" class="mt-1 inline-block rounded bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">{{ t('other_items.forecast') }}</span>
            </div>
            <span class="rounded px-2 py-1 text-xs font-medium" :class="badgeClass(item)">{{ label('status', item.status) }}</span>
          </div>
          <div class="mt-3 flex flex-wrap justify-between gap-2 text-sm"><span>{{ item.partner_name || t('other_items.no_partner') }}</span><span>{{ item.due_on ? formatDate(item.due_on) : '–' }}</span></div>
          <div class="mt-2 flex flex-wrap justify-between gap-2 border-t border-neutral-100 pt-2 text-sm"><span>{{ t('other_items.remaining') }}</span><strong class="tabular-nums">{{ formatMoney(item.remaining_amount, item.currency) }}</strong></div>
          <div class="mt-3 flex flex-wrap gap-1.5">
            <RouterLink :to="{ name: 'other-item-detail', params: { id: item.source_id } }" :class="btnOutlineSm('primary')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
              {{ t('common.detail') }}
            </RouterLink>
            <RouterLink v-if="item.status === 'draft' && auth.canWrite('other_items')" :to="{ name: 'other-item-edit', params: { id: item.source_id } }" :class="btnOutlineSm('neutral')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              {{ t('common.edit') }}
            </RouterLink>
            <button v-if="item.status === 'draft' && auth.canWrite('other_items')" type="button" :disabled="removingId !== null" :class="btnOutlineSm('danger')" @click="removeItem(item)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
      <div v-if="visibleItems.length && page < pages" ref="loadMoreTarget" class="flex flex-wrap items-center justify-center gap-3 text-sm pointer-fine-hidden">
        <button type="button" class="my-4" :class="btnOutlineSm('neutral')" :disabled="loadingMore" @click="load(true)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t(loadingMore ? 'common.loading' : 'common.load_more') }}
        </button>
        <span>{{ items.length }} / {{ total }}</span>
      </div>
      <section v-if="visibleSources.length" class="mt-6">
        <h2 class="mb-1 text-lg font-semibold">{{ t('other_items.sources_section') }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t('other_items.sources_hint') }}</p>
        <div class="grid gap-2 md:grid-cols-2">
          <div v-for="item in visibleSources" :key="item.id" class="rounded-lg border border-neutral-200 bg-surface p-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <RouterLink v-if="internalSourceUrl(item)" :to="internalSourceUrl(item)!" class="font-medium text-primary-700 hover:underline">{{ item.title }}</RouterLink>
                <span v-else class="font-medium">{{ item.title }}</span>
                <div class="mt-0.5 text-xs text-neutral-500">{{ label('source', item.source_kind) }} · {{ item.due_on ? formatDate(item.due_on) : '–' }}</div>
              </div>
              <div class="text-right"><strong class="block text-sm tabular-nums">{{ formatMoney(item.remaining_amount, item.currency) }}</strong><span v-if="isEstimate(item)" class="mt-1 inline-block rounded bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">{{ t('other_items.forecast') }}</span></div>
            </div>
            <div class="mt-2 flex flex-wrap gap-2 text-xs text-neutral-500"><span v-if="item.partner_name">{{ item.partner_name }}</span><span v-if="!isEstimate(item)">{{ label('status', item.status) }}</span></div>
          </div>
        </div>
      </section>
    </template>
    <OtherItemKindsModal v-if="showKinds" @close="showKinds = false" @changed="onKindsChanged" />
  </div>
</template>
