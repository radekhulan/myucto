<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertySettlementsApi as api, type SettlementDeadlines } from '@/api/propertySettlements'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { propertyError } from '@/components/property/propertyError'
import { formatDate } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { settlementInput as input, settlementBox as box } from './settlementForm'

const props = defineProps<{ supplier: number; documentId: number; manage: boolean }>()
const { t } = useI18n()
const read = ref<SettlementDeadlines | null>(null), busy = ref(false), error = ref(''), notice = ref('')
const recipient = ref(0), receivedOn = ref(appIsoDate()), content = ref('')
const resolving = ref<number | null>(null), resolvedOn = ref(appIsoDate()), resolution = ref('')
let epoch = 0
onBeforeUnmount(() => epoch++)

const names = computed(() => Object.fromEntries((read.value?.parties ?? []).map(p => [p.id, p.name])))
const person = (id: number) => names.value[id] || t('propertySettlements.selectedPerson')
const generalWarnings = computed(() => (read.value?.warnings ?? []).filter(w => w.recipient_id === undefined))

async function load() {
 const supplier = props.supplier, ticket = ++epoch
 busy.value = true; error.value = ''
 try {
  const result = await api.deadlines(supplier, props.documentId)
  if (ticket !== epoch) return
  read.value = result
  if (!recipient.value && result.parties.length) recipient.value = result.parties[0]!.id
 } catch (e) { if (ticket === epoch) error.value = propertyError(e, t) }
 finally { if (ticket === epoch) busy.value = false }
}
watch(() => [props.supplier, props.documentId], () => { read.value = null; void load() }, { immediate: true })

async function record() {
 if (!props.manage || busy.value || !recipient.value || !receivedOn.value || !content.value.trim()) return
 const ticket = ++epoch
 busy.value = true; error.value = ''; notice.value = ''
 try {
  await api.recordObjection(props.supplier, props.documentId, { recipient_id: recipient.value, received_on: receivedOn.value, content: content.value })
  if (ticket !== epoch) return
  content.value = ''; notice.value = t('propertySettlements.deadlines.objectionRecorded'); busy.value = false
  await load()
 } catch (e) { if (ticket === epoch) error.value = propertyError(e, t) }
 finally { if (ticket === epoch) busy.value = false }
}

async function resolve() {
 if (!props.manage || busy.value || resolving.value === null || !resolvedOn.value || !resolution.value.trim()) return
 const ticket = ++epoch
 busy.value = true; error.value = ''; notice.value = ''
 try {
  await api.resolveObjection(props.supplier, resolving.value, { resolved_on: resolvedOn.value, resolution: resolution.value })
  if (ticket !== epoch) return
  resolving.value = null; resolution.value = ''; notice.value = t('propertySettlements.deadlines.objectionResolved'); busy.value = false
  await load()
 } catch (e) { if (ticket === epoch) error.value = propertyError(e, t) }
 finally { if (ticket === epoch) busy.value = false }
}

const recordActions = computed<ActionItem[]>(() => [
 { key: 'record-objection', label: t('propertySettlements.deadlines.recordObjection'), icon: 'plus', variant: 'primary', tier: 'primary', disabled: busy.value || !props.manage || !recipient.value || !receivedOn.value || !content.value.trim(), run: () => { void record() } },
 { key: 'refresh-deadlines', label: t('propertySettlements.refresh'), icon: 'cycle', tier: 'secondary', disabled: busy.value, run: () => { void load() } },
])
const resolveActions = computed<ActionItem[]>(() => [
 { key: 'resolve-objection', label: t('propertySettlements.deadlines.resolve'), icon: 'check', variant: 'success', tier: 'primary', disabled: busy.value || !props.manage || !resolvedOn.value || !resolution.value.trim(), run: () => { void resolve() } },
 { key: 'cancel-resolve', label: t('propertySettlements.cancel'), icon: 'x', tier: 'secondary', disabled: busy.value, run: () => { resolving.value = null } },
])
</script>

