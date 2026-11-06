<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import type { PayrollJmhzFreezeBlocker } from '@/api/payroll'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { pathWithReturn } from './jmhzFreezeBlockers'

/**
 * Seznam nálezů, kvůli kterým nejde hlášení zmrazit: seskupené podle důvodu,
 * u každého zaměstnance tlačítko Opravit s návratem na přípravu JMHZ.
 */
const props = defineProps<{
  blockers: PayrollJmhzFreezeBlocker[]
  /** `YYYY-MM` vykazovaného období; podle něj se u ročního zúčtování předvyplní rok. */
  period?: string | null
}>()

const { t } = useI18n()
const route = useRoute()

interface Row { key: string, label: string | null, path: string | null }
interface Group { code: string, reason: string, rows: Row[] }

const annualYear = computed(() => {
  const year = Number((props.period ?? '').slice(0, 4))
  return Number.isInteger(year) && year > 0 ? year - 1 : null
})

const groups = computed<Group[]>(() => {
  const byReason = new Map<string, Group>()
  props.blockers.forEach((blocker, index) => {
    const reason = blocker.reason || t('payroll.jmhz_gate.unlabelled')
    const group = byReason.get(reason) ?? { code: blocker.code, reason, rows: [] }
    byReason.set(reason, group)
    const key = `${blocker.entity_type}:${blocker.entity_id ?? index}`
    // Táž osoba nese nález za každé pole zvlášť, v seznamu se ukáže jednou.
    if (blocker.entity_id !== null && group.rows.some(row => row.key === key)) return
    group.rows.push({
      key,
      label: blocker.label,
      path: blocker.remediation_path
        ? pathWithReturn(blocker.remediation_path, route.fullPath, annualYear.value)
        : null,
    })
  })
  return [...byReason.values()]
})
</script>

<template>
  <div class="mt-3 space-y-3" data-test="jmhz-freeze-blockers">
    <p class="text-sm font-medium text-danger-700">
      {{ t('payroll.jmhz_gate.freeze_blockers.title') }}
    </p>
    <p class="text-xs text-neutral-500">
      {{ t('payroll.jmhz_gate.freeze_blockers.hint') }}
    </p>
    <section
      v-for="group in groups"
      :key="group.reason"
      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3"
      :data-test="`jmhz-freeze-blocker-group-${group.code}`"
    >
      <h4 class="text-sm font-semibold text-danger-800">{{ group.reason }}</h4>
      <ul class="mt-2 space-y-2">
        <li
          v-for="row in group.rows"
          :key="row.key"
          class="flex flex-wrap items-center justify-between gap-2"
          data-test="jmhz-freeze-blocker-row"
        >
          <span class="text-sm text-neutral-900">
            {{ row.label ?? t('payroll.jmhz_gate.freeze_blockers.no_name') }}
          </span>
          <RouterLink
            v-if="row.path"
            :to="row.path"
            :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
            :title="t('payroll.jmhz_gate.freeze_blockers.fix_for', { label: row.label ?? '' })"
            data-test="jmhz-freeze-blocker-fix"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ t('payroll.jmhz_gate.freeze_blockers.fix') }}
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>
