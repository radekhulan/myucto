<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type PaymentQrSettings } from '@/api/settings'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const settings = ref<PaymentQrSettings | null>(null)
const loading = ref(true)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    settings.value = await settingsApi.getClientPaymentQrSettings()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function save() {
  if (blockDemoMutation() || !settings.value) return
  saving.value = true
  try {
    settings.value = await settingsApi.updateClientPaymentQrSettings(settings.value)
    toast.success(t('common.saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="max-w-5xl rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
    <header class="mb-5">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
        {{ t('settings.payment_qr_due_date_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('settings.payment_qr_due_date_hint') }}</p>
    </header>

    <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <form v-else-if="settings" class="space-y-5" @submit.prevent="save">
      <label class="flex cursor-pointer items-start gap-3">
        <input
          v-model="settings.invoice_qr_include_due_date"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-primary-600"
        />
        <span>
          <span class="block text-sm font-medium text-neutral-700">{{ t('settings.invoice_qr_include_due_date') }}</span>
          <span class="mt-0.5 block text-xs text-neutral-500">{{ t('settings.invoice_qr_include_due_date_hint') }}</span>
        </span>
      </label>

      <label class="flex cursor-pointer items-start gap-3">
        <input
          v-model="settings.purchase_invoice_qr_include_due_date"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-primary-600"
        />
        <span>
          <span class="block text-sm font-medium text-neutral-700">{{ t('settings.purchase_invoice_qr_include_due_date') }}</span>
          <span class="mt-0.5 block text-xs text-neutral-500">{{ t('settings.purchase_invoice_qr_include_due_date_hint') }}</span>
        </span>
      </label>

      <div class="flex flex-wrap border-t border-neutral-200 pt-4">
        <button type="submit" :disabled="saving" :class="btnFilled('primary')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
          </svg>
          {{ saving ? '…' : t('common.save') }}
        </button>
      </div>
    </form>
  </section>
</template>
