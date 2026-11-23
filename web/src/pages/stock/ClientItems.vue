<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItem, type StockItemPayload } from '@/api/stock'
import { codebooksApi, type Unit, type VatRate } from '@/api/codebooks'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { unitPriceText } from '@/utils/unitPrice'

const props = defineProps<{ mode: 'list' | 'detail' | 'editor' }>()
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const canWrite = computed(() => auth.canWrite('stock.items.write'))
const itemId = computed(() => Number(route.params.id || 0))
const items = ref<StockItem[]>([])
const item = ref<StockItem | null>(null)
const units = ref<Unit[]>([])
const vatRates = ref<VatRate[]>([])
const form = ref<StockItemPayload>(blankForm())
const query = ref('')
const page = ref(1)
const pages = ref(1)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
let generation = 0
function blankForm(): StockItemPayload {
  return { sku: '', name: '', item_type: 'goods', unit: 'ks', tracking_mode: 'none', ean: null, vat_rate_id: null, sale_price_without_vat: null, min_qty: null, is_active: true, note: null }
}
function payload(value: StockItem): StockItemPayload {
  return { sku: value.sku, name: value.name, item_type: value.item_type, unit: value.unit, tracking_mode: value.tracking_mode ?? 'none', ean: value.ean, vat_rate_id: value.vat_rate_id, sale_price_without_vat: value.sale_price_without_vat, min_qty: value.min_qty, is_active: value.is_active, note: value.note }
}
async function load() {
  const current = ++generation
  loading.value = true
  error.value = ''
  item.value = null
  try {
    if (props.mode === 'list') {
      const result = await stockApi.listItems({ q: query.value || undefined, page: page.value, per_page: 50 })
      if (current !== generation) return
      items.value = result.data
      pages.value = result.meta.pages ?? 1
    } else {
      const [loadedUnits, loadedRates] = await Promise.all([codebooksApi.units(), codebooksApi.vatRates('CZ')])
      if (current !== generation) return
      units.value = loadedUnits
      vatRates.value = loadedRates
      if (itemId.value > 0) {
        const detail = await stockApi.getItem(itemId.value)
        if (current !== generation) return
        item.value = detail
        form.value = payload(detail)
      } else form.value = blankForm()
    }
  } catch (cause) {
    if (current === generation) error.value = apiErrorMessage(cause)
  } finally {
    if (current === generation) loading.value = false
  }
}
async function save() {
  if (!canWrite.value || props.mode !== 'editor' || saving.value || loading.value) return
  saving.value = true
  try {
    const result = itemId.value
      ? await stockApi.updateItem(itemId.value, { ...form.value, row_version: item.value?.row_version })
      : await stockApi.createItem(form.value)
    toast.success(t('common.saved'))
    await router.push({ name: 'stock-item-detail', params: { id: result.id } })
  } catch (cause) { error.value = apiErrorMessage(cause) }
  finally { saving.value = false }
}
async function remove() {
  if (!canWrite.value || !item.value || !window.confirm(t('stock.items.delete_confirm', { name: item.value.name }))) return
  try {
    await stockApi.deleteItem(item.value.id)
    await router.push({ name: 'stock-items' })
  } catch (cause) { error.value = apiErrorMessage(cause) }
}
const actions = computed<ActionItem[]>(() => [
  { key: 'save', label: t('common.save'), icon: 'check', variant: 'primary', tier: 'primary', show: canWrite.value && props.mode === 'editor', disabled: saving.value || loading.value, run: save },
  { key: 'edit', label: t('common.edit'), icon: 'edit', variant: 'primary', tier: 'primary', show: canWrite.value && props.mode === 'detail' && !!item.value, to: { name: 'stock-item-edit', params: { id: itemId.value } } },
  { key: 'back', label: t('stock.item_detail.back_to_list'), icon: 'uturn', tier: 'secondary', to: { name: 'stock-items' } },
  { key: 'delete', label: t('common.remove'), icon: 'trash', variant: 'danger', tier: 'overflow', show: canWrite.value && props.mode === 'detail' && !!item.value, run: remove },
])
onMounted(load)
watch(() => [route.name, route.params.id, supplier.currentSupplierId], () => { page.value = 1; items.value = []; void load() })
</script>

