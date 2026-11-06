<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type AboMetadata, type Reservation } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { downloadAbo } from './aboDownload'
import { useBusyScope } from './busy'
import { failureMessage } from './format'

const props = defineProps<{ supplier: number; reservation: Reservation; canManage: boolean; reloadKey: number }>()
const { t } = useI18n()
const scope = useBusyScope()
const metadata = ref<AboMetadata | null>(null)
const loading = ref(false)
const downloading = ref(false)
const error = ref('')
let epoch = 0
const historical = (value: AboMetadata) => value.reservation_ids.some(id => value.reservation_statuses[id] !== 'submitted')
const archive = computed(() => metadata.value !== null && historical(metadata.value))
function reset() {
  epoch++
  if (downloading.value) { downloading.value = false; scope.end() }
  metadata.value = null; error.value = ''; loading.value = false
}
onBeforeUnmount(reset)
async function load() {
  reset()
  const ticket = epoch; const supplier = props.supplier; const reservation = props.reservation.id
  loading.value = true
  try {
    const value = await propertyPaymentOperationsApi.aboMetadata(supplier, reservation)
    if (ticket !== epoch || supplier !== props.supplier) return
    if (value && !value.reservation_ids.includes(reservation)) throw new Error(t('propertyPayments.failed'))
    metadata.value = value
  } catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) }
  finally { if (ticket === epoch) loading.value = false }
}
watch(() => [props.supplier, props.reservation.id, props.reservation.row_version, props.reloadKey], () => { void load() }, { immediate: true })
async function download() {
  const value = metadata.value; const supplier = props.supplier; const ticket = epoch
  if (!value || !props.canManage || loading.value || downloading.value || scope.busy.value) return
  downloading.value = true; scope.begin(); error.value = ''
  try {
    const result = await propertyPaymentOperationsApi.aboDownload(supplier, value.id)
    if (ticket !== epoch || supplier !== props.supplier || !props.canManage) return
    if (result.id !== value.id || !result.reservation_ids.includes(props.reservation.id)) throw new Error(t('propertyPayments.failed'))
    metadata.value = { id: result.id, filename: result.filename, encoding: result.encoding, reservation_ids: result.reservation_ids, reservation_statuses: result.reservation_statuses }
    if (historical(result) && !historical(value)) return
    downloadAbo(result.filename, result.content_base64, result.encoding)
  } catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) }
  finally { if (ticket === epoch && downloading.value) { downloading.value = false; scope.end() } }
}
</script>

<template>
  <section v-if="metadata || error" class="space-y-2 rounded border border-neutral-200 p-3 text-sm" data-section="existing-abo">
    <template v-if="metadata">
      <p data-field="existing-abo-filename">{{ t('propertyPayments.abo.existing', { filename: metadata.filename }) }}</p>
      <p v-for="id in metadata.reservation_ids" :key="id" data-row="existing-abo-status" :data-status="metadata.reservation_statuses[id]">{{ t('propertyPayments.abo.reservation') }} {{ id }} · {{ t(`propertyPayments.reservation.status.${metadata.reservation_statuses[id]}`) }}</p>
      <p v-if="archive" role="alert" class="text-warning-600" data-flag="existing-abo-history">{{ t('propertyPayments.abo.historyWarning') }}</p>
      <PropertyButton v-if="canManage" icon="download" variant="neutral" :disabled="loading || downloading || scope.busy.value" data-action="existing-abo-download" @click="download">{{ archive ? t('propertyPayments.abo.archiveDownload') : t('propertyPayments.abo.download') }}</PropertyButton>
    </template>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <PropertyButton v-if="error" icon="eye" variant="neutral" :disabled="loading || downloading" data-action="existing-abo-retry" @click="load">{{ t('propertyPayments.abo.metadataRetry') }}</PropertyButton>
  </section>
</template>
