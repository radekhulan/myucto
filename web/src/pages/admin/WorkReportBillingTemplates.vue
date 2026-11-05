<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import WorkReportBillingTemplates from '@/components/work-reports/WorkReportBillingTemplates.vue'
const { t } = useI18n()
const auth = useAuthStore()
const readOnly = computed(() => !auth.canWrite('price_list'))
</script>
<template>
  <div class="w-full">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold">{{ t('work_report_billing.templates') }}</h1>
    </header>
    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <WorkReportBillingTemplates :read-only="readOnly" />
    </div>
  </div>
</template>
