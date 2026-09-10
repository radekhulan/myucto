<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { eshopApi, type EshopCurrency, type ProductSetCard, type ProductSetDefinition, type ProductSetGroup } from '@/api/eshop'
import { stockApi, type StockItem, type StockItemSearchResult } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ProductSetQuotePanel from '@/components/eshop/ProductSetQuotePanel.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const itemId = computed(() => Number(route.params.id))
const canWrite = computed(() => auth.canWrite('eshop.write') && auth.canWrite('stock.items.write'))
const FIELD = 'mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20'

const item = ref<StockItem | null>(null)
const currencies = ref<EshopCurrency[]>([])
const cards = ref<ProductSetCard[]>([])
const definitions = ref<Record<string, ProductSetDefinition>>({})
const rowVersion = ref(0)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const searchRows = ref<StockItemSearchResult[]>([])
const searchLoading = ref(false)
const fixedPick = ref<number | null>(null)
const optionPick = ref<Record<number, number | null>>({})
let loadToken = 0

function emptyDefinition(): ProductSetDefinition {
  return { components: [], groups: [], prices: {} }
}
const form = ref<ProductSetDefinition>(emptyDefinition())

const activeCurrencies = computed(() => currencies.value.filter(currency => !currency.archived))
const cardMap = computed(() => new Map(cards.value.map(card => [card.id, card])))
const searchOptions = computed(() => searchRows.value.map(row => ({
  value: row.id,
  label: row.name,
  secondary: `${row.sku} · ${row.unit}`,
})))
const isVirtualCard = computed(() => item.value !== null && !item.value.is_stocked)
const currentCard = computed(() => item.value ? ({
  id: item.value.id, sku: item.value.sku, name: item.value.name, unit: item.value.unit,
  vat_rate_id: item.value.vat_rate_id, is_active: item.value.is_active, is_stocked: item.value.is_stocked,
}) : null)
const allCards = computed(() => {
  const mapped = new Map(cards.value.map(card => [card.id, card]))
  if (currentCard.value) mapped.set(currentCard.value.id, currentCard.value)
  return [...mapped.values()]
})

function cloneDefinition(definition: ProductSetDefinition): ProductSetDefinition {
  return JSON.parse(JSON.stringify(definition)) as ProductSetDefinition
}

function preparePrices() {
  for (const currency of activeCurrencies.value) {
    form.value.prices[currency.code] ??= { mode: 'sum' }
  }
}

async function load() {
  const token = ++loadToken
  loading.value = true
  error.value = ''
  try {
    const [loadedItem, setResponse, loadedCurrencies] = await Promise.all([
      stockApi.getItem(itemId.value),
      eshopApi.getProductSet(itemId.value),
      eshopApi.listCurrencies(),
    ])
    if (token !== loadToken) return
    item.value = loadedItem
    currencies.value = loadedCurrencies
    cards.value = setResponse.cards ?? []
    definitions.value = setResponse.definitions ?? {}
    rowVersion.value = setResponse.set?.row_version ?? 0
    form.value = cloneDefinition(setResponse.set?.definition ?? emptyDefinition())
    preparePrices()
  } catch (e: any) {
    if (token === loadToken) error.value = e?.response?.data?.error?.message || t('eshop.sets.load_failed')
  } finally {
    if (token === loadToken) loading.value = false
  }
}

async function searchItems(query: string) {
  searchLoading.value = true
  try {
    searchRows.value = await stockApi.searchItems(query, 30)
  } catch {
    searchRows.value = []
  } finally {
    searchLoading.value = false
  }
}

function rememberCard(id: number) {
  const source = searchRows.value.find(row => row.id === id)
  if (!source || cards.value.some(card => card.id === id)) return
  cards.value.push({
    id: source.id, sku: source.sku, name: source.name, unit: source.unit,
    vat_rate_id: source.vat_rate_id ?? null, is_active: true, is_stocked: false,
  })
}

function addFixedComponent() {
  if (fixedPick.value === null) return
  rememberCard(fixedPick.value)
  form.value.components.push({ item_id: fixedPick.value, quantity: '1' })
  fixedPick.value = null
}

function addGroup() {
  const base = `group_${form.value.groups.length + 1}`
  let code = base
  let suffix = 2
  while (form.value.groups.some(group => group.code === code)) code = `${base}_${suffix++}`
  form.value.groups.push({ code, name: '', min: 0, max: 1, options: [] })
}

function addOption(groupIndex: number) {
  const selected = optionPick.value[groupIndex]
  if (selected === null || selected === undefined) return
  const group = form.value.groups[groupIndex]
  rememberCard(selected)
  const base = `option_${group.options.length + 1}`
  let code = base
  let suffix = 2
  while (group.options.some(option => option.code === code)) code = `${base}_${suffix++}`
  group.options.push({ code, name: cardMap.value.get(selected)?.name ?? '', item_id: selected, quantity: '1', surcharges: {} })
  optionPick.value[groupIndex] = null
}

