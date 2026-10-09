<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDateTime } from '@/composables/useFormat'
import type { TakeoverInvariants, TakeoverInvariantViolation } from '@/api/payrollTakeover'

/**
 * Brána G2: porušení invariantů převzetí. Kontroly vlastních dat (osoba dvakrát,
 * překryv verzí, úhrn bez vztahu) jsou spočítané teď, kontroly proti zdroji
 * (chybějící vztah, nesedící součty) jsou z posledního převodu daného zdroje.
 */
const props = defineProps<{ invariants: TakeoverInvariants }>()

const { t } = useI18n()

interface Group {
  key: string
  code: TakeoverInvariantViolation['code']
  source: string | null
  checkedAt: string | null
  texts: string[]
}

const groups = computed<Group[]>(() => {
  const out = new Map<string, Group>()
  const add = (violation: TakeoverInvariantViolation, source: string | null, checkedAt: string | null): void => {
    const key = `${source ?? 'live'}|${violation.code}`
    const group = out.get(key) ?? { key, code: violation.code, source, checkedAt, texts: [] }
    group.texts.push(violation.text)
    out.set(key, group)
  }
  for (const violation of props.invariants.live) add(violation, null, null)
  for (const check of props.invariants.stored) {
    for (const violation of check.violations) add(violation, check.source, check.checked_at)
  }
  return [...out.values()]
})
</script>

<template>
  <div
    v-if="groups.length > 0"
    class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
    data-test="takeover-check-invariants"
  >
    <p class="font-medium">{{ t('payroll.takeover_check.invariants_title') }}</p>
    <p class="mt-0.5 text-xs">{{ t('payroll.takeover_check.invariants_hint') }}</p>
    <div v-for="group in groups" :key="group.key" class="mt-3" :data-test="`takeover-invariant-${group.code}`">
      <p class="font-medium">
        {{ t(`payroll.takeover_check.invariant_codes.${group.code}`) }}
        <span v-if="group.source" class="ml-1 text-xs font-normal">
          {{ t('payroll.takeover_check.invariants_source', { source: group.source, at: formatDateTime(group.checkedAt) }) }}
        </span>
      </p>
      <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
        <li v-for="(text, index) in group.texts" :key="index">{{ text }}</li>
      </ul>
    </div>
  </div>
</template>
