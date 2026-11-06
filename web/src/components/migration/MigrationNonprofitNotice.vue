<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useSupplierStore } from '@/stores/supplier'

/**
 * Výrazné upozornění průvodců převodem, že převod pro neziskové organizace (a další
 * účetní jednotky podle vyhlášky 504) nebyl ověřen. Ukáže se, když je cílová firma
 * neziskovka nebo SVJ podle právní formy, vede rámec 504, nebo když upozornění vrátil
 * backend v náhledu převodu (`nonprofit_notice`, zná i historii rámce). `native` = převod
 * z exportu MyÚčto, který osnovu a deník přenese do firmy se shodným rámcem.
 */
const props = defineProps<{ notice?: { code?: string } | null; native?: boolean }>()
const { t } = useI18n()
const supplierStore = useSupplierStore()

const visible = computed(() => {
  if (props.notice) return true
  const current = supplierStore.currentSupplier
  return current?.entity_profile === 'nonprofit' || current?.entity_profile === 'svj' || current?.accounting_framework === 'cz_504'
})
</script>

<template>
  <div v-if="visible" class="flex items-start gap-3 rounded-lg border border-warning-500/60 bg-warning-50 px-4 py-3 text-sm text-warning-700" role="alert" data-testid="migration-nonprofit-notice">
    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
    <div>
      <p class="font-semibold">{{ t('migration_nonprofit_notice.title') }}</p>
      <p class="mt-1">{{ t(native ? 'migration_nonprofit_notice.body_native' : 'migration_nonprofit_notice.body') }}</p>
      <RouterLink to="/admin/support" class="mt-1 inline-block font-medium underline hover:no-underline">{{ t('migration_nonprofit_notice.contact') }}</RouterLink>
    </div>
  </div>
</template>
