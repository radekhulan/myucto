<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import WorkReportBillingTemplates from '@/components/work-reports/WorkReportBillingTemplates.vue'
const { t } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const readOnly = computed(() => !auth.canWrite('price_list'))
// Ceník je dostupný jen firmám bez skladu (requiresNoStock).
const showPriceListLink = computed(() => auth.canRead('price_list') && !supplier.currentSupplier?.stock_enabled)
</script>
<template>
  <div class="w-full">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('work_report_billing.templates') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('work_report_billing.templates_subtitle') }}</p>
      </div>
      <RouterLink v-if="showPriceListLink" :to="{ name: 'admin-price-list' }" :class="[btnOutline('neutral'), 'whitespace-nowrap']">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('price_list.back_to_list') }}
      </RouterLink>
    </header>
    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <WorkReportBillingTemplates :read-only="readOnly" />
    </div>
  </div>
</template>