function removeFixed(index: number) { form.value.components.splice(index, 1) }
function removeGroup(index: number) { form.value.groups.splice(index, 1) }
function removeOption(group: ProductSetGroup, index: number) { group.options.splice(index, 1) }
function cardLabel(id: number) {
  const card = cardMap.value.get(id)
  return card ? `${card.sku} - ${card.name}` : `#${id}`
}

function normalizeDefinition(): ProductSetDefinition {
  const prices: ProductSetDefinition['prices'] = {}
  for (const currency of activeCurrencies.value) {
    const price = form.value.prices[currency.code] ?? { mode: 'sum' as const }
    prices[currency.code] = price.mode === 'discount'
      ? { mode: 'discount', discount_pct: String(price.discount_pct ?? '') }
      : price.mode === 'fixed'
        ? { mode: 'fixed', fixed_price: String(price.fixed_price ?? '') }
        : { mode: 'sum' }
  }
  return {
    components: form.value.components.map(component => ({ item_id: component.item_id, quantity: String(component.quantity) })),
    groups: form.value.groups.map(group => ({
      code: group.code.trim(), name: group.name.trim(), min: Number(group.min), max: Number(group.max),
      options: group.options.map(option => ({
        code: option.code.trim(), name: option.name.trim(), item_id: option.item_id, quantity: String(option.quantity),
        surcharges: Object.fromEntries(activeCurrencies.value
          .map(currency => [currency.code, option.surcharges[currency.code]])
          .filter(([, amount]) => amount !== undefined && amount !== null && String(amount).trim() !== '')),
      })),
    })),
    prices,
  }
}

async function save() {
  if (saving.value || !canWrite.value || !isVirtualCard.value) return
  error.value = ''
  saving.value = true
  try {
    const saved = await eshopApi.saveProductSet(itemId.value, rowVersion.value, normalizeDefinition())
    rowVersion.value = saved.row_version
    form.value = cloneDefinition(saved.definition)
    preparePrices()
    definitions.value[String(itemId.value)] = cloneDefinition(saved.definition)
    toast.success(t('common.saved'))
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    error.value = code === 'version_conflict'
      ? t('eshop.sets.version_conflict')
      : e?.response?.data?.error?.message || t('eshop.sets.save_failed')
  } finally {
    saving.value = false
  }
}

