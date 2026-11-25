<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import { apiErrorMessage } from '@/api/errors'
import { nonprofitTaxJ6Api } from '@/api/nonprofitTaxJ6'
import type { J6SourceFindingFilter, J6SourceFindingGroup, J6SourceFindingLine, J6SourceFindingSummary } from '@/api/nonprofitTaxJ6Types'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { journalEntryLink, journalSourceLink } from '@/utils/journalSourceLink'

/**
 * Zjištění podkladů daňového posouzení za období mimo koncept. Souhrn se počítá na
 * vyžádání jedním průchodem podkladů, řádky se čtou po stránkách přes kurzor serveru.
 * Proklik vede na prvotní doklad a vždy i na zápis v deníku (ne každý doklad umí upravit členění
 * řádku, např. rozpuštění časového rozlišení); řádky ke společné alokaci mají odkaz na společné alokace.
 */
const props = defineProps<{ supplierId: number; periodId: number; currentHash?: string }>()
const JOINT_CODES = ['joint_allocation_required', 'joint_dimensions_changed']
const { t, locale } = useI18n()

const summary = ref<J6SourceFindingSummary | null>(null)
const filter = ref<J6SourceFindingFilter | null>(null)
const lines = ref<J6SourceFindingLine[]>([])
const after = ref<string | null>(null)
const cursor = ref<string | null>(null)
const back = ref<(string | null)[]>([])
const loading = ref(false)
const error = ref('')
let ticket = 0

const filterLabel = computed(() => {
  const value = filter.value
  if (!value) return ''
  return [value.account, value.code ? findingLabel(value.code) : null].filter(Boolean).join(' · ')
})

function money(cents: number): string {
  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(cents / 100)
}
function date(value: string): string {
  return new Intl.DateTimeFormat(locale.value).format(new Date(value + 'T00:00:00'))
}
function findingLabel(code: string): string {
  return t('nonprofitTax.findings.' + code)
}
function accountLabel(group: J6SourceFindingGroup): string {
  return group.account_name ? `${group.account_code} ${group.account_name}` : group.account_code
}
function needsJointAllocation(line: J6SourceFindingLine): boolean {
  return line.codes.some(code => JOINT_CODES.includes(code))
}
/** Souhrn spočítaný nad jinými podklady, než jaké ukazuje přehled stránky (deník se mezitím změnil). */
const changedSinceOverview = computed(() => Boolean(summary.value && props.currentHash && summary.value.source.input_hash !== props.currentHash))
function lineLink(line: J6SourceFindingLine): { to: RouteLocationRaw; document: boolean } {
  const to = journalSourceLink(line)
  return to ? { to, document: true } : { to: journalEntryLink(line.entry_id), document: false }
}

async function run<T>(work: () => Promise<T>, apply: (value: T) => void) {
  const current = ++ticket
  loading.value = true
  error.value = ''
  try {
    const value = await work()
    if (current === ticket) apply(value)
  } catch (value) {
    if (current === ticket) error.value = apiErrorMessage(value, t('nonprofitTaxJ6.sourceFindings.error'))
  } finally {
    if (current === ticket) loading.value = false
  }
}
async function loadSummary() {
  // Při přepočtu se staré počty nezobrazují, ani když přepočet selže.
  summary.value = null
  filter.value = null
  lines.value = []
  await run(() => nonprofitTaxJ6Api.sourceFindings(props.supplierId, props.periodId), value => { summary.value = value })
}
/** Zásobník předchozích stránek se mění až po úspěšném načtení, aby chyba nepřeskočila stránku. */
async function loadLines(position: string | null, history?: (string | null)[]) {
  const selected = filter.value
  if (!selected) return
  await run(() => nonprofitTaxJ6Api.sourceFindingLines(props.supplierId, props.periodId, selected, position), page => {
    lines.value = page.rows
    after.value = page.after
    cursor.value = position
    if (history) back.value = history
  })
}
async function showLines(group: J6SourceFindingGroup) {
  // Kurzor i historie patří předchozí skupině; server by je pro nový filtr odmítl.
  filter.value = { account: group.account_code, code: group.code }
  lines.value = []
  after.value = null
  cursor.value = null
  back.value = []
  await loadLines(null, [])
}
async function next() {
  if (!after.value) return
  await loadLines(after.value, [...back.value, cursor.value])
}
async function previous() {
  if (!back.value.length) return
  await loadLines(back.value[back.value.length - 1]!, back.value.slice(0, -1))
}

