<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItemSearchResult } from '@/api/stock'
import { productMastersApi, type ProductRelation, type ProductRelationType } from '@/api/productMasters'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'

const props = withDefaults(defineProps<{ itemId: number; canWrite?: boolean; compact?: boolean }>(), { canWrite: false, compact: false })
const { t } = useI18n()
const supplier = useSupplierStore()
const toast = useToast()

const emit = defineEmits<{ (event: 'updated', rowVersion: number, previousVersion: number): void }>()
const rowVersion = ref(0)
const rows = ref<ProductRelation[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const query = ref('')
const candidates = ref<StockItemSearchResult[]>([])
const relationType = ref<ProductRelationType>('related')
const active = ref(true)
let generation = 0
let searchGeneration = 0
let searchTimer: ReturnType<typeof setTimeout> | null = null

const grouped = computed(() => (['accessory', 'replacement', 'related'] as ProductRelationType[]).map(type => ({ type, rows: rows.value.filter(row => row.type === type) })).filter(group => group.rows.length))

async function load() {
  if (!active.value || !props.itemId) return
  const current = ++generation
  loading.value = true
  try {
    const result = await productMastersApi.getRelations(props.itemId)
    if (!active.value || current !== generation) return
    rowVersion.value = result.row_version
    rows.value = result.items
  } catch (err: any) {
    if (current === generation) error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    if (current === generation) loading.value = false
  }
}

async function search() {
  const current = ++searchGeneration
  const value = query.value.trim()
  if (value.length < 2) { candidates.value = []; return }
  try {
    const found = await stockApi.searchItems(value, 30)
    if (current !== searchGeneration) return
    const existing = new Set(rows.value.map(row => row.target_stock_item_id))
    candidates.value = found.filter(row => row.id !== props.itemId && !existing.has(row.id))
  } catch (err: any) {
    if (current === searchGeneration) toast.error(apiErrorMessage(err, t('common.error')))
  }
}

function onQuery() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(search, 250)
}

function add(candidate: StockItemSearchResult) {
  rows.value.push({ type: relationType.value, target_stock_item_id: candidate.id, target_sku: candidate.sku, target_name: candidate.name, display_order: rows.value.length * 10 + 10 })
  query.value = ''
  candidates.value = []
}

function remove(targetId: number) { rows.value = rows.value.filter(row => row.target_stock_item_id !== targetId) }

async function save() {
  if (!props.canWrite || saving.value) return
  saving.value = true
  error.value = ''
  const current = generation
  const previousVersion = rowVersion.value
  try {
    const result = await productMastersApi.updateRelations(props.itemId, {
      row_version: rowVersion.value,
      items: rows.value.map((row, index) => ({ type: row.type, target_stock_item_id: row.target_stock_item_id, display_order: index * 10 + 10 })),
    })
    if (!active.value || current !== generation) return
    rowVersion.value = result.row_version
    rows.value = result.items
    emit('updated', result.row_version, previousVersion)
    toast.success(t('common.saved'))
  } catch (err: any) {
    if (active.value && current === generation) error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    saving.value = false
  }
}

watch(() => props.itemId, () => { generation++; rows.value = []; void load() })
watch(() => supplier.currentSupplierId, () => { generation++; searchGeneration++; rows.value = []; void load() })
onMounted(load)
onActivated(() => { active.value = true; void load() })
onDeactivated(() => { active.value = false; generation++; searchGeneration++ })
onBeforeUnmount(() => { active.value = false; generation++; searchGeneration++; if (searchTimer) clearTimeout(searchTimer) })
</script>

<template>
  <section :class="compact ? '' : 'rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm'">
    <div class="mb-4"><h2 class="text-lg font-semibold">{{ t('eshop.relations.title') }}</h2><p class="text-sm text-neutral-500">{{ t('eshop.relations.hint') }}</p></div>
    <div v-if="canWrite" class="mb-4 rounded-md border border-neutral-200 bg-neutral-50 p-3">
      <div class="grid grid-cols-1 gap-2 sm:grid-cols-[12rem_1fr]"><label><span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.relations.type') }}</span><select v-model="relationType" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm"><option value="accessory">{{ t('eshop.relations.types.accessory') }}</option><option value="replacement">{{ t('eshop.relations.types.replacement') }}</option><option value="related">{{ t('eshop.relations.types.related') }}</option></select></label><label class="relative"><span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.relations.add') }}</span><input v-model="query" type="search" @input="onQuery" :placeholder="t('eshop.relations.search')" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" /><div v-if="candidates.length" class="absolute z-20 mt-1 max-h-52 w-full overflow-y-auto rounded-md border border-neutral-200 bg-surface shadow-lg"><button v-for="candidate in candidates" :key="candidate.id" type="button" @click="add(candidate)" class="flex w-full cursor-pointer gap-3 border-b border-neutral-100 px-3 py-2 text-left text-sm hover:bg-primary-50"><span class="font-mono text-xs text-neutral-500">{{ candidate.sku }}</span>{{ candidate.name }}</button></div></label></div>
    </div>
    <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="rows.length === 0" dense icon="link" :title="t('eshop.relations.empty')" />
    <div v-else class="space-y-4"><div v-for="group in grouped" :key="group.type"><h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t(`eshop.relations.types.${group.type}`) }}</h3><div class="divide-y divide-neutral-100 rounded-md border border-neutral-200"><div v-for="row in group.rows" :key="row.target_stock_item_id" class="flex flex-wrap items-center gap-3 px-3 py-2 text-sm"><span class="font-mono text-xs text-neutral-500">{{ row.target_sku }}</span><RouterLink :to="`/stock/items/${row.target_stock_item_id}`" class="min-w-0 flex-1 font-medium text-primary-700 hover:underline">{{ row.target_name }}</RouterLink><button v-if="canWrite" type="button" @click="remove(row.target_stock_item_id)" :aria-label="t('common.remove')" :title="t('common.remove')" :class="btnOutlineSm('danger')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>{{ t('common.remove') }}</button></div></div></div></div>
    <p v-if="error" class="mt-3 text-sm text-danger-600">{{ error }}</p>
    <div v-if="canWrite" class="mt-4 flex flex-wrap justify-end"><button type="button" @click="save" :disabled="saving" :class="btnFilled('success')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>{{ saving ? t('common.saving') : t('common.save') }}</button></div>
  </section>
</template>
