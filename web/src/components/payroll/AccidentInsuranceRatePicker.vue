<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollAccidentInsuranceRateSchedule } from '@/api/payroll'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

/**
 * Sazebník přílohy č. 2 vyhlášky č. 125/1993 Sb. jako PODKLAD k výběru sazby
 * zákonného pojištění odpovědnosti.
 *
 * Komponenta sazbu jen NABÍZÍ — kliknutí ji vloží do pole formuláře, kde ji
 * lze dál přepsat. Uloží se vždy to, co potvrdí účetní; aplikace sazbu netvrdí.
 * Důvod je právní a je vidět i v UI: příloha člení činnosti podle OKEČ, která
 * byla zrušena k 31. 12. 2007, a proti CZ-NACE není převod jednoznačný — stejné
 * číslo znamená v obou klasifikacích jinou činnost.
 *
 * Návrh podle firemního CZ-NACE proto přichází ze serveru spárovaný podle
 * NÁZVU činnosti, ne podle čísla, a je označený jako nezávazný.
 */

const props = defineProps<{
  canWrite: boolean
  /** Sazba právě rozepsaná ve formuláři — řádek se zvýrazní. */
  currentRate: string
}>()

const emit = defineEmits<{ select: [rate: string] }>()

const { t } = useI18n()

const loading = ref(true)
/** Dotaz selhal (síť / 5xx). NENÍ totéž co „sazebník je prázdný". */
const failed = ref(false)
const data = ref<PayrollAccidentInsuranceRateSchedule | null>(null)
const filter = ref('')
const expanded = ref(false)

type Row = {
  key: string
  rate: string
  code: string | null
  label: string
  kind: 'classified' | 'hazard' | 'residual'
}

