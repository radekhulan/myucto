<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { MigrationPayrollNotice } from '@/api/migrationApi'
import { btnFilledSm, ICONS } from '@/components/ui/buttonStyles'

/**
 * Výrazné upozornění průvodců převodem, že zdroj obsahuje mzdy, které se do MyÚčta
 * nepřevedou: licence nezahrnuje mzdový doplněk, nebo firma nemá zapnutý modul Mzdy.
 * Převod účetnictví nic neblokuje, zaúčtování mezd nese převáděný účetní deník. Upozornění
 * vrací backend v náhledu převodu (`payroll_notice`); slot přidá akci průvodce (například
 * odkaz na převod mezd, který jde použít).
 */
const props = defineProps<{ notice?: MigrationPayrollNotice | null }>()
const { t } = useI18n()

function monthLabel(period: string | null): string {
  return period && /^\d{4}-\d{2}/.test(period) ? `${period.slice(5, 7)}/${period.slice(0, 4)}` : (period ?? '')
}
const period = computed(() => {
  const c = props.notice?.context
  if (!c) return ''
  if (c.first && c.last && c.first !== c.last) return t('migration_payroll_notice.period_range', { from: monthLabel(c.first), to: monthLabel(c.last) })
  return c.last ? t('migration_payroll_notice.period_single', { month: monthLabel(c.last) }) : ''
})
const unlicensed = computed(() => props.notice?.reason === 'unlicensed')
</script>

<template>
  <div v-if="notice" class="flex items-start gap-3 rounded-lg border border-warning-500/60 bg-warning-50 px-4 py-3 text-sm text-warning-700" role="alert" data-testid="migration-payroll-notice">
    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
    <div class="min-w-0">
      <p class="font-semibold">{{ t('migration_payroll_notice.title') }}</p>
      <p class="mt-1" data-testid="migration-payroll-notice-summary">{{ t('migration_payroll_notice.summary', { employees: notice.context.employees, months: notice.context.months, period }) }}</p>
      <p class="mt-1" :data-testid="`migration-payroll-notice-${notice.reason}`">
        <strong>{{ t(`migration_payroll_notice.reason.${notice.reason}`) }}</strong>
        {{ t(`migration_payroll_notice.todo.${notice.reason}`) }}
      </p>
      <p class="mt-1">{{ t('migration_payroll_notice.accounting') }}</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <RouterLink v-if="unlicensed" :to="notice.context.buy_url || '/activation/purchase#payroll-addon'" :class="btnFilledSm('primary')" class="whitespace-nowrap" data-testid="migration-payroll-notice-buy">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
          {{ t('migration_payroll_notice.buy') }}
        </RouterLink>
        <RouterLink v-else :to="{ name: 'admin-settings', query: { tab: 'accounting' } }" :class="btnFilledSm('primary')" class="whitespace-nowrap" data-testid="migration-payroll-notice-settings">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
          {{ t('migration_payroll_notice.settings') }}
        </RouterLink>
        <slot />
      </div>
    </div>
  </div>
</template>