watch(() => [props.supplierId, props.periodId], () => {
  ticket++
  summary.value = null
  filter.value = null
  lines.value = []
  error.value = ''
  loading.value = false
})
</script>

<template>
  <div class="space-y-3 border-t border-neutral-200 pt-4" data-test="source-findings">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 class="font-semibold">{{ t('nonprofitTaxJ6.sourceFindings.title') }}</h3>
        <p class="max-w-3xl text-sm text-neutral-600">{{ t('nonprofitTaxJ6.sourceFindings.help') }}</p>
      </div>
      <button type="button" data-test="source-findings-load" class="whitespace-nowrap" :class="btnOutline('primary')" :disabled="loading" @click="loadSummary">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="summary ? ICONS.cycle : ICONS.search" /></svg>
        {{ summary ? t('nonprofitTaxJ6.sourceFindings.refresh') : t('nonprofitTaxJ6.sourceFindings.show') }}
      </button>
    </div>
    <p v-if="error" role="alert" class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700">{{ error }}</p>
    <p v-if="loading" class="text-sm text-neutral-600">{{ t('nonprofitTaxJ6.loading') }}</p>

    <template v-if="summary">
      <p v-if="changedSinceOverview" data-test="source-findings-changed" class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800">{{ t('nonprofitTaxJ6.sourceFindings.changed') }}</p>
      <p class="text-sm" data-test="source-findings-total">{{ t('nonprofitTaxJ6.sourceFindings.total', { lines: summary.line_count, rows: summary.source.row_count }) }}</p>
      <p v-if="!summary.groups.length" class="rounded-lg border border-success-300 bg-success-50 p-3 text-sm text-success-700">{{ t('nonprofitTaxJ6.sourceFindings.none') }}</p>
      <!-- Úzká obrazovka: karty jako výpis podkladů konceptu; od sm tabulka jako Úplnost dokladů. -->
      <div v-if="summary.groups.length" class="space-y-2 sm:hidden">
        <article v-for="group in summary.groups" :key="'card:' + group.account_code + ':' + group.code" data-test="source-finding-group-card" class="rounded-lg border border-neutral-200 bg-surface p-3 text-sm">
          <div class="font-medium">{{ accountLabel(group) }}</div>
          <div class="mt-1 text-neutral-700">{{ findingLabel(group.code) }}</div>
          <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <span class="text-neutral-600">{{ t('nonprofitTaxJ6.sourceFindings.lines') }}: {{ group.line_count }}</span>
            <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="loading" @click="showLines(group)">
              <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.eye" /></svg>{{ t('nonprofitTaxJ6.sourceFindings.showLines') }}
            </button>
          </div>
        </article>
      </div>
      <div v-if="summary.groups.length" class="hidden sm:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('nonprofitTaxJ6.sourceFindings.account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('nonprofitTaxJ6.finding') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('nonprofitTaxJ6.sourceFindings.lines') }}</th>
              <th class="px-3 py-2 text-right font-medium"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="group in summary.groups" :key="group.account_code + ':' + group.code" data-test="source-finding-group" class="hover:bg-neutral-50">
              <td class="px-3 py-2">{{ accountLabel(group) }}</td>
              <td class="px-3 py-2">{{ findingLabel(group.code) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ group.line_count }}</td>
              <td class="px-3 py-2 text-right">
                <button type="button" data-test="source-finding-show" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="loading" @click="showLines(group)">
                  <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.eye" /></svg>{{ t('nonprofitTaxJ6.sourceFindings.showLines') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <template v-if="filter">
      <h4 class="text-sm font-semibold" data-test="source-finding-filter">{{ t('nonprofitTaxJ6.sourceFindings.linesTitle', { filter: filterLabel }) }}</h4>
      <div class="space-y-2 sm:hidden">
        <article v-for="line in lines" :key="'card:' + line.line_id" data-test="source-finding-line-card" class="rounded-lg border border-neutral-200 bg-surface p-3 text-sm">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <strong>{{ line.document_no || '—' }}</strong>
            <span class="font-mono whitespace-nowrap">{{ money(line.amount_cents) }}</span>
          </div>
          <p class="text-xs text-neutral-600">{{ date(line.date) }} · {{ line.account_code }}<template v-if="line.description"> · {{ line.description }}</template></p>
          <div v-for="code in line.codes" :key="code" class="mt-1 text-xs text-warning-700">{{ findingLabel(code) }}</div>
          <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
            <RouterLink :to="lineLink(line).to" data-test="source-finding-card-link" class="text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
              {{ lineLink(line).document ? t('nonprofitTaxJ6.sourceFindings.openDocument') : t('nonprofitTaxJ6.sourceFindings.openEntry') }} →
            </RouterLink>
            <RouterLink v-if="lineLink(line).document" :to="journalEntryLink(line.entry_id)" data-test="source-finding-card-entry" class="text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
              {{ t('nonprofitTaxJ6.sourceFindings.openEntry') }} →
            </RouterLink>
            <RouterLink v-if="needsJointAllocation(line)" :to="{ name: 'accounting-joint-allocations' }" data-test="source-finding-card-joint" class="text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
              {{ t('nonprofitTaxJ6.sourceFindings.openJointAllocations') }} →
            </RouterLink>
          </div>
        </article>
        <p v-if="!lines.length && !loading" class="text-sm text-neutral-600">{{ t(after ? 'nonprofitTaxJ6.sourceFindings.continueSearch' : 'nonprofitTaxJ6.empty') }}</p>
      </div>
      <div class="hidden sm:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('nonprofitTaxJ6.sourceFindings.date') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('nonprofitTaxJ6.sourceFindings.document') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('nonprofitTaxJ6.sourceFindings.account') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('nonprofitTaxJ6.sourceFindings.amount') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('nonprofitTaxJ6.finding') }}</th>
              <th class="px-3 py-2 text-right font-medium"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="line in lines" :key="line.line_id" data-test="source-finding-line" class="hover:bg-neutral-50">
              <td class="px-3 py-2 whitespace-nowrap">{{ date(line.date) }}</td>
              <td class="px-3 py-2">
                <div>{{ line.document_no || '—' }}</div>
                <div v-if="line.description" class="text-xs text-neutral-400">{{ line.description }}</div>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ line.account_code }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ money(line.amount_cents) }}</td>
              <td class="px-3 py-2">
                <div v-for="code in line.codes" :key="code" class="text-xs text-warning-700">{{ findingLabel(code) }}</div>
              </td>
              <td class="px-3 py-2 text-right">
                <RouterLink :to="lineLink(line).to" data-test="source-finding-link" class="text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
                  {{ lineLink(line).document ? t('nonprofitTaxJ6.sourceFindings.openDocument') : t('nonprofitTaxJ6.sourceFindings.openEntry') }} →
                </RouterLink>
                <RouterLink v-if="lineLink(line).document" :to="journalEntryLink(line.entry_id)" data-test="source-finding-entry" class="mt-1 block text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
                  {{ t('nonprofitTaxJ6.sourceFindings.openEntry') }} →
                </RouterLink>
                <RouterLink v-if="needsJointAllocation(line)" :to="{ name: 'accounting-joint-allocations' }" data-test="source-finding-joint" class="mt-1 block text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
                  {{ t('nonprofitTaxJ6.sourceFindings.openJointAllocations') }} →
                </RouterLink>
              </td>
            </tr>
            <tr v-if="!lines.length && !loading">
              <td class="px-3 py-3 text-sm text-neutral-600" colspan="6">{{ t(after ? 'nonprofitTaxJ6.sourceFindings.continueSearch' : 'nonprofitTaxJ6.empty') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="loading || !back.length" @click="previous"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button>
        <button type="button" data-test="source-finding-next" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="loading || !after" @click="next"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button>
      </div>
    </template>
  </div>
</template>