function fold(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

const rows = computed<Row[]>(() => {
  const groups = data.value?.schedule.groups ?? []
  const out: Row[] = []
  for (const group of groups) {
    if (group.kind === 'classified') {
      for (const activity of group.activities) {
        out.push({
          key: `${group.key}:${activity.okec_code}`,
          rate: group.rate_per_mille,
          code: activity.okec_code,
          label: activity.label,
          kind: group.kind,
        })
      }
    } else {
      out.push({
        key: group.key,
        rate: group.rate_per_mille,
        code: null,
        label: group.label ?? '',
        kind: group.kind,
      })
    }
  }
  return out
})

const visibleRows = computed<Row[]>(() => {
  const needle = fold(filter.value.trim())
  if (needle === '') return rows.value
  return rows.value.filter(row =>
    fold(row.label).includes(needle) || (row.code ?? '').startsWith(needle),
  )
})

const legal = computed(() => data.value?.schedule.legal ?? null)
const naceLabel = computed(() => {
  const nace = data.value?.nace
  if (!nace) return null
  return nace.name ? `${nace.display} — ${nace.name}` : nace.display
})

function sameRate(a: string, b: string): boolean {
  const normalize = (value: string) => Number(value.replace(',', '.'))
  const left = normalize(a)
  const right = normalize(b)
  return Number.isFinite(left) && Number.isFinite(right) && left === right
}

function use(rate: string): void {
  if (!props.canWrite) return
  emit('select', rate)
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    data.value = await payrollApi.accidentInsuranceRateSchedule()
  } catch {
    data.value = null
    failed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="rounded-lg border border-neutral-200 bg-neutral-50/60">
    <button
      type="button"
      class="flex w-full cursor-pointer items-center justify-between gap-3 px-4 py-3 text-left"
      :aria-expanded="expanded"
      @click="expanded = !expanded"
    >
      <span>
        <span class="block text-sm font-medium text-neutral-900">
          {{ t('payroll.employer.accident_insurance.schedule.title') }}
        </span>
        <span class="mt-0.5 block text-xs text-neutral-500">
          {{ t('payroll.employer.accident_insurance.schedule.subtitle') }}
        </span>
      </span>
      <svg
        class="h-4 w-4 shrink-0 text-neutral-500 transition-transform"
        :class="expanded ? 'rotate-180' : ''"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      ><path d="M19 9l-7 7-7-7" /></svg>
    </button>

    <div v-if="expanded" class="border-t border-neutral-200 px-4 py-4">
      <div v-if="loading" class="h-24 animate-pulse rounded-lg bg-neutral-100" />

      <div
        v-else-if="failed"
        class="rounded-md border border-warning-400 bg-warning-50 px-3 py-2"
        role="alert"
        data-testid="accident-rate-schedule-failed"
      >
        <p class="text-xs text-warning-700">
          {{ t('payroll.employer.accident_insurance.schedule.load_failed') }}
        </p>
        <button type="button" :class="btnOutlineSm('neutral')" class="mt-2" @click="load">
          {{ t('payroll.employer.accident_insurance.schedule.retry') }}
        </button>
      </div>

      <template v-else-if="data">
        <!-- Nejdřív varování, teprve pak data. Kdo si sazebník otevře, musí
             vědět, proč se čísla kódů nepárují, ještě než na nějaké klikne. -->
        <div
          class="rounded-md border border-warning-400 bg-warning-50 px-3 py-2"
          data-testid="accident-rate-okec-warning"
        >
          <p class="text-xs leading-relaxed text-warning-700">
            {{ t('payroll.employer.accident_insurance.schedule.okec_warning') }}
          </p>
        </div>

        <div class="mt-4" data-testid="accident-rate-nace-hint">
          <p v-if="naceLabel" class="text-xs text-neutral-600">
            {{ t('payroll.employer.accident_insurance.schedule.nace_is', { nace: naceLabel }) }}
          </p>
          <p v-else class="text-xs text-neutral-600">
            {{ t('payroll.employer.accident_insurance.schedule.nace_missing') }}
          </p>

          <ul v-if="data.suggestions.length > 0" class="mt-2 space-y-1">
            <li
              v-for="suggestion in data.suggestions"
              :key="`${suggestion.group_key}:${suggestion.okec_code}`"
              class="flex flex-wrap items-center gap-2 text-sm"
            >
              <span class="font-medium text-neutral-900">{{ suggestion.rate_per_mille }} ‰</span>
              <span class="font-mono text-xs text-neutral-500">{{ suggestion.okec_code }}</span>
              <span class="text-neutral-700">{{ suggestion.label }}</span>
              <button
                v-if="canWrite"
                type="button"
                :class="btnOutlineSm('primary')"
                class="whitespace-nowrap"
                @click="use(suggestion.rate_per_mille)"
              >
                {{ t('payroll.employer.accident_insurance.schedule.use', { rate: suggestion.rate_per_mille }) }}
              </button>
            </li>
          </ul>
          <p v-else-if="naceLabel" class="mt-1 text-xs text-neutral-500">
            {{ t('payroll.employer.accident_insurance.schedule.no_suggestion') }}
          </p>
          <p class="mt-2 text-xs text-neutral-500">
            {{ t('payroll.employer.accident_insurance.schedule.suggestion_disclaimer') }}
          </p>
        </div>

        <label class="mt-4 block">
          <span class="mb-1 block text-xs font-medium text-neutral-700">
            {{ t('payroll.employer.accident_insurance.schedule.filter') }}
          </span>
          <input
            v-model="filter"
            type="search"
            autocomplete="off"
            :placeholder="t('payroll.employer.accident_insurance.schedule.filter_placeholder')"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
          >
        </label>

        <div class="mt-3 max-h-96 overflow-auto rounded-md border border-neutral-200 bg-surface">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead class="sticky top-0 bg-neutral-50">
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-3 py-2">{{ t('payroll.employer.accident_insurance.rate_per_mille') }}</th>
                <th class="px-3 py-2">{{ t('payroll.employer.accident_insurance.schedule.okec_code') }}</th>
                <th class="px-3 py-2">{{ t('payroll.employer.accident_insurance.schedule.activity') }}</th>
                <th class="px-3 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr
                v-for="row in visibleRows"
                :key="row.key"
                :class="sameRate(row.rate, currentRate) ? 'bg-payroll-50' : ''"
              >
                <td class="whitespace-nowrap px-3 py-2 font-medium">{{ row.rate }} ‰</td>
                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-neutral-500">
                  {{ row.code ?? '—' }}
                </td>
                <td class="px-3 py-2 text-neutral-700">{{ row.label }}</td>
                <td class="px-3 py-2 text-right">
                  <button
                    v-if="canWrite"
                    type="button"
                    :class="btnOutlineSm('primary')"
                    class="whitespace-nowrap"
                    @click="use(row.rate)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                    {{ t('payroll.employer.accident_insurance.schedule.use', { rate: row.rate }) }}
                  </button>
                </td>
              </tr>
              <tr v-if="visibleRows.length === 0">
                <td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-500">
                  {{ t('payroll.employer.accident_insurance.schedule.no_results') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-if="legal" class="mt-3 text-xs leading-relaxed text-neutral-500">
          {{ t('payroll.employer.accident_insurance.schedule.provenance', {
            annex: legal.annex,
            decree: legal.decree,
            rates_source: legal.rates_source,
            rates_effective_from: legal.rates_effective_from,
            minimum: legal.minimum_quarterly_premium_czk,
          }) }}
          <a
            :href="legal.source_url"
            target="_blank"
            rel="noopener noreferrer"
            class="text-primary-700 underline"
          >{{ t('payroll.employer.accident_insurance.schedule.source_link') }}</a>
        </p>
      </template>
    </div>
  </div>
</template>
