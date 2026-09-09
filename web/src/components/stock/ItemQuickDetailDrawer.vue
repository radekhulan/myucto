<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import { stockApi, type StockItem, type StockItemNeighborsOptions } from '@/api/stock'
import type { Manufacturer } from '@/api/eshop'
import Drawer from '@/components/ui/Drawer.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{
  itemId: number
  initialItem?: StockItem | null
  manufacturers: Manufacturer[]
  neighbors: StockItemNeighborsOptions
  canEdit?: boolean
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'open', id: number): void
  (e: 'edit', id: number): void
  (e: 'navigate', id: number): void
}>()

const { t } = useI18n()
const item = ref<StockItem | null>(props.initialItem ?? null)
const loading = ref(false)
const neighborLoading = ref(false)
const previousId = ref<number | null>(null)
const nextId = ref<number | null>(null)
const position = ref<number | null>(null)
const total = ref(0)
let requestVersion = 0
let itemController: AbortController | undefined
let neighborsController: AbortController | undefined

const title = computed(() => item.value?.name ?? props.initialItem?.name ?? t('stock.items.quick_detail.title'))
const subtitle = computed(() => item.value?.sku ?? props.initialItem?.sku ?? '')
const manufacturerName = computed(() => {
  const manufacturerId = item.value?.manufacturer_id
  return manufacturerId == null ? null : props.manufacturers.find(manufacturer => manufacturer.id === manufacturerId)?.name ?? null
})
const itemPrice = computed(() => item.value?.effective_price ?? null)
const positionLabel = computed(() => position.value == null || total.value === 0
  ? null
  : t('stock.items.quick_detail.position', { position: position.value, total: total.value }))

async function load(id: number) {
  const version = ++requestVersion
  itemController?.abort()
  neighborsController?.abort()
  itemController = new AbortController()
  neighborsController = new AbortController()
  loading.value = true
  neighborLoading.value = true
  item.value = id === props.initialItem?.id ? props.initialItem : null

  void stockApi.getItem(id, { signal: itemController.signal }).then(nextItem => {
    if (version === requestVersion) item.value = nextItem
  }).catch(error => {
    if (error?.code !== 'ERR_CANCELED' && version === requestVersion) item.value = null
  }).finally(() => {
    if (version === requestVersion) loading.value = false
  })

  void stockApi.itemNeighbors(id, props.neighbors, { signal: neighborsController.signal }).then(result => {
    if (version !== requestVersion) return
    previousId.value = result.previous_id
    nextId.value = result.next_id
    position.value = result.position
    total.value = result.total
  }).catch(error => {
    if (error?.code !== 'ERR_CANCELED' && version === requestVersion) {
      previousId.value = null
      nextId.value = null
      position.value = null
      total.value = 0
    }
  }).finally(() => {
    if (version === requestVersion) neighborLoading.value = false
  })
}

function navigateTo(id: number | null) {
  if (id == null) return
  emit('navigate', id)
}

watch(() => props.itemId, id => void load(id), { immediate: true })
onBeforeUnmount(() => {
  requestVersion++
  itemController?.abort()
  neighborsController?.abort()
})
</script>

<template>
  <Drawer :title="title" :subtitle="subtitle" width-class="max-w-lg" @close="emit('close')">
    <div v-if="loading && !item" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else-if="item" class="space-y-5">
      <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-neutral-500">{{ t('stock.items.col_sku') }}</dt>
          <dd class="mt-0.5 font-mono text-neutral-900">{{ item.sku }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('stock.items.field_type') }}</dt>
          <dd class="mt-0.5 text-neutral-900">{{ t(`stock.item_type.${item.item_type}`) }}</dd>
        </div>
        <div v-if="manufacturerName">
          <dt class="text-neutral-500">{{ t('stock.items.filter_manufacturer') }}</dt>
          <dd class="mt-0.5 text-neutral-900">{{ manufacturerName }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('stock.items.quick_detail.sale_price_without_vat') }}</dt>
          <dd class="mt-0.5 font-mono text-neutral-900">{{ itemPrice == null ? '—' : formatMoney(Number(itemPrice)) }}</dd>
        </div>
        <div>
          <dt class="text-neutral-500">{{ t('stock.items.field_active') }}</dt>
          <dd class="mt-0.5 text-neutral-900">{{ item.is_active ? t('common.yes') : t('common.no') }}</dd>
        </div>
        <div v-if="item.ean">
          <dt class="text-neutral-500">{{ t('stock.items.field_ean') }}</dt>
          <dd class="mt-0.5 font-mono text-neutral-900">{{ item.ean }}</dd>
        </div>
      </dl>
    </div>
    <p v-else class="text-sm text-danger-600">{{ t('stock.items.quick_detail.load_failed') }}</p>

    <template #footer>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" :disabled="previousId == null || neighborLoading" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="navigateTo(previousId)">
            <svg class="h-4 w-4 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
            {{ t('common.previous') }}
          </button>
          <button type="button" :disabled="nextId == null || neighborLoading" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="navigateTo(nextId)">
            {{ t('common.next') }}
            <svg class="h-4 w-4 -rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
          </button>
          <span v-if="positionLabel" class="text-xs text-neutral-500 whitespace-nowrap">{{ positionLabel }}</span>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('open', itemId)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
            {{ t('stock.items.quick_detail.open_detail') }}
          </button>
          <button v-if="canEdit" type="button" :class="btnFilled('primary')" class="whitespace-nowrap" @click="emit('edit', itemId)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
            {{ t('common.edit') }}
          </button>
        </div>
      </div>
    </template>
  </Drawer>
</template>
