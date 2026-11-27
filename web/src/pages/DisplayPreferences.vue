<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import type { DisplayPrefs } from '@/api/preferences'
import { DISPLAY_DEFAULTS, displayPrefsReady, useDisplayPrefs, type ResolvedDisplayPrefs } from '@/composables/useDisplayPrefs'
import { getPagePrefs, patchDisplayPrefs, patchPagePrefs, resetDisplayPrefs } from '@/composables/useUserPrefs'
import { SIDE_PREVIEW_FLAG, SIDE_PREVIEW_PAGE_KEYS } from '@/composables/useSidePreview'

/**
 * Předvolby zobrazení — výchozí podoba sekcí a přepínačů napříč stránkami. Ukládá se
 * per uživatel (`user_preferences` → `display.defaults`), platí na všech zařízeních.
 * Stránky z nich berou jen počáteční stav; ruční změna na stránce předvolbu nemění.
 */
const { t } = useI18n()
const prefs = useDisplayPrefs()
const ready = ref(false)

type BoolKey = 'posting_open' | 'dimensions_open' | 'preview_open' | 'statement_hide_zero' | 'statement_analytics' | 'estimate_open' | 'statement_accrual_spread'

interface ToggleRow { key: BoolKey; label: string; hint: string }
interface ChoiceRow<K extends 'preview_placement' | 'statement_unit'> {
  key: K
  label: string
  hint: string
  options: { value: ResolvedDisplayPrefs[K]; label: string }[]
}

const sections = computed(() => [
  {
    key: 'documents',
    title: t('display_prefs.section_documents'),
    toggles: [
      { key: 'posting_open', label: t('display_prefs.posting_open'), hint: t('display_prefs.posting_open_hint') },
      { key: 'dimensions_open', label: t('display_prefs.dimensions_open'), hint: t('display_prefs.dimensions_open_hint') },
    ] as ToggleRow[],
    choices: [] as ChoiceRow<'preview_placement' | 'statement_unit'>[],
  },
  {
    key: 'preview',
    title: t('display_prefs.section_preview'),
    toggles: [
      { key: 'preview_open', label: t('display_prefs.preview_open'), hint: t('display_prefs.preview_open_hint') },
    ] as ToggleRow[],
    choices: [{
      key: 'preview_placement',
      label: t('display_prefs.preview_placement'),
      hint: t('display_prefs.preview_placement_hint'),
      options: [
        { value: 'side', label: t('display_prefs.preview_placement_side') },
        { value: 'below', label: t('display_prefs.preview_placement_below') },
      ],
    }] as ChoiceRow<'preview_placement' | 'statement_unit'>[],
  },
  {
    key: 'statements',
    title: t('display_prefs.section_statements'),
    toggles: [
      { key: 'statement_hide_zero', label: t('display_prefs.statement_hide_zero'), hint: t('display_prefs.statement_hide_zero_hint') },
      { key: 'statement_analytics', label: t('display_prefs.statement_analytics'), hint: t('display_prefs.statement_analytics_hint') },
      { key: 'statement_accrual_spread', label: t('display_prefs.statement_accrual_spread'), hint: t('display_prefs.statement_accrual_spread_hint') },
      { key: 'estimate_open', label: t('display_prefs.estimate_open'), hint: t('display_prefs.estimate_open_hint') },
    ] as ToggleRow[],
    choices: [{
      key: 'statement_unit',
      label: t('display_prefs.statement_unit'),
      hint: t('display_prefs.statement_unit_hint'),
      options: [
        { value: 'czk', label: t('reports.unit_czk') },
        { value: 'thousands', label: t('reports.unit_thousands') },
      ],
    }] as ChoiceRow<'preview_placement' | 'statement_unit'>[],
  },
])

/**
 * Náhled si detail a editor pamatují zvlášť pro každou agendu. Nová předvolba by se
 * tak projevila jen tam, kde uživatel náhled ještě nikdy nepřepnul — proto se
 * zapamatovaný stav agend při její změně zahodí a předvolba začne platit všude.
 */
function forgetPreviewMemory(kind: 'open' | 'placement'): void {
  for (const pageKey of SIDE_PREVIEW_PAGE_KEYS) {
    const page = getPagePrefs(pageKey).value
    if (kind === 'open' && page.flags && SIDE_PREVIEW_FLAG in page.flags) {
      const { [SIDE_PREVIEW_FLAG]: _drop, ...flags } = page.flags
      patchPagePrefs(pageKey, { flags })
    }
    if (kind === 'placement' && page.options && 'preview_placement' in page.options) {
      const { preview_placement: _drop, ...options } = page.options
      patchPagePrefs(pageKey, { options })
    }
  }
}

function set(patch: DisplayPrefs): void {
  patchDisplayPrefs(patch)
  if ('preview_open' in patch) forgetPreviewMemory('open')
  if ('preview_placement' in patch) forgetPreviewMemory('placement')
}

const customized = computed(() => (Object.keys(DISPLAY_DEFAULTS) as (keyof ResolvedDisplayPrefs)[])
  .some(key => prefs.value[key] !== DISPLAY_DEFAULTS[key]))

async function reset(): Promise<void> {
  await resetDisplayPrefs()
  forgetPreviewMemory('open')
  forgetPreviewMemory('placement')
}

onMounted(async () => {
  await displayPrefsReady()
  ready.value = true
})
</script>

<template>
  <div class="max-w-5xl">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold">{{ t('display_prefs.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('display_prefs.subtitle') }}</p>
      </div>
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="!customized"
        data-test="display-prefs-reset" @click="reset">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
        {{ t('display_prefs.reset') }}
      </button>
    </div>

    <div class="mb-5 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-800">
      {{ t('display_prefs.hint') }}
    </div>

    <div class="space-y-6" :aria-busy="!ready">
      <section v-for="section in sections" :key="section.key" :data-test="`display-prefs-${section.key}`">
        <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-neutral-500">{{ section.title }}</h2>
        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm divide-y divide-neutral-100">
          <div v-for="row in section.toggles" :key="row.key" class="flex items-start justify-between gap-4 px-4 py-3">
            <div class="min-w-0">
              <div class="font-medium text-neutral-800">{{ row.label }}</div>
              <p class="mt-0.5 text-xs text-neutral-500">{{ row.hint }}</p>
            </div>
            <ToggleSwitch class="mt-0.5" :model-value="prefs[row.key]" :label="row.label" :disabled="!ready"
              :data-test="`display-prefs-${row.key}`" @update:model-value="set({ [row.key]: $event } as DisplayPrefs)" />
          </div>
          <div v-for="row in section.choices" :key="row.key" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-4 py-3">
            <div class="min-w-0 flex-1 basis-64">
              <div class="font-medium text-neutral-800">{{ row.label }}</div>
              <p class="mt-0.5 text-xs text-neutral-500">{{ row.hint }}</p>
            </div>
            <div class="flex items-center rounded-md border border-neutral-300 overflow-hidden text-sm" role="radiogroup" :aria-label="row.label"
              :data-test="`display-prefs-${row.key}`">
              <button v-for="(option, index) in row.options" :key="option.value" type="button" role="radio"
                :aria-checked="prefs[row.key] === option.value" :disabled="!ready"
                class="cursor-pointer h-9 px-3 whitespace-nowrap disabled:cursor-not-allowed"
                :class="[index > 0 ? 'border-l border-neutral-300' : '', prefs[row.key] === option.value ? 'bg-primary-600 text-white' : 'hover:bg-neutral-50']"
                @click="set({ [row.key]: option.value } as DisplayPrefs)">
                {{ option.label }}
              </button>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
