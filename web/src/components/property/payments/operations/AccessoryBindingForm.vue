<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type AccessoryCandidate, type DebtDetail } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { useBusyScope } from './busy'
import { useWriteFlow } from './flows'
import { buildAccessory, emptyAccessory, type AccessoryComponent } from './partForms'
import { failureMessage, moneyText } from './format'
import { boxClass, inputClass } from './ui'
const props = defineProps<{ supplier: number; detail: DebtDetail; canManage: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const scope = useBusyScope()
const state = ref(emptyAccessory())
const candidates = ref<AccessoryCandidate[]>([])
const next = ref<number | null>(null)
const loading = ref(false)
const loaded = ref(false)
const error = ref('')
const form = ref<HTMLFormElement | null>(null)
let epoch = 0
const components: AccessoryComponent[] = ['determined_cost', 'default_interest', 'interest']
const oldEffectOffered = computed(() => props.detail.legal.component_disposition?.old_interest_effect?.resolution === 'separately_evidenced')
const flow = useWriteFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false, canWrite: () => props.canManage,
  build: () => buildAccessory(state.value),
  write: (supplier, request) => propertyPaymentOperationsApi.bindAccessory(supplier, props.detail.id, request),
  onDone: () => { state.value = emptyAccessory(); emit('changed'); void load(false) },
})
function stop() { epoch++; if (loading.value) { loading.value = false; scope.end() } }
onBeforeUnmount(stop)
async function load(more: boolean) {
  if (more && (next.value === null || loading.value)) return
  const ticket = more ? epoch : ++epoch; const supplier = props.supplier; const debtId = props.detail.id
  if (!more) { if (loading.value) { loading.value = false; scope.end() } candidates.value = []; next.value = null; loaded.value = false }
  loading.value = true; scope.begin(); error.value = ''
  try {
    const page = await propertyPaymentOperationsApi.accessoryCandidates(supplier, more && next.value !== null ? next.value : 0, 25)
    if (ticket !== epoch || supplier !== props.supplier || debtId !== props.detail.id) return
    candidates.value = more ? [...candidates.value, ...page.items] : page.items
    next.value = page.next_cursor; loaded.value = true
  } catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) }
  finally { if (ticket === epoch && loading.value) { loading.value = false; scope.end() } }
}
watch(() => [props.supplier, props.detail.id], () => { stop(); state.value = emptyAccessory(); flow.reset(); void load(false) }, { immediate: true })
watch(() => state.value.component, component => { if (component !== 'interest') state.value.oldEffect = false })
const label = (c: AccessoryCandidate) => [c.document_number, c.date, c.label, c.account_code].filter(Boolean).join(' · ')
const actions = () => [{ key: 'accessory-bind', label: t('propertyPayments.accessory.submit'), icon: 'check', variant: 'primary', tier: 'primary', disabled: flow.busy.value || loading.value || !props.canManage || !state.value.candidate, run: () => { void flow.submit() } }] as ActionItem[]
</script>

<template>
  <section :class="boxClass" data-section="accessory-binding">
    <h4 class="font-semibold">{{ t('propertyPayments.accessory.title') }}</h4>
    <p class="text-sm text-neutral-500">{{ t('propertyPayments.accessory.help') }}</p>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <p v-if="loading && !candidates.length">{{ t('propertyPayments.loading') }}</p>
    <p v-else-if="loaded && !candidates.length" class="text-sm text-neutral-500" data-flag="no-accessories">{{ t('propertyPayments.accessory.none') }}</p>
    <form ref="form" class="space-y-3" @submit.prevent="flow.submit">
      <fieldset :disabled="flow.busy.value || !canManage" class="space-y-3">
        <label v-for="c in candidates" :key="c.id" class="flex flex-wrap items-center gap-2 border-t border-neutral-200 py-1 text-sm" data-row="accessory-candidate">
          <input type="radio" name="accessory-candidate" data-field="accessory-select" :checked="state.candidate?.id === c.id" @change="state.candidate = c">
          <span class="min-w-0 flex-1">{{ label(c) }}</span><span>{{ moneyText(c.amount_cents, t('propertyPayments.unknown')) }}</span>
        </label>
        <PropertyButton v-if="next !== null" icon="chevron" data-action="accessory-more" :disabled="loading || flow.busy.value" @click="load(true)">{{ t('propertyPayments.loadMore') }}</PropertyButton>
        <p class="text-xs text-neutral-500">{{ t('propertyPayments.accessory.discoveryNote') }}</p>
        <div v-if="state.candidate" class="grid gap-3 sm:grid-cols-2">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.accessory.component') }}<select v-model="state.component" required :class="inputClass" data-field="accessory-component"><option value="">{{ t('propertyPayments.choose') }}</option><option v-for="c in components" :key="c" :value="c">{{ t(`propertyPayments.component.${c}`) }}</option></select></label>
          <label v-if="oldEffectOffered && state.component === 'interest'" class="flex flex-wrap items-center gap-2 self-end"><input v-model="state.oldEffect" type="checkbox" data-field="accessory-old-effect">{{ t('propertyPayments.accessory.oldEffect') }}</label>
          <label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.accessory.evidence') }}<input v-model="state.evidence" required :class="inputClass" data-field="accessory-evidence"></label>
          <label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.accessory.liabilityEvidence') }}<input v-model="state.liability" required :class="inputClass" data-field="accessory-liability"></label>
        </div>
      </fieldset>
      <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
      <p v-if="flow.done.value" role="status" class="text-success-600">{{ t('propertyPayments.accessory.done') }}</p>
      <ActionBar :actions="actions()" />
    </form>
  </section>
</template>
