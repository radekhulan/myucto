<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import type { PayrollJmhzBulkDeferral, PayrollJmhzDeferralContext, PayrollJmhzFreezeBlocker } from '@/api/payroll'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import JmhzBulkDefer from './JmhzBulkDefer.vue'
import { pathWithReturn } from './jmhzFreezeBlockers'

/**
 * Seznam nálezů, kvůli kterým nejde hlášení zmrazit: seskupené podle důvodu,
 * u každého zaměstnance tlačítko Opravit s návratem na přípravu JMHZ.
 */
const props = defineProps<{
  blockers: PayrollJmhzFreezeBlocker[]
  /** `YYYY-MM` vykazovaného období; podle něj se u ročního zúčtování předvyplní rok. */
  period?: string | null
  deferral?: PayrollJmhzDeferralContext | null
  canWrite?: boolean
}>()

const emit = defineEmits<{ deferred: [result: PayrollJmhzBulkDeferral] }>()

const { t } = useI18n()
const route = useRoute()

interface Row { key: string, label: string | null, path: string | null, fixed: boolean }
interface Group {
  code: string
  reason: string
  rows: Row[]
  /** Údaj se čte ze schváleného běhu: po opravě je potřeba opravná revize. */
  needsRevision: boolean
  /** Mzdové běhy měsíce, kde se oprava běhu otevírá. */
  revisionPath: string | null
}

const annualYear = computed(() => {
  const year = Number((props.period ?? '').slice(0, 4))
  return Number.isInteger(year) && year > 0 ? year - 1 : null
})

const groups = computed<Group[]>(() => {
  const byReason = new Map<string, Group>()
  props.blockers.forEach((blocker, index) => {
    const reason = blocker.reason || t('payroll.jmhz_gate.unlabelled')
    const group = byReason.get(reason)
      ?? { code: blocker.code, reason, rows: [], needsRevision: false, revisionPath: null }
    byReason.set(reason, group)
    if (blocker.needs_revision) {
      group.needsRevision = true
      group.revisionPath ??= blocker.revision_path
        ? pathWithReturn(blocker.revision_path, route.fullPath)
        : null
    }
    const key = `${blocker.entity_type}:${blocker.entity_id ?? index}`
    // Táž osoba nese nález za každé pole zvlášť, v seznamu se ukáže jednou.
    if (blocker.entity_id !== null && group.rows.some(row => row.key === key)) return
    group.rows.push({
      key,
      label: blocker.label,
      path: blocker.remediation_path
        ? pathWithReturn(blocker.remediation_path, route.fullPath, annualYear.value)
        : null,
      fixed: blocker.fixed_in_source === true,
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
    <JmhzBulkDefer
      v-if="deferral"
      :context="deferral"
      :can-write="canWrite === true"
      @deferred="emit('deferred', $event)"
    />
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
            <span
              v-if="row.fixed"
              class="ml-1 inline-flex whitespace-nowrap rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700"
              data-test="jmhz-freeze-blocker-fixed"
            >{{ t('payroll.jmhz_gate.freeze_blockers.fixed_in_source') }}</span>
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
      <!--
        Údaj čtený ze schváleného běhu: samotná oprava blokaci neodstraní.
        Bez téhle věty účetní údaj opraví a hlášení se zastaví na tomtéž.
      -->
      <div
        v-if="group.needsRevision"
        class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-danger-500/20 pt-3"
        data-test="jmhz-freeze-blocker-revision"
      >
        <p class="min-w-0 flex-1 text-xs text-danger-800">
          {{ group.rows.every(row => row.fixed)
            ? t('payroll.jmhz_gate.freeze_blockers.revision_only')
            : t('payroll.jmhz_gate.freeze_blockers.revision_hint') }}
        </p>
        <RouterLink
          v-if="group.revisionPath"
          :to="group.revisionPath"
          :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
          data-test="jmhz-freeze-blocker-revision-link"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.jmhz_gate.freeze_blockers.open_run') }}
        </RouterLink>
      </div>
    </section>
  </div>
</template>