<template>
 <section :class="box" data-section="deadlines">
  <h2 class="font-semibold">{{ t('propertySettlements.deadlines.title') }}</h2>
  <p class="text-sm text-neutral-500">{{ t('propertySettlements.deadlines.help') }}</p>
  <p v-if="error" role="alert" class="rounded border border-danger-300 bg-danger-50 p-3 text-danger-700">{{ error }}</p>
  <p v-if="notice" role="status" class="rounded border p-3">{{ notice }}</p>
  <template v-if="read">
   <p class="text-sm">{{ t('propertySettlements.deadlines.deliveryDeadline', { date: formatDate(read.delivery_deadline) }) }}</p>
   <p v-for="(w, i) in generalWarnings" :key="`general-${i}`" class="rounded border border-warning-300 bg-warning-50 p-2 text-sm text-warning-700" data-warning="general">{{ t(`propertySettlements.deadlines.warning.${w.code}`) }}</p>
   <p v-if="!read.deliveries.length" class="text-sm text-neutral-500">{{ t('propertySettlements.deadlines.noDeliveries') }}</p>
   <article v-for="d in read.deliveries" :key="`${d.segment_ordinal}:${d.recipient_id}`" class="space-y-1 rounded border p-3" data-row="delivery">
    <h3 class="font-medium">{{ person(d.recipient_id) }}</h3>
    <p class="text-sm">
     {{ d.delivered_on ? t('propertySettlements.deadlines.deliveredOn', { date: formatDate(d.delivered_on) }) : t('propertySettlements.deadlines.notDelivered') }}
     <template v-if="d.due_limit"> · {{ t('propertySettlements.deadlines.dueLimit', { date: formatDate(d.due_limit) }) }}</template>
     <template v-if="d.due_date"> · {{ t('propertySettlements.deadlines.dueDate', { date: formatDate(d.due_date) }) }}</template>
     <template v-if="d.objection_deadline"> · {{ t('propertySettlements.deadlines.objectionDeadline', { date: formatDate(d.objection_deadline) }) }}</template>
    </p>
    <div class="flex flex-wrap gap-2">
     <span v-for="code in d.warnings" :key="code" class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700 whitespace-nowrap" :data-warning="code">{{ t(`propertySettlements.deadlines.warning.${code}`) }}</span>
    </div>
   </article>
   <h3 class="font-medium">{{ t('propertySettlements.deadlines.objections') }}</h3>
   <p v-if="!read.objections.length" class="text-sm text-neutral-500">{{ t('propertySettlements.deadlines.noObjections') }}</p>
   <article v-for="o in read.objections" :key="o.id" class="space-y-1 rounded border p-3" data-row="objection">
    <p class="font-medium">{{ person(o.recipient_id) }} · {{ t('propertySettlements.deadlines.receivedOn', { date: formatDate(o.received_on) }) }}</p>
    <p class="text-sm">{{ o.content }}</p>
    <p class="text-sm">
     <template v-if="o.resolved_on">{{ t('propertySettlements.deadlines.resolvedOn', { date: formatDate(o.resolved_on) }) }}: {{ o.resolution }}</template>
     <template v-else>{{ t('propertySettlements.deadlines.resolutionDeadline', { date: formatDate(o.resolution_deadline) }) }}</template>
    </p>
    <div class="flex flex-wrap gap-2">
     <span v-for="code in o.warnings" :key="code" class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700 whitespace-nowrap" :data-warning="code">{{ t(`propertySettlements.deadlines.warning.${code}`) }}</span>
    </div>
    <ActionBar v-if="manage && !o.resolved_on && resolving !== o.id" :actions="[{ key: `begin-resolve-${o.id}`, label: t('propertySettlements.deadlines.resolve'), icon: 'edit', variant: 'neutral', tier: 'secondary', disabled: busy, run: () => { resolving = o.id } }]" />
    <template v-if="resolving === o.id">
     <fieldset :disabled="busy" class="grid gap-3 sm:grid-cols-2">
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.deadlines.resolvedOnLabel') }}<DateInput :class="input" v-model="resolvedOn" required data-field="resolved-on" /></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm sm:col-span-2">{{ t('propertySettlements.deadlines.resolution') }}<textarea v-model="resolution" :class="input" data-field="resolution" /></label>
     </fieldset>
     <ActionBar :actions="resolveActions" />
    </template>
   </article>
   <template v-if="manage">
    <fieldset :disabled="busy" class="grid gap-3 sm:grid-cols-2">
     <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.deadlines.recipient') }}<select v-model.number="recipient" :class="input" data-field="objection-recipient"><option v-for="p in read.parties" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
     <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.deadlines.receivedOnLabel') }}<DateInput :class="input" v-model="receivedOn" required data-field="objection-received" /></label>
     <label class="flex min-w-0 flex-col gap-1 text-sm sm:col-span-2">{{ t('propertySettlements.deadlines.content') }}<textarea v-model="content" :class="input" data-field="objection-content" /></label>
    </fieldset>
   </template>
   <ActionBar :actions="manage ? recordActions : recordActions.slice(1)" />
  </template>
  <p v-else-if="busy" class="text-sm text-neutral-500">{{ t('propertySettlements.deadlines.loading') }}</p>
 </section>
</template>
