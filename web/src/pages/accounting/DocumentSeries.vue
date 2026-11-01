<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { seriesApi, SERIES_DEFAULT_PREFIXES, SERIES_DOUBLE_ENTRY_ONLY, type DocumentSeries, type DocumentSeriesPatch, type SeriesCode } from '@/api/closing'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import { kindSettingLabel } from '@/utils/otherItemKind'

// embedded = vykresleno jako záložka uvnitř ToolsPage.vue (Nástroje); hlavičku dodává obálka.
defineProps<{ embedded?: boolean }>()

const { t } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const toast = useToast()

// Daňová evidence deník nemá — deníkové řady jí server nevrací a UI je nesmí
// dofabrikovat z výchozích prefixů, jinak by nabízela k editaci řadu, kterou
// firma nikdy nevydá.
const isDoubleEntry = computed(() => supplierStore.currentSupplier?.accounting_mode === 'double_entry')
const editableCodes = computed<SeriesCode[]>(() =>
  (Object.keys(SERIES_DEFAULT_PREFIXES) as SeriesCode[])
    .filter(c => isDoubleEntry.value || !SERIES_DOUBLE_ENTRY_ONLY.includes(c)))

const DEFAULT_TEMPLATE = '{PREFIX}-{YYYY}-{CCCC}'

const stored = ref<DocumentSeries[]>([])
const loading = ref(false)
const year = ref(new Date().getFullYear())
const saving = ref(false)
// `DocumentSeriesAction::update` chce jen `accounting` WRITE. Gate na
// `accounting.periods.manage` byl přísnější než API a systémová role „účetní" ho
// má explicitně vyřazený — běžná účetní tak viděla záložku jen ke čtení.
const canWrite = computed(() => auth.canWrite('accounting'))

type SeriesEdit = { prefix: string; number_format: string; next_number: string }
const edits = reactive<Record<string, SeriesEdit>>({})

// Klíč musí nést i vlastníka řady: vlastní řada pokladny nebo druhu ostatní položky
// (register_id > 0) je samostatný řádek vedle společné řady firmy, jinak by se editace
// obou slily do jedné.
const seriesKey = (s: DocumentSeries) => `${s.series_code}-${s.fiscal_year}-${s.register_id ?? 0}`

function fillEdit(s: DocumentSeries) {
  edits[seriesKey(s)] = {
    prefix: s.prefix,
    number_format: s.number_format || '',
    next_number: String(s.next_number),
  }
}

// Řádek řady v DB vzniká lazy až prvním výdejem čísla — uložené řady proto
// doplňujeme o dosud neexistující řady zvoleného roku s vestavěnými hodnotami,
// aby šla řada nastavit dopředu (převzetí číslování z jiného systému).
const series = computed<DocumentSeries[]>(() => {
  const rows = [...stored.value]
  const have = new Set(rows.map(seriesKey))
  for (const code of editableCodes.value) {
    const key = `${code}-${year.value}-0`
    if (have.has(key)) continue
    rows.push({ series_code: code, fiscal_year: year.value, register_id: 0, prefix: SERIES_DEFAULT_PREFIXES[code], number_format: null, next_number: 1 })
  }
  return rows.sort((a, b) =>
    b.fiscal_year - a.fiscal_year
    || a.series_code.localeCompare(b.series_code)
    || (a.register_id ?? 0) - (b.register_id ?? 0))
})

/** Řada zatím nevydala číslo — uloží se až se změnou (řádek vznikne lazy na serveru). */
const isNew = (s: DocumentSeries) => s.id === undefined

/** Komu patří vlastní řada: pokladně, nebo druhu ostatní položky (vestavěnému i vlastnímu). */
function registerLabel(s: DocumentSeries): string {
  if (!s.series_code.startsWith('other_')) return s.register_name || t('cash.register')
  if (!s.kind_name && !s.kind_builtin_code) return t('accounting.closing.series.other_item_kind')
  return kindSettingLabel(t, {
    side: s.series_code === 'other_receivable' ? 'receivable' : 'payable',
    builtin_code: s.kind_builtin_code ?? null,
    name: s.kind_name ?? null,
  })
}

watch(series, rows => {
  for (const s of rows) if (!edits[seriesKey(s)]) fillEdit(s)
}, { immediate: true })

