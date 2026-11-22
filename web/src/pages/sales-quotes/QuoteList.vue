<script setup lang="ts">
import { computed, onActivated, onMounted, ref, useId, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { salesQuotesApi, quoteStatuses, type QuoteListItem, type QuoteStatus } from '@/api/salesQuotes'
import { clientsApi, type Client } from '@/api/clients'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useWindowTitle } from '@/composables/useWindowTitle'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { useAuthStore } from '@/stores/auth'
import { quoteStatusBadge } from './quotePresentation'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const clientInputId = useId()
const rows = ref<QuoteListItem[]>([])
const clients = ref<Client[]>([])
const clientLookup = ref<Client | null>(null)
const clientsLoading = ref(false)
const clientsError = ref('')
const search = ref('')
const appliedSearch = ref('')
const status = ref<QuoteStatus | ''>('')
const page = ref(1)
const total = ref(0)
const loading = ref(true)
const error = ref('')
const perPage = 30
let request = 0
let clientSearchRequest = 0

useWindowTitle(() => t('nav.sales_quotes'))
const projectId = computed(() => Number(route.query.project_id) || undefined)
const clientId = computed(() => Number(route.query.client_id) || undefined)
const canReadClients = computed(() => auth.canRead('clients'))
const clientOptions = computed(() => clients.value.map(clientOption))
const selectedClient = computed(() => {
  const id = clientId.value
  if (!id) return null
  if (clientLookup.value?.id === id) return clientOption(clientLookup.value)
  const option = clientOptions.value.find(client => client.value === id)
  return option ?? { value: id, label: rows.value.find(row => row.client_id === id)?.client_name || `#${id}` }
})
const pages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
const newQuote = computed(() => ({
  name: 'sales-quote-new',
  query: { project_id: projectId.value, client_id: clientId.value },
}))

function clientOption(client: Client) {
  return {
    value: client.id,
    label: client.company_name || [client.first_name, client.last_name].filter(Boolean).join(' ') || `#${client.id}`,
    secondary: client.ic ?? undefined,
  }
}

async function searchClients(query: string) {
  const current = ++clientSearchRequest
  clientsLoading.value = true
  clientsError.value = ''
  try {
    const result = await clientsApi.list({ q: query, role: 'customers', per_page: 30 })
    if (current === clientSearchRequest) clients.value = result.data
  } catch (error) {
    if (current === clientSearchRequest) {
      clients.value = []
      clientsError.value = apiErrorMessage(error)
    }
  } finally {
    if (current === clientSearchRequest) clientsLoading.value = false
  }
}

function changeClient(id: number | null) {
  void router.replace({ query: { ...route.query, client_id: id === null ? undefined : String(id) } })
}

async function load(silent = false) {
  const current = ++request
  if (!silent) loading.value = true
  error.value = ''
  try {
    const result = await salesQuotesApi.list({
      page: page.value, per_page: perPage, search: appliedSearch.value || undefined,
      status: status.value || undefined, project_id: projectId.value, client_id: clientId.value,
    })
    if (current !== request) return
    rows.value = result.items
    total.value = result.total
  } catch (e) {
    if (current === request) error.value = apiErrorMessage(e)
  } finally {
    if (current === request) loading.value = false
  }
}

function applySearch() {
  page.value = 1
  appliedSearch.value = search.value.trim()
  void load()
}

function changePage(next: number) {
  page.value = next
  void load()
}

watch([clientId, canReadClients], async ([id, canRead], _, onCleanup) => {
  clientLookup.value = null
  if (!id || !canRead) return
  let current = true
  onCleanup(() => { current = false })
  const client = await clientsApi.get(id).catch(() => null)
  if (current) clientLookup.value = client
}, { immediate: true })
watch([projectId, clientId], () => changePage(1))
onMounted(() => { void load() })
useWindowRefresh(() => load(true), () => !loading.value)
onActivated(() => { if (!loading.value) void load() })
</script>

