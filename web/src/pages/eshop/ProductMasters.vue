<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { productMastersApi, type ProductMasterListItem } from '@/api/productMasters'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()

const canWrite = computed(() => auth.canWrite('eshop.write') && auth.canWrite('stock.items.write'))
const query = ref(typeof route.query.master_query === 'string' ? route.query.master_query : '')
const status = ref<'active' | 'archived' | 'all'>(['active', 'archived', 'all'].includes(String(route.query.master_status)) ? route.query.master_status as any : 'active')
const page = ref(Math.max(1, Number(route.query.master_page) || 1))
const rows = ref<ProductMasterListItem[]>([])
const pagination = ref({ page: 1, limit: 25, total: 0, pages: 1 })
const loading = ref(false)
const active = ref(true)
let requestGeneration = 0
let controller: AbortController | null = null
let debounceTimer: ReturnType<typeof setTimeout> | null = null

function syncQuery() {
  const next = { ...route.query, tab: 'masters', master_query: query.value || undefined, master_status: status.value, master_page: page.value > 1 ? String(page.value) : undefined }
  void router.replace({ query: next })
}

async function load() {
  if (!active.value) return
  const generation = ++requestGeneration
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  try {
    const result = await productMastersApi.list({ status: status.value, query: query.value, page: page.value, limit: 25 }, controller.signal)
    if (!active.value || generation !== requestGeneration) return
    rows.value = result.items
    pagination.value = result.pagination
  } catch (error: any) {
    if (error?.code !== 'ERR_CANCELED' && generation === requestGeneration) toast.error(apiErrorMessage(error, t('common.error')))
  } finally {
    if (generation === requestGeneration) loading.value = false
  }
}

function reloadFromStart() {
  page.value = 1
  syncQuery()
  void load()
}

function onSearchInput() {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(reloadFromStart, 250)
}

async function toggleArchive(row: ProductMasterListItem) {
  if (!canWrite.value) return
  const promptKey = row.status === 'active' ? 'eshop.masters.archive_confirm' : 'eshop.masters.restore_confirm'
  if (!confirm(t(promptKey, { name: row.name }))) return
  try {
    if (row.status === 'active') await productMastersApi.archive(row.id, row.row_version)
    else await productMastersApi.restore(row.id, row.row_version)
    toast.success(t('common.saved'))
    await load()
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('common.error')))
  }
}

function setPage(next: number) {
  page.value = next
  syncQuery()
  void load()
}

watch(() => supplier.currentSupplierId, () => {
  requestGeneration++
  controller?.abort()
  rows.value = []
  page.value = 1
  void load()
})

watch(() => route.query, value => {
  if (value.tab !== 'masters') return
  const nextQuery = typeof value.master_query === 'string' ? value.master_query : ''
  const nextStatus = ['active', 'archived', 'all'].includes(String(value.master_status)) ? value.master_status as typeof status.value : 'active'
  const nextPage = Math.max(1, Number(value.master_page) || 1)
  if (nextQuery === query.value && nextStatus === status.value && nextPage === page.value) return
  query.value = nextQuery
  status.value = nextStatus
  page.value = nextPage
  void load()
})

onMounted(load)
onActivated(() => { active.value = true; void load() })
onDeactivated(() => { active.value = false; requestGeneration++; controller?.abort() })
onBeforeUnmount(() => {
  active.value = false
  requestGeneration++
  controller?.abort()
  if (debounceTimer) clearTimeout(debounceTimer)
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-2xl font-semibold">{{ t('eshop.masters.title') }}</h2>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('eshop.masters.subtitle') }}</p>
      </div>
      <RouterLink v-if="canWrite" to="/eshop/product-masters/new" :class="btnFilled('primary')">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.masters.new') }}
      </RouterLink>
    </div>

    <div class="mb-4 flex flex-wrap gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
      <label class="min-w-56 flex-1">
        <span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('common.search') }}</span>
        <input v-model="query" type="search" @input="onSearchInput" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
      </label>
      <label class="w-full sm:w-48">
        <span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.masters.filter_status') }}</span>
        <select v-model="status" @change="reloadFromStart" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          <option value="active">{{ t('eshop.masters.status.active') }}</option>
          <option value="archived">{{ t('eshop.masters.status.archived') }}</option>
          <option value="all">{{ t('eshop.masters.status.all') }}</option>
        </select>
      </label>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="rows.length === 0" boxed icon="box" :title="t('eshop.masters.empty_title')" :message="t('eshop.masters.empty_hint')" />
    <div v-else class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
            <tr><th class="px-3 py-2 text-left">{{ t('eshop.masters.name') }}</th><th class="px-3 py-2 text-right">{{ t('eshop.masters.variants') }}</th><th class="px-3 py-2 text-center">{{ t('eshop.masters.filter_status') }}</th><th class="w-44 px-3 py-2"></th></tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50" :class="{ 'opacity-60': row.status === 'archived' }">
              <td class="px-3 py-2 font-medium">{{ row.name }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ row.variant_count }}</td>
              <td class="px-3 py-2 text-center"><span class="rounded px-2 py-0.5 text-xs font-medium" :class="row.status === 'active' ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">{{ t(`eshop.masters.status.${row.status}`) }}</span></td>
              <td class="px-3 py-2"><div class="flex flex-wrap justify-end gap-2">
                <RouterLink :to="`/eshop/product-masters/${row.id}`" :class="btnOutlineSm('primary')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="canWrite ? ICONS.edit : ICONS.eye" /></svg>{{ canWrite ? t('common.edit') : t('common.detail') }}</RouterLink>
                <button v-if="canWrite" type="button" @click="toggleArchive(row)" :class="btnOutlineSm(row.status === 'active' ? 'warning' : 'success')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="row.status === 'active' ? ICONS.archive : ICONS.uturn" /></svg>{{ row.status === 'active' ? t('eshop.masters.archive') : t('eshop.masters.restore') }}</button>
              </div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="pagination.pages > 1" class="flex items-center justify-between border-t border-neutral-200 px-3 py-2 text-sm">
        <button type="button" :disabled="page <= 1" @click="setPage(page - 1)" :class="btnOutlineSm('neutral')"><svg class="h-3.5 w-3.5 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('common.previous') }}</button>
        <span class="text-neutral-500">{{ page }} / {{ pagination.pages }}</span>
        <button type="button" :disabled="page >= pagination.pages" @click="setPage(page + 1)" :class="btnOutlineSm('neutral')">{{ t('common.next') }}<svg class="h-3.5 w-3.5 -rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg></button>
      </div>
    </div>
  </div>
</template>
