<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PropertyObject } from '@/api/propertyRegistry'
import { propertyPaymentOperationsApi, type Correction, type CorrectionDetail, type Operation, type OperationDetail } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import OpsTabBar from './OpsTabBar.vue'
import PagedCollection from './PagedCollection.vue'
import CorrectionForm from './CorrectionForm.vue'
import ReverseForm from './ReverseForm.vue'
import { useBusyScope } from './busy'
import { failureMessage, moneyText, propertyUnitLabel } from './format'
import { inputClass } from './ui'
const props = defineProps<{ supplier: number; units: PropertyObject[]; canManage: boolean; canPost: boolean; canReverse: boolean }>()
const { t } = useI18n()
const busy = useBusyScope().busy
const tab = ref('operations')
const unitId = ref(0)
const reloadKey = ref(0)
const notice = ref(false)
const detail = ref<{ kind: 'operations' | 'corrections'; id: number; value: OperationDetail | CorrectionDetail } | null>(null)
const reversing = ref<{ kind: 'operations' | 'corrections'; id: number } | null>(null)
const error = ref('')
let epoch = 0
onBeforeUnmount(() => epoch++)
watch(() => props.supplier, () => { epoch++; unitId.value = 0; tab.value = 'operations'; detail.value = null; reversing.value = null })
watch([unitId, tab], () => { epoch++; detail.value = null; reversing.value = null })
function changed() { reloadKey.value++; notice.value = true; detail.value = null; reversing.value = null }
async function open(kind: 'operations' | 'corrections', id: number) {
  const ticket = ++epoch; const supplier = props.supplier
  detail.value = null; error.value = ''
  try {
    const value = kind === 'operations' ? await propertyPaymentOperationsApi.detail<OperationDetail>(supplier, kind, id) : await propertyPaymentOperationsApi.detail<CorrectionDetail>(supplier, kind, id)
    if (ticket === epoch && supplier === props.supplier) detail.value = { kind, id, value }
  } catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) }
}
const tabs = () => ['operations', 'corrections', ...(props.canManage ? ['new'] : [])].map(key => ({ key, label: t(`propertyPayments.tabs.${key}`) }))
const unknown = () => t('propertyPayments.unknown')
</script>

<template>
  <section class="space-y-3" data-section="corrections-history">
    <div class="flex flex-wrap items-end gap-3">
      <OpsTabBar v-model="tab" :tabs="tabs()" :disabled="busy" />
      <label class="min-w-48 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.alloc.unitFilter') }}<select v-model="unitId" :disabled="busy" :class="inputClass" data-field="ops-unit"><option :value="0">{{ t('propertyPayments.alloc.allUnits') }}</option><option v-for="u in units" :key="u.id" :value="u.id">{{ propertyUnitLabel(u, number => t('propertyPayments.unitNumber', { number })) }}</option></select></label>
    </div>
    <p v-if="notice" role="status" class="text-success-600">{{ t('propertyPayments.actionDone') }}</p>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <PagedCollection v-if="tab === 'operations'" :supplier="supplier" collection="operations" :unit-id="unitId" :reload-key="reloadKey">
      <template #row="{ item }">
        <article class="space-y-2 border-t border-neutral-200 py-2 text-sm" data-row="operation">
          <div class="flex flex-wrap items-center gap-3">
            <span class="min-w-0 flex-1">{{ (item as Operation).accounting_date }} · {{ t(`propertyPayments.operationKind.${(item as Operation).kind}`, (item as Operation).kind) }} · {{ (item as Operation).unit_labels.join(', ') }}</span>
            <span class="rounded border border-neutral-300 px-2">{{ (item as Operation).status }}</span>
            <span>{{ moneyText((item as Operation).amount_cents, unknown()) }}</span>
            <PropertyButton icon="eye" data-action="operation-open" @click="open('operations', item.id)">{{ t('propertyPayments.detail') }}</PropertyButton>
            <PropertyButton v-if="canReverse && (item as Operation).kind !== 'correction'" icon="uturn" variant="warning" data-action="operation-reverse" @click="reversing = { kind: 'operations', id: item.id }">{{ t('propertyPayments.reverse.open') }}</PropertyButton>
          </div>
          <p class="text-xs text-neutral-500">{{ (item as Operation).reason }}<template v-if="(item as Operation).source_label"> · {{ (item as Operation).source_label }}</template></p>
          <ul v-if="detail?.kind === 'operations' && detail.id === item.id" class="list-disc pl-5" data-section="operation-detail"><li v-for="a in (detail.value as OperationDetail).allocations" :key="a.id">{{ a.label }}: {{ moneyText(a.amount_cents, unknown()) }}</li></ul>
          <ReverseForm v-if="reversing?.kind === 'operations' && reversing.id === item.id" kind="operation" :supplier="supplier" :row="item as Operation" :can-reverse="canReverse" @changed="changed" @close="reversing = null" />
        </article>
      </template>
    </PagedCollection>
    <PagedCollection v-else-if="tab === 'corrections'" :supplier="supplier" collection="corrections" :unit-id="unitId" :reload-key="reloadKey">
      <template #row="{ item }">
        <article class="space-y-2 border-t border-neutral-200 py-2 text-sm" data-row="correction">
          <div class="flex flex-wrap items-center gap-3">
            <span class="min-w-0 flex-1">{{ (item as Correction).accounting_date }} · {{ item.unit_label }} · {{ (item as Correction).reason }}</span>
            <span class="rounded border border-neutral-300 px-2">{{ (item as Correction).status }}</span>
            <span>{{ t('propertyPayments.correction.relief') }}: {{ moneyText((item as Correction).relief_cents, unknown()) }}</span>
            <PropertyButton icon="eye" data-action="correction-open" @click="open('corrections', item.id)">{{ t('propertyPayments.detail') }}</PropertyButton>
            <PropertyButton v-if="canReverse" icon="uturn" variant="warning" data-action="correction-reverse" @click="reversing = { kind: 'corrections', id: item.id }">{{ t('propertyPayments.reverse.open') }}</PropertyButton>
          </div>
          <div v-if="detail?.kind === 'corrections' && detail.id === item.id" data-section="correction-detail">
            <ul class="list-disc pl-5"><li v-for="a in (detail.value as CorrectionDetail).anchors" :key="a.adjustment_line_id">{{ moneyText(a.amount_cents, unknown()) }} · {{ a.evidence }}</li></ul>
            <ul class="list-disc pl-5"><li v-for="r in (detail.value as CorrectionDetail).reliefs" :key="r.allocation_id">{{ moneyText(r.amount_cents, unknown()) }}</li></ul>
          </div>
          <ReverseForm v-if="reversing?.kind === 'corrections' && reversing.id === item.id" kind="correction" :supplier="supplier" :row="item as Correction" :can-reverse="canReverse" @changed="changed" @close="reversing = null" />
        </article>
      </template>
    </PagedCollection>
    <CorrectionForm v-else-if="canManage" :supplier="supplier" :unit-id="unitId" :can-manage="canManage" :can-post="canPost" @changed="changed" />
  </section>
</template>
