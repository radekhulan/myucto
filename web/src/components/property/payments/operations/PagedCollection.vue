<script setup lang="ts" generic="T extends { id: number; [key: string]: any }">
import { onBeforeUnmount, ref, watch, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type OpsCollection } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { failureMessage } from './format'
const props = withDefaults(defineProps<{ supplier: number; collection: OpsCollection; unitId?: number; limit?: number; filter?: (item: T) => boolean; reloadKey?: number }>(), { unitId: 0, limit: 25, reloadKey: 0 })
const emit = defineEmits<{ loaded: [items: T[]] }>()
defineSlots<{ row(props: { item: T }): unknown }>()
const { t } = useI18n()
const items = ref<T[]>([]) as Ref<T[]>
const next = ref<number | null>(null)
const loading = ref(false)
const error = ref('')
const loadedOnce = ref(false)
let epoch = 0
onBeforeUnmount(() => epoch++)
async function load(more: boolean) {
  const ticket = more ? epoch : ++epoch
  const supplier = props.supplier
  if (!more) { items.value = []; next.value = null; loadedOnce.value = false }
  if (more && next.value === null) return
  loading.value = true; error.value = ''
  try {
    const page = await propertyPaymentOperationsApi.list<T>(supplier, props.collection, { ...(props.unitId ? { unit_id: props.unitId } : {}), ...(more && next.value !== null ? { after_id: next.value } : {}), limit: props.limit })
    if (ticket !== epoch || supplier !== props.supplier) return
    items.value = more ? [...items.value, ...page.items] : page.items
    next.value = page.next_cursor
    loadedOnce.value = true
    emit('loaded', items.value)
  } catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) } finally { if (ticket === epoch) loading.value = false }
}
watch(() => [props.supplier, props.collection, props.unitId, props.reloadKey], () => { void load(false) }, { immediate: true })
defineExpose({ reload: () => load(false), items })
</script>

<template>
  <div class="space-y-2" :data-collection="collection">
    <p class="text-xs text-neutral-500">{{ t('propertyPayments.coverageIncomplete') }}</p>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <p v-if="loading && !items.length">{{ t('propertyPayments.loading') }}</p>
    <p v-else-if="loadedOnce && !items.length" class="text-sm text-neutral-500">{{ t('propertyPayments.noRows') }}</p>
    <template v-for="item in (filter ? items.filter(filter) : items)" :key="item.id"><slot name="row" :item="item" /></template>
    <PropertyButton v-if="next !== null" icon="chevron" data-action="load-more" :disabled="loading" @click="load(true)">{{ t('propertyPayments.loadMore') }}</PropertyButton>
  </div>
</template>