async function load() {
  loading.value = true
  try {
    stored.value = await seriesApi.list()
    for (const s of stored.value) fillEdit(s)
  } catch (e: any) {
    stored.value = []
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

/** Náhled čísla dle rozeditované šablony — stejná pravidla jako DocumentSeriesService::format. */
function preview(s: DocumentSeries): string {
  const e = edits[seriesKey(s)]
  if (!e) return ''
  const tpl = (e.number_format.trim() || DEFAULT_TEMPLATE).toUpperCase()
  const n = Math.max(1, Number(e.next_number) || 1)
  return tpl
    .replace(/\{PREFIX\}/g, e.prefix.trim().toUpperCase())
    .replace(/\{YYYY\}/g, String(s.fiscal_year))
    .replace(/\{YY\}/g, String(s.fiscal_year).slice(-2))
    .replace(/\{(C+)\}/g, (_m, c: string) => String(n).padStart(c.length, '0'))
}

function isDirty(s: DocumentSeries): boolean {
  const e = edits[seriesKey(s)]
  if (!e) return false
  return e.prefix.trim().toUpperCase() !== s.prefix
    || e.number_format.trim().toUpperCase() !== (s.number_format || '')
    || Number(e.next_number) !== s.next_number
}


const dirtyRows = computed(() => series.value.filter(isDirty))

/** Vrátí chybovou hlášku první neplatné hodnoty, jinak prázdný řetězec. */
function validationError(s: DocumentSeries): string {
  const e = edits[seriesKey(s)]
  if (!e) return ''
  if (!/^[A-Z0-9]{1,10}$/.test(e.prefix.trim().toUpperCase())) return t('accounting.closing.series.prefix_invalid')
  const format = e.number_format.trim().toUpperCase()
  if (format !== '' && !/\{C+\}/.test(format)) return t('accounting.closing.series.format_invalid')
  const next = Number(e.next_number)
  if (!Number.isInteger(next) || next < 1 || next > 999999999) return t('accounting.closing.series.next_number_invalid')
  return ''
}

// Jedno společné Uložit pro celou tabulku — řady se ukládají po jedné, protože
// API bere jednu řadu na volání; odešlou se jen skutečně změněné řádky.
async function saveAll() {
  const rows = dirtyRows.value
  if (!rows.length) return
  for (const s of rows) {
    const err = validationError(s)
    if (err) { toast.warning(err); return }
  }
  saving.value = true
  try {
    for (const s of rows) {
      const e = edits[seriesKey(s)]
      const format = e.number_format.trim().toUpperCase()
      // Posílají se JEN skutečně změněná pole. Čítač je živá hodnota — mezi otevřením
      // stránky a uložením mohl vydat číslo jiný doklad, a přeposláním načtené hodnoty
      // by se řada vrátila zpět a začala vydávat už použitá čísla.
      const patch: DocumentSeriesPatch = { register_id: s.register_id ?? 0 }
      const prefix = e.prefix.trim().toUpperCase()
      if (prefix !== s.prefix) patch.prefix = prefix
      if (format !== (s.number_format || '')) patch.number_format = format === '' ? null : format
      if (Number(e.next_number) !== s.next_number) patch.next_number = Number(e.next_number)
      stored.value = await seriesApi.update(s.series_code, s.fiscal_year, patch)
    }
    for (const row of stored.value) fillEdit(row)
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div v-if="!embedded" class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('accounting.closing.series.title') }}</h1>
    </div>

    <p class="text-sm text-neutral-500 mb-1">{{ t('accounting.closing.series.hint') }}</p>
    <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.closing.series.format_hint') }}</p>

    <div class="flex items-center gap-2 mb-4">
      <label class="text-sm text-neutral-600">{{ t('common.year') }}</label>
      <input v-model.number="year" type="number" min="2000" max="2200" step="1"
        class="h-9 w-24 px-2 border border-neutral-300 rounded-md text-sm text-right" />
    </div>

    <div v-if="loading" class="text-sm text-neutral-500 py-4">{{ t('common.loading') }}</div>
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.closing.series.code') }}</th>
            <th class="px-3 py-2 text-left font-medium whitespace-nowrap w-16">{{ t('common.year') }}</th>
            <th class="px-3 py-2 text-left font-medium whitespace-nowrap w-24">{{ t('accounting.closing.series.prefix') }}</th>
            <th class="px-3 py-2 text-left font-medium whitespace-nowrap w-56">{{ t('accounting.closing.series.number_format') }}</th>
            <th class="px-3 py-2 text-left font-medium whitespace-nowrap w-24">{{ t('accounting.closing.series.next_number') }}</th>
            <th class="px-3 py-2 text-left font-medium whitespace-nowrap w-44">{{ t('accounting.closing.series.preview') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="s in series" :key="seriesKey(s)">
            <td class="px-3 py-2">
              {{ t(`accounting.closing.series.codes.${s.series_code}`) }}
              <span v-if="(s.register_id ?? 0) > 0" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 whitespace-nowrap">
                {{ registerLabel(s) }}
              </span>
              <span v-if="isNew(s)" class="ml-2 text-xs text-neutral-400 whitespace-nowrap">{{ t('accounting.closing.series.not_issued') }}</span>
            </td>
            <td class="px-3 py-2 whitespace-nowrap">{{ s.fiscal_year }}</td>
            <td class="px-3 py-2">
              <input v-model="edits[seriesKey(s)].prefix" type="text" maxlength="10" :disabled="!canWrite"
                class="w-full h-8 px-2 border border-neutral-300 rounded-md text-sm font-mono uppercase disabled:bg-neutral-50" />
            </td>
            <td class="px-3 py-2">
              <input v-model="edits[seriesKey(s)].number_format" type="text" maxlength="40" :disabled="!canWrite"
                :placeholder="DEFAULT_TEMPLATE"
                class="w-full h-8 px-2 border border-neutral-300 rounded-md text-xs font-mono uppercase placeholder:text-neutral-400 disabled:bg-neutral-50" />
            </td>
            <td class="px-3 py-2">
              <input v-model="edits[seriesKey(s)].next_number" type="number" min="1" step="1" :disabled="!canWrite"
                class="w-full h-8 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right disabled:bg-neutral-50" />
            </td>
            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap" :class="isNew(s) ? 'text-neutral-400' : 'text-neutral-500'">{{ preview(s) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="canWrite" class="sticky bottom-[var(--app-footer-height,0px)] mt-4 py-3 bg-surface/95 border-t border-neutral-200 flex justify-end">
      <button @click="saveAll" :disabled="saving || !dirtyRows.length" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ saving ? t('common.saving') : t('common.save') }}
      </button>
    </div>
  </div>
</template>