watch(itemId, () => { void load() })
onMounted(() => { void load() })
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <button type="button" class="mb-1 text-sm text-primary-700 hover:underline" @click="router.push(`/stock/items/${itemId}`)">← {{ t('eshop.sets.back_to_item') }}</button>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('eshop.sets.title') }}<span v-if="item">: {{ item.name }}</span></h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('eshop.sets.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 1 0 2 5.3M20 4v7h-7" /></svg>
          {{ t('common.refresh') }}
        </button>
        <button type="button" :class="btnFilled('success')" :disabled="saving || !canWrite || !isVirtualCard" @click="save">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12l4 4L19 6" /></svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="error && !item" boxed variant="failed" :message="error" @action="load" />
    <template v-else-if="item">
      <p v-if="error" role="alert" class="mb-4 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ error }}</p>
      <p v-if="!canWrite" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('eshop.sets.readonly_hint') }}</p>
      <p v-if="!isVirtualCard" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('eshop.sets.stocked_hint') }}</p>

      <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-5">
          <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3">
              <div>
                <h2 class="font-semibold text-neutral-900">{{ t('eshop.sets.fixed_components') }}</h2>
                <p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.sets.fixed_components_hint') }}</p>
              </div>
            </header>
            <div class="space-y-3 p-4">
              <div v-for="(component, index) in form.components" :key="`${component.item_id}-${index}`" class="grid gap-2 rounded-lg border border-neutral-200 p-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto] sm:items-end">
                <div class="min-w-0 text-sm"><p class="truncate font-medium text-neutral-900">{{ cardLabel(component.item_id) }}</p><p class="text-xs text-neutral-500">{{ t('eshop.sets.fixed_component') }}</p></div>
                <label class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.quantity') }}<input v-model="component.quantity" :class="FIELD" inputmode="decimal"></label>
                <button type="button" :class="btnOutline('danger')" :disabled="!canWrite" @click="removeFixed(index)"><span aria-hidden="true">×</span><span class="sr-only">{{ t('common.delete') }}</span></button>
              </div>
              <div class="flex flex-wrap items-end gap-2 border-t border-neutral-100 pt-3">
                <div class="min-w-[16rem] flex-1"><SearchableSelect v-model="fixedPick" remote :options="searchOptions" :loading="searchLoading" :placeholder="t('eshop.sets.search_item')" :no-results-label="t('common.no_results')" teleport @search="searchItems" /></div>
                <button type="button" :class="btnOutline('primary')" :disabled="fixedPick === null || !canWrite" @click="addFixedComponent">+ {{ t('eshop.sets.add_component') }}</button>
              </div>
            </div>
          </section>

          <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3"><div><h2 class="font-semibold text-neutral-900">{{ t('eshop.sets.groups') }}</h2><p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.sets.groups_hint') }}</p></div><button type="button" :class="btnOutline('primary')" :disabled="!canWrite" @click="addGroup">+ {{ t('eshop.sets.add_group') }}</button></header>
            <div class="space-y-4 p-4">
              <EmptyState v-if="form.groups.length === 0" dense icon="tag" :title="t('eshop.sets.empty_groups')" :message="t('eshop.sets.empty_groups_hint')" />
              <article v-for="(group, groupIndex) in form.groups" :key="groupIndex" class="rounded-lg border border-neutral-200 p-3">
                <div class="grid gap-3 sm:grid-cols-4">
                  <label class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.code') }}<input v-model="group.code" :class="FIELD" maxlength="50" pattern="[A-Za-z0-9_-]+"></label>
                  <label class="text-xs font-medium text-neutral-500 sm:col-span-2">{{ t('eshop.sets.name') }}<input v-model="group.name" :class="FIELD" maxlength="150"></label>
                  <div class="flex items-end justify-end"><button type="button" :class="btnOutline('danger')" :disabled="!canWrite" @click="removeGroup(groupIndex)">{{ t('common.delete') }}</button></div>
                  <label class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.min') }}<input v-model.number="group.min" :class="FIELD" type="number" min="0" :max="group.options.length"></label>
                  <label class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.max') }}<input v-model.number="group.max" :class="FIELD" type="number" min="1" :max="group.options.length || 1"></label>
                </div>
                <div class="mt-3 space-y-2 border-t border-neutral-100 pt-3">
                  <div v-for="(option, optionIndex) in group.options" :key="optionIndex" class="rounded-md bg-neutral-50 p-3 dark:bg-neutral-900/20">
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                      <label class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.code') }}<input v-model="option.code" :class="FIELD" maxlength="50"></label>
                      <label class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.name') }}<input v-model="option.name" :class="FIELD" maxlength="150"></label>
                      <div class="text-xs font-medium text-neutral-500"><span>{{ t('eshop.sets.item') }}</span><p class="mt-1 h-9 truncate rounded-md border border-neutral-200 bg-surface px-3 py-2 text-sm text-neutral-700">{{ cardLabel(option.item_id) }}</p></div>
                      <label class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.quantity') }}<input v-model="option.quantity" :class="FIELD" inputmode="decimal"></label>
                      <div class="flex items-end justify-end"><button type="button" :class="btnOutline('danger')" :disabled="!canWrite" @click="removeOption(group, optionIndex)">{{ t('common.delete') }}</button></div>
                    </div>
                    <div class="mt-2 grid gap-2 sm:grid-cols-3"><label v-for="currency in activeCurrencies" :key="currency.code" class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.surcharge', { currency: currency.code }) }}<input v-model="option.surcharges[currency.code]" :class="FIELD" inputmode="decimal" :placeholder="'0.00'"></label></div>
                  </div>
                  <div class="flex flex-wrap items-end gap-2"><div class="min-w-[16rem] flex-1"><SearchableSelect v-model="optionPick[groupIndex]" remote :options="searchOptions" :loading="searchLoading" :placeholder="t('eshop.sets.search_item')" :no-results-label="t('common.no_results')" teleport @search="searchItems" /></div><button type="button" :class="btnOutline('primary')" :disabled="optionPick[groupIndex] == null || !canWrite" @click="addOption(groupIndex)">+ {{ t('eshop.sets.add_option') }}</button></div>
                </div>
              </article>
            </div>
          </section>

          <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"><header class="border-b border-neutral-200 px-4 py-3"><h2 class="font-semibold text-neutral-900">{{ t('eshop.sets.prices') }}</h2><p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.sets.prices_hint') }}</p></header><div class="divide-y divide-neutral-100"><div v-for="currency in activeCurrencies" :key="currency.code" class="grid gap-3 px-4 py-3 sm:grid-cols-[6rem_10rem_minmax(0,1fr)] sm:items-end"><p class="font-mono text-sm font-semibold text-neutral-900">{{ currency.code }}</p><label class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.price_mode') }}<select v-model="form.prices[currency.code].mode" :class="FIELD"><option value="sum">{{ t('eshop.sets.mode_sum') }}</option><option value="discount">{{ t('eshop.sets.mode_discount') }}</option><option value="fixed">{{ t('eshop.sets.mode_fixed') }}</option></select></label><label v-if="form.prices[currency.code].mode === 'discount'" class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.discount_pct') }}<input v-model="form.prices[currency.code].discount_pct" :class="FIELD" inputmode="decimal"></label><label v-else-if="form.prices[currency.code].mode === 'fixed'" class="text-xs font-medium text-neutral-500">{{ t('eshop.sets.fixed_price') }}<input v-model="form.prices[currency.code].fixed_price" :class="FIELD" inputmode="decimal"></label><p v-else class="text-xs text-neutral-500">{{ t('eshop.sets.mode_sum_hint') }}</p></div></div></section>
        </div>

        <ProductSetQuotePanel :item-id="itemId" :definition="form" :definitions="definitions" :cards="allCards" :currencies="activeCurrencies.map(currency => currency.code)" />
      </div>
    </template>
  </div>
</template>
