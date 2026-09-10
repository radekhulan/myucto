<script setup lang="ts">
/**
 * Výpis kontrol prostředí — sdílený mezi Systém → Diagnostika a kontrolou
 * prostředí v setup wizardu, ať čtenář vidí na obou místech totéž.
 *
 * Problémy jdou nahoru, `ok` dolů. U seznamových kontrol (chybějící rozšíření,
 * adresáře bez zápisu) se popisek řádku přebíjí přes i18n — „Naměřeno: intl"
 * se totiž čte jako „nainstalováno je jen intl", což je pravý opak pravdy.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DiagnosticCheck } from '@/api/diagnostics'

const props = withDefaults(
  defineProps<{
    checks: DiagnosticCheck[]
    /** Skrýt kontroly, které dopadly dobře — v setupu zajímá jen to, co opravit. */
    problemsOnly?: boolean
  }>(),
  { problemsOnly: false },
)

const { t, te } = useI18n()

const ORDER: Record<string, number> = { fail: 0, warn: 1, skip: 2, ok: 3 }

const visibleChecks = computed<DiagnosticCheck[]>(() => {
  const list = props.problemsOnly
    ? props.checks.filter((c) => c.status === 'fail' || c.status === 'warn')
    : [...props.checks]

  return list.sort((a, b) => (ORDER[a.status] ?? 9) - (ORDER[b.status] ?? 9))
})

/** Popisek kontroly z i18n; když klíč chybí, ukáže se aspoň `id`. */
function checkText(id: string, part: 'label' | 'impact' | 'fix'): string {
  const key = `diagnostics.checks.${id}.${part}`
  return te(key) ? t(key) : part === 'label' ? id : ''
}

/** Popisek hodnoty — kontrola si ho smí přebít vlastním klíčem. */
function valueLabel(id: string, part: 'actual' | 'expected' | 'info'): string {
  const key = `diagnostics.checks.${id}.${part}_label`
  return te(key) ? t(key) : t(`diagnostics.${part}`)
}

/** Strojové reason codes přeloží jen tehdy, když pro ně kontrola má slovník. */
function checkValue(check: DiagnosticCheck, part: 'actual' | 'expected' | 'info'): string {
  const value = check[part] ?? ''
  const key = `diagnostics.checks.${check.id}.values.${value}`
  return value && te(key) ? t(key) : value
}

interface CheckFinding {
  severity: string
  code: string
  object: string
  expected: string
  actual: string
}

/** Jednotlivé nálezy kontroly, která je nese v `meta.findings` (struktura databáze). */
function findingsOf(check: DiagnosticCheck): CheckFinding[] {
  const list = check.meta?.findings
  return Array.isArray(list) ? (list as CheckFinding[]) : []
}

/** Kolik nálezů se kvůli stropu na stránku nevešlo. */
function moreFindings(check: DiagnosticCheck): number {
  return Math.max(0, Number(check.meta?.total ?? 0) - findingsOf(check).length)
}

function findingLabel(code: string): string {
  const key = `diagnostics.schema_findings.${code}`
  return te(key) ? t(key) : code
}

function findingTitle(finding: CheckFinding): string {
  return [finding.expected, finding.actual].filter((v) => v !== '').join(' → ')
}

function rowClass(status: string): string {
  switch (status) {
    case 'fail':
      return 'border-danger-500/40 bg-danger-50/30'
    case 'warn':
      return 'border-warning-200 bg-warning-50/40'
    case 'skip':
      return 'border-neutral-200 bg-neutral-50'
    default:
      return 'border-neutral-200 bg-surface'
  }
}

function statusPill(status: string): string {
  const base = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium shrink-0'
  switch (status) {
    case 'fail':
      return `${base} bg-danger-50 text-danger-600`
    case 'warn':
      return `${base} bg-warning-100 text-warning-800`
    case 'skip':
      return `${base} bg-neutral-100 text-neutral-600`
    default:
      return `${base} bg-success-50 text-success-700`
  }
}
</script>

<template>
  <ul class="space-y-2">
    <li
      v-for="check in visibleChecks"
      :key="check.id"
      class="rounded-md border px-3 py-2.5"
      :class="rowClass(check.status)"
    >
      <div class="flex flex-wrap items-start gap-2">
        <span :class="statusPill(check.status)">{{ t(`diagnostics.status.${check.status}`) }}</span>
        <span class="text-sm font-medium text-neutral-900">{{ checkText(check.id, 'label') }}</span>
      </div>

      <dl class="mt-1.5 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-0.5 text-xs text-neutral-600">
        <div v-if="check.actual" class="flex gap-1.5">
          <dt class="text-neutral-500 shrink-0">{{ valueLabel(check.id, 'actual') }}:</dt>
          <!-- U nálezu je `actual` to špatné (chybějící rozšíření, nízký limit) —
               ať to jde poznat na první pohled, ne až po přečtení pilulky vlevo. -->
          <dd
            class="font-mono break-all"
            :class="check.status === 'fail' || check.status === 'warn' ? 'text-danger-600 font-semibold' : ''"
          >{{ checkValue(check, 'actual') }}</dd>
        </div>
        <div v-if="check.expected" class="flex gap-1.5">
          <dt class="text-neutral-500 shrink-0">{{ valueLabel(check.id, 'expected') }}:</dt>
          <dd class="font-mono break-all">{{ checkValue(check, 'expected') }}</dd>
        </div>
        <!-- `info` je informace, ne nález — zůstává šedá i u kontroly ve stavu fail,
             ať se nepřičte k tomu, co má uživatel opravovat. -->
        <div v-if="check.info" class="flex gap-1.5 sm:col-span-2">
          <dt class="text-neutral-500 shrink-0">{{ valueLabel(check.id, 'info') }}:</dt>
          <dd class="font-mono break-all">{{ checkValue(check, 'info') }}</dd>
        </div>
      </dl>

      <ul
        v-if="(check.status === 'fail' || check.status === 'warn') && findingsOf(check).length"
        class="mt-1.5 space-y-0.5 text-xs"
      >
        <li
          v-for="(finding, index) in findingsOf(check)"
          :key="index"
          class="flex flex-wrap gap-x-1.5"
          :title="findingTitle(finding)"
        >
          <span :class="finding.severity === 'fail' ? 'text-danger-600 font-semibold' : 'text-warning-800'">
            {{ findingLabel(finding.code) }}
          </span>
          <span class="font-mono break-all text-neutral-700">{{ finding.object }}</span>
        </li>
        <li v-if="moreFindings(check) > 0" class="text-neutral-500">
          {{ t('diagnostics.findings_more', { n: moreFindings(check) }) }}
        </li>
      </ul>

      <template v-if="check.status === 'fail' || check.status === 'warn'">
        <p v-if="checkText(check.id, 'impact')" class="mt-1.5 text-sm text-neutral-700">
          {{ checkText(check.id, 'impact') }}
        </p>
        <p v-if="checkText(check.id, 'fix')" class="mt-0.5 text-sm text-neutral-600">
          <span class="font-medium">{{ t('diagnostics.fix') }}:</span>
          {{ checkText(check.id, 'fix') }}
          <a
            v-if="check.manual"
            :href="`/manual?ch=${check.manual}`"
            target="_blank"
            rel="noopener"
            class="ml-1 text-primary-600 hover:text-primary-800 hover:underline"
          >{{ t('diagnostics.manual_link') }}</a>
        </p>
      </template>
    </li>
  </ul>
</template>