<template>
  <div class="min-w-0">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('nav.sales_quotes') }}</h1>
      <RouterLink v-if="auth.canWrite('sales_quotes.write')" :to="newQuote" :class="[btnFilled('primary'), 'whitespace-nowrap']">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
        {{ t('sales_quotes.new') }}
      </RouterLink>
    </header>

    <form class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-neutral-200 bg-surface p-3" @submit.prevent="applySearch">
      <label class="min-w-40 flex-1 text-sm sm:max-w-sm">
        <span class="mb-1 block font-medium">{{ t('sales_quotes.search') }}</span>
        <input v-model="search" type="search" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3" :placeholder="t('sales_quotes.search_hint')" />
      </label>
      <div v-if="canReadClients || clientId" class="min-w-48 flex-1 text-sm sm:max-w-xs">
        <label :for="clientInputId" class="mb-1 block font-medium">{{ t('sales_quotes.client') }}</label>
        <SearchableSelect :input-id="clientInputId" :model-value="clientId ?? null" :options="clientOptions"
          :selected-option="selectedClient" :disabled="!canReadClients" :placeholder="t('sales_quotes.all_clients')"
          :clear-label="t('sales_quotes.clear_client_filter')" :loading="clientsLoading" :no-results-label="clientsError || t('common.no_data')"
          remote @search="searchClients" @update:model-value="changeClient" />
      </div>
      <label class="text-sm">
        <span class="mb-1 block font-medium">{{ t('sales_quotes.status_label') }}</span>
        <select v-model="status" class="h-9 rounded-md border border-neutral-300 bg-surface px-3" @change="changePage(1)">
          <option value="">{{ t('sales_quotes.all_statuses') }}</option>
          <option v-for="value in quoteStatuses" :key="value" :value="value">{{ t(`sales_quotes.status.${value}`) }}</option>
        </select>
      </label>
      <button type="submit" :class="[btnOutline('neutral'), 'whitespace-nowrap']">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
        {{ t('sales_quotes.search') }}
      </button>
      <RouterLink v-if="projectId || (clientId && !canReadClients)" to="/sales-quotes" class="py-2 text-sm font-medium text-primary-700 hover:underline">
        {{ t('sales_quotes.clear_context') }}
      </RouterLink>
    </form>

    <p v-if="error" role="alert" class="mb-4 rounded-md border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700">{{ error }}</p>
    <p v-else-if="loading" class="p-8 text-center text-sm text-neutral-500" aria-live="polite">{{ t('common.loading') }}</p>
    <p v-else-if="rows.length === 0" class="rounded-lg border border-neutral-200 bg-surface p-8 text-center text-sm text-neutral-500">
      {{ t('sales_quotes.no_results') }}
    </p>
    <div v-else>
      <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 bg-surface md:block">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-700">
            <tr class="[&>th]:whitespace-nowrap [&>th]:px-3 [&>th]:py-2">
              <th class="text-left">{{ t('sales_quotes.number') }}</th>
              <th class="text-left">{{ t('sales_quotes.title') }}</th>
              <th class="text-left">{{ t('sales_quotes.client') }}</th>
              <th class="text-left">{{ t('sales_quotes.status_label') }}</th>
              <th class="text-left">{{ t('sales_quotes.valid_until') }}</th>
              <th class="text-right">{{ t('sales_quotes.amount') }}</th>
              <th class="text-right">{{ t('sales_quotes.billed') }}</th>
              <th class="text-right">{{ t('sales_quotes.remaining') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-200">
            <tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50 [&>td]:px-3 [&>td]:py-2">
              <td class="whitespace-nowrap font-medium">
                <RouterLink :to="{ name: 'sales-quote-detail', params: { id: row.id } }" class="text-primary-700 hover:underline">{{ row.number || '—' }}</RouterLink>
              </td>
              <td><RouterLink :to="{ name: 'sales-quote-detail', params: { id: row.id } }" class="font-medium text-primary-700 hover:underline">{{ row.title }}</RouterLink></td>
              <td>{{ row.client_name }}<span v-if="row.project_name" class="block text-xs text-neutral-500">{{ row.project_name }}</span></td>
              <td><span :class="['whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium', quoteStatusBadge[row.status]]">{{ t(`sales_quotes.status.${row.status}`) }}</span></td>
              <td class="whitespace-nowrap">{{ formatDate(row.valid_until) }}</td>
              <td class="whitespace-nowrap text-right tabular-nums">{{ formatMoney(row.totals?.with_vat, row.currency) }}</td>
              <td class="whitespace-nowrap text-right tabular-nums">{{ row.status === 'accepted' ? formatMoney(row.billing?.billed, row.currency) : '—' }}</td>
              <td class="whitespace-nowrap text-right tabular-nums">{{ row.status === 'accepted' ? formatMoney(row.billing?.remaining, row.currency) : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="space-y-3 md:hidden">
        <RouterLink v-for="row in rows" :key="row.id" :to="{ name: 'sales-quote-detail', params: { id: row.id } }" class="block rounded-lg border border-neutral-200 bg-surface p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="min-w-0 font-medium wrap-anywhere text-primary-700">{{ row.title }}</p>
            <span :class="['whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium', quoteStatusBadge[row.status]]">{{ t(`sales_quotes.status.${row.status}`) }}</span>
          </div>
          <div class="mt-1 text-sm wrap-anywhere text-neutral-600">{{ row.number }} · {{ row.client_name }}</div>
          <p v-if="row.project_name" class="text-xs wrap-anywhere text-neutral-500">{{ row.project_name }}</p>
          <div class="mt-1 flex flex-wrap justify-between gap-2 text-sm">
            <span>{{ t('sales_quotes.valid_until') }} {{ formatDate(row.valid_until) }}</span>
            <span class="font-semibold tabular-nums">{{ formatMoney(row.totals?.with_vat, row.currency) }}</span>
          </div>
          <dl v-if="row.status === 'accepted' && row.billing" class="mt-2 grid grid-cols-2 gap-2 border-t border-neutral-200 pt-2 text-xs">
            <div>
              <dt class="text-neutral-600">{{ t('sales_quotes.billed') }}</dt>
              <dd class="font-medium tabular-nums">{{ formatMoney(row.billing.billed, row.currency) }}</dd>
            </div>
            <div>
              <dt class="text-neutral-600">{{ t('sales_quotes.remaining') }}</dt>
              <dd class="font-medium tabular-nums">{{ formatMoney(row.billing.remaining, row.currency) }}</dd>
            </div>
          </dl>
        </RouterLink>
      </div>
    </div>
    <nav v-if="pages > 1" class="mt-4 flex flex-wrap items-center justify-center gap-3" :aria-label="t('sales_quotes.pagination')">
      <button type="button" :disabled="page <= 1 || loading" :class="btnOutline('neutral')" :aria-label="t('sales_quotes.previous_page')" @click="changePage(page - 1)">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevronLeft" /></svg>
      </button>
      <span class="text-sm tabular-nums">{{ page }} / {{ pages }}</span>
      <button type="button" :disabled="page >= pages || loading" :class="btnOutline('neutral')" :aria-label="t('sales_quotes.next_page')" @click="changePage(page + 1)">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevronRight" /></svg>
      </button>
    </nav>
  </div>
</template>
