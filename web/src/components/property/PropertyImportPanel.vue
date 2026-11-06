<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyRegistryApi, type ImportPreview, type PropertyObject, type HouseState, type ServiceState } from '@/api/propertyRegistry'
import PropertyInstantInput from './PropertyInstantInput.vue'
import PropertyPartyPicker from './PropertyPartyPicker.vue'
import PropertyButton from './PropertyButton.vue'
import { propertyError } from './propertyError'
import { propertyInput as input } from './propertyFormStyles'
const props = defineProps<{ supplierId: number; houses: PropertyObject[]; services: PropertyObject[]; columns: string[]; asOf: string; canConfirm: boolean }>()
const emit = defineEmits<{ applied: [] }>()
const { t } = useI18n()
const form = reactive({ house_id: props.houses[0]?.id ?? 0, effective_from: props.asOf, effective_to: '', status: 'pending', reason: '', title_ref: '' })
const partyMap = reactive<Record<string, number>>({})
const serviceMap = reactive<Record<string, number>>({})
const names = reactive<Record<number, string>>({})
const result = ref<ImportPreview | null>(null)
const valid = ref(false)
const busy = ref(false)
const error = ref('')
const content = ref('')
const format = ref('csv')
const fileName = ref('')
let epoch = 0
let token = crypto.randomUUID()
onBeforeUnmount(() => epoch++)
watch([form, partyMap, serviceMap, content], () => { valid.value = false; epoch++ }, { deep: true, flush: 'sync' })
const canPreview = computed(() => !busy.value && !!content.value && form.house_id > 0 && !!form.reason.trim() && !!form.title_ref.trim())
const label = (object: PropertyObject) => (object.revision?.state as HouseState | ServiceState | undefined)?.name ?? object.code
function message(e: unknown) { return propertyError(e, t) }
async function fileChanged(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  content.value = ''; result.value = null; error.value = ''
  if (!file) return
  const extension = file.name.split('.').pop()?.toLowerCase()
  if (!['csv', 'xlsx'].includes(extension ?? '') || file.size > 5_000_000) { error.value = t('propertyRegistry.importFileInvalid'); return }
  const ticket = ++epoch
  const data = await new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result).split(',')[1] ?? ''); reader.onerror = reject; reader.readAsDataURL(file) }).catch(() => '')
  if (ticket !== epoch) return
  content.value = data; format.value = extension!; fileName.value = file.name
}
async function preview() {
  if (!canPreview.value) return
  const ticket = ++epoch; busy.value = true; error.value = ''
  try {
    const response = await propertyRegistryApi.importPreview(props.supplierId, { ...form, effective_to: form.effective_to || null, format: format.value, content_base64: content.value, party_map: { ...partyMap }, service_map: { ...serviceMap } })
    if (ticket !== epoch) return
    result.value = response; valid.value = response.ready && !!response.plan && !!response.preview_hash; token = crypto.randomUUID()
  } catch (e) { if (ticket === epoch) error.value = message(e) } finally { busy.value = false }
}
async function apply() {
  if (!valid.value || busy.value || !result.value?.plan || !result.value.preview_hash) return
  const ticket = epoch; busy.value = true; error.value = ''
  try {
    await propertyRegistryApi.importApply(props.supplierId, result.value.plan, result.value.preview_hash, token)
    if (ticket !== epoch) return
    valid.value = false; result.value = null; content.value = ''; fileName.value = ''; emit('applied')
  } catch (e) { if (ticket === epoch) error.value = message(e) } finally { busy.value = false }
}
function template() {
  const blob = new Blob(['\ufeff' + props.columns.join(';') + '\r\n'], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'property-registry-template.csv'; link.click(); URL.revokeObjectURL(url)
}
</script>
<template>
  <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4">
    <h2 class="text-lg font-semibold">{{ t('propertyRegistry.importTitle') }}</h2><p class="text-sm text-neutral-500">{{ t('propertyRegistry.importHelp') }}</p>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <form class="space-y-4" @submit.prevent="preview">
    <fieldset :disabled="busy" class="space-y-3">
      <div class="flex flex-wrap items-center gap-3"><input type="file" accept=".csv,.xlsx" :aria-label="t('propertyRegistry.importFile')" @change="fileChanged"><PropertyButton icon="download" @click="template">{{ t('propertyRegistry.template') }}</PropertyButton></div>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.kinds.house') }}<select v-model="form.house_id" :class="input"><option :value="0">{{ t('propertyRegistry.chooseHouse') }}</option><option v-for="house in houses" :key="house.id" :value="house.id">{{ label(house) }}</option></select></label>
      <PropertyInstantInput v-model="form.effective_from" required :label="t('propertyRegistry.effectiveFrom')" />
      <details><summary>{{ t('propertyRegistry.optionalEnd') }}</summary><PropertyInstantInput v-model="form.effective_to" :label="t('propertyRegistry.effectiveTo')" /><PropertyButton icon="x" @click="form.effective_to = ''">{{ t('propertyRegistry.noEnd') }}</PropertyButton></details>
      <div class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.reason') }}<input v-model="form.reason" :class="input"></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }}<input v-model="form.title_ref" :class="input"></label></div>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.status') }}<select v-model="form.status" :class="input"><option value="pending">{{ t('propertyRegistry.statuses.pending') }}</option><option v-if="canConfirm" value="confirmed">{{ t('propertyRegistry.statuses.confirmed') }}</option></select></label>
      <div v-if="result?.unresolved_parties.length" class="space-y-3"><h3 class="font-medium">{{ t('propertyRegistry.mapParties') }}</h3><div v-for="party in result.unresolved_parties" :key="party.reference" class="space-y-1"><p class="text-sm">{{ party.name || party.reference }} <span class="text-neutral-500">{{ party.email }}</span></p><PropertyPartyPicker :supplier-id="supplierId" :model-value="partyMap[party.reference] ?? null" :selected-label="names[partyMap[party.reference] ?? 0]" @update:model-value="$event === null ? delete partyMap[party.reference] : partyMap[party.reference] = $event" @selected="names[$event.id] = $event.name" /></div></div>
      <div v-for="reference in result?.unresolved_services ?? []" :key="reference"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.mapService', { reference }) }}<select v-model="serviceMap[reference]" :class="input"><option v-for="service in services" :key="service.id" :value="service.id">{{ label(service) }}</option></select></label></div>
    </fieldset>
    <div v-if="result?.errors.length" role="alert" class="space-y-1 text-danger-600"><p v-for="(item, index) in result.errors" :key="index">{{ item.row ? t('propertyRegistry.row', { n: item.row }) : '' }} {{ item.message }}</p></div>
    <div v-if="result?.plan" class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="text-left">{{ t('propertyRegistry.unitNumber') }}</th><th class="text-left">{{ t('propertyRegistry.propertyShare') }}</th><th class="text-left">{{ t('propertyRegistry.change') }}</th></tr></thead><tbody><tr v-for="unit in result.plan.units" :key="unit.code"><td>{{ unit.state.unit_no }}</td><td>{{ unit.state.attributes.share?.num }}/{{ unit.state.attributes.share?.den }}</td><td>{{ t(unit.object_id ? 'propertyRegistry.newRevision' : 'propertyRegistry.newObject') }}</td></tr></tbody></table></div>
    <p v-if="busy" role="status">{{ t('propertyRegistry.working') }}</p><p v-else-if="!canPreview" class="text-sm text-neutral-500">{{ t('propertyRegistry.importRequired') }}</p><p v-else-if="!valid" class="text-sm text-neutral-500">{{ t('propertyRegistry.previewRequired') }}</p>
    <div class="flex flex-wrap gap-2"><PropertyButton icon="eye" submit :disabled="!canPreview">{{ t('propertyRegistry.preview') }}</PropertyButton><PropertyButton icon="check" variant="success" filled :disabled="!valid || busy" @click="apply">{{ t('propertyRegistry.applyImport') }}</PropertyButton><span class="self-center text-sm text-neutral-500">{{ fileName }}</span></div>
    </form>
  </section>
</template>