<template>
  <div class="w-full">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold">{{ mode === 'list' ? t('stock.items.title') : mode === 'editor' ? itemId ? t('stock.items.edit_title') : t('stock.items.new_title') : item?.name }}</h1>
      <button v-if="mode === 'list' && canWrite" type="button" :class="btnFilled('primary')" @click="router.push({ name: 'stock-item-new' })"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('stock.items.new_title') }}</button>
      <ActionBar v-if="mode !== 'list'" :actions="actions" />
    </header>
    <div v-if="error" class="mb-4 rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700">{{ error }}</div>
    <form v-if="mode === 'list'" class="mb-4 flex flex-wrap gap-2" @submit.prevent="page = 1; load()">
      <input v-model="query" :placeholder="t('stock.items.filter_q_placeholder')" class="h-10 w-full max-w-lg rounded-md border border-neutral-300 bg-surface px-3" />
      <button :class="btnOutline('neutral')" type="submit"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>{{ t('common.search') }}</button>
    </form>
    <div v-if="loading" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>
    <template v-else-if="mode === 'list'">
      <EmptyState v-if="!items.length" boxed :title="t('stock.items.empty')" />
      <div v-else class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-surface shadow-sm">
        <article v-for="row in items" :key="row.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
          <div><div class="text-xs font-mono text-neutral-500">{{ row.sku }}</div><h2 class="font-medium">{{ row.name }}</h2><div class="mt-1 text-sm text-neutral-500">{{ t('stock.items.col_qty') }}: {{ row.qty }} {{ row.unit }} · {{ t('stock.items.col_sale_price') }}: {{ unitPriceText(row.effective_price ?? row.sale_price_without_vat) }}</div></div>
          <button type="button" :class="btnOutline('primary')" @click="router.push({ name: 'stock-item-detail', params: { id: row.id } })"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>{{ t('common.detail') }}</button>
        </article>
      </div>
      <div v-if="pages > 1" class="mt-4 flex flex-wrap items-center justify-end gap-3"><button :class="btnOutline('neutral')" :disabled="page <= 1" @click="page--; load()"><svg class="h-4 w-4 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('common.previous') }}</button><span>{{ page }} / {{ pages }}</span><button :class="btnOutline('neutral')" :disabled="page >= pages" @click="page++; load()">{{ t('common.next') }}<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg></button></div>
    </template>
    <form v-else-if="mode === 'editor' || item" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" @submit.prevent="save">
      <fieldset :disabled="mode !== 'editor' || !canWrite" class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm font-medium">{{ t('stock.items.field_name') }}<input v-model="form.name" required maxlength="255" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm font-medium">{{ t('stock.items.field_sku') }}<input v-model="form.sku" maxlength="50" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono" /></label>
        <label class="text-sm font-medium">{{ t('stock.items.field_unit') }}<input v-model="form.unit" required list="client-stock-units" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /><datalist id="client-stock-units"><option v-for="unit in units" :key="unit.id" :value="unit.code" /></datalist></label>
        <label class="text-sm font-medium">{{ t('stock.items.field_type') }}<select v-model="form.item_type" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3"><option v-for="type in ['goods', 'material', 'product', 'service'] as const" :key="type" :value="type">{{ t(`stock.item_type.${type}`) }}</option></select></label>
        <label class="text-sm font-medium">{{ t('stock.tracking.mode') }}<select v-model="form.tracking_mode" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3"><option v-for="mode in ['none', 'lot', 'serial'] as const" :key="mode" :value="mode">{{ t(`stock.tracking.mode_${mode}`) }}</option></select></label>
        <label class="text-sm font-medium">{{ t('stock.items.field_ean') }}<input v-model="form.ean" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm font-medium">{{ t('stock.items.field_vat_rate') }}<select v-model="form.vat_rate_id" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3"><option :value="null">—</option><option v-for="rate in vatRates" :key="rate.id" :value="rate.id">{{ rate.rate_percent }} % · {{ locale === 'en' ? rate.label_en : rate.label_cs }}</option></select></label>
        <label class="text-sm font-medium">{{ t('stock.items.field_sale_price') }}<input v-model="form.sale_price_without_vat" type="number" min="0" step="any" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm font-medium">{{ t('stock.items.field_min_qty') }}<input v-model="form.min_qty" type="number" min="0" step="0.001" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm font-medium sm:col-span-2">{{ t('stock.items.field_note') }}<textarea v-model="form.note" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface p-3" /></label>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" />{{ t('stock.items.field_active') }}</label>
      </fieldset>
    </form>
  </div>
</template>
