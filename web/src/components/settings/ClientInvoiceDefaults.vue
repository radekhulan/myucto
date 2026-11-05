<script setup lang="ts">
import { onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type InvoiceDefaultsPayload, type InvoiceDefaultsSettings } from '@/api/settings'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { requestSupplierIdNumber } from '@/workspace/supplierContext'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import InvoiceDefaultsFields from '@/components/settings/InvoiceDefaultsFields.vue'
import { pendingInvoiceDefaultsSave, trackInvoiceDefaultsSave } from '@/components/settings/invoiceDefaultsPending'

const { t } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const settings = ref<InvoiceDefaultsSettings | null>(null)
const loading = ref(true)
const saving = ref(false)
let active = true
let activatedOnce = false
let generation = 0
let pending: Promise<void> = Promise.resolve()

function isCurrent(id: number, version: number) {
  const requestId = requestSupplierIdNumber()
  return active && generation === version && supplier.currentSupplierId === id
    && (!requestId || requestId === id) && auth.canWrite('settings.invoice_defaults')
}

async function refresh() {
  const id = supplier.currentSupplierId
  const version = generation
  loading.value = true
  await pendingInvoiceDefaultsSave(id)
  if (!isCurrent(id, version)) return
  try {
    const result = await settingsApi.getClientInvoiceDefaults()
    if (isCurrent(id, version) && result.id === id) settings.value = result
  } catch (error: any) {
    if (isCurrent(id, version)) {
      settings.value = null
      toast.error(error?.response?.data?.error?.message || t('common.error'))
    }
  } finally {
    if (isCurrent(id, version)) loading.value = false
  }
}

function save() {
  if (blockDemoMutation() || !settings.value || saving.value) return
  const id = settings.value.id
  const version = generation
  if (!isCurrent(id, version)) return
  const { default_payment_due_days, default_payment_due_unit, default_hourly_rate,
    default_note_below_items_enabled, default_note_below_items_cs, default_note_below_items_en } = settings.value
  const payload: InvoiceDefaultsPayload = { default_payment_due_days, default_payment_due_unit, default_hourly_rate,
    default_note_below_items_enabled, default_note_below_items_cs, default_note_below_items_en }
  saving.value = true
  pending = (async () => {
    try {
      const result = await settingsApi.updateClientInvoiceDefaults(payload, id)
      if (!isCurrent(id, version)) return
      settings.value = result
      supplier.patchSupplier(id, { default_payment_due_days: result.default_payment_due_days,
        default_payment_due_unit: result.default_payment_due_unit,
        default_hourly_rate: result.default_hourly_rate,
        default_note_below_items_enabled: result.default_note_below_items_enabled,
        default_note_below_items_cs: result.default_note_below_items_cs, default_note_below_items_en: result.default_note_below_items_en })
      toast.success(t('common.saved'))
    } catch (error: any) {
      if (isCurrent(id, version)) toast.error(error?.response?.data?.error?.message || t('common.error'))
    } finally {
      saving.value = false
    }
  })()
  trackInvoiceDefaultsSave(id, pending)
}

watch(() => supplier.currentSupplierId, () => { generation++; settings.value = null; if (active) void refresh() }, { flush: 'sync' })
watch(() => auth.canWrite('settings.invoice_defaults'), allowed => {
  generation++
  settings.value = null
  if (allowed && active) void refresh()
}, { flush: 'sync' })
onMounted(() => { void refresh() })
onActivated(() => {
  active = true
  if (activatedOnce) void refresh()
  else activatedOnce = true
})
onDeactivated(() => { active = false; generation++ })
onBeforeUnmount(() => { active = false; generation++ })
</script>

<template>
  <section class="max-w-5xl rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
    <h2 class="mb-5 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.invoice_defaults_title') }}</h2>
    <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <form v-else-if="settings && auth.canWrite('settings.invoice_defaults')" @submit.prevent="save">
      <fieldset :disabled="saving">
        <InvoiceDefaultsFields :model-value="settings" />
      </fieldset>
      <div class="flex flex-wrap border-t border-neutral-200 mt-5 pt-4">
        <button type="submit" :disabled="saving" :class="btnFilled('primary')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? '…' : t('common.save') }}
        </button>
      </div>
    </form>
  </section>
</template>
