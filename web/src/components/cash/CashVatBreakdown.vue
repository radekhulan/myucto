<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnIconSm, btnOutlineSm } from '@/components/ui/buttonStyles'
import type { CashVatLine, CashVatDeduction, CashTaxTreatment } from '@/api/cash'

/**
 * DPH rozpad pokladního dokladu (§6.5). Sazby z API (jen > 0, per rok — A4/O5c).
 * Zadání shora (§37/2) nebo zdola (§37/1); komponenta VŽDY dorovná poslední řádek
 * tak, aby Σ(base+vat) == total PŘESNĚ (O6 — BE mismatch tvrdě odmítá).
 */
const props = defineProps<{
  modelValue: CashVatLine[]
  total: number
  rates: number[]
  /**
   * M-7: rozsah odpočtu (§ 75/76) a daňová uznatelnost (§ 24/25) dávají smysl jen
   * na vstupu. Bez UI je editor neposílal a server je při každém uložení resetoval
   * na `full` / 100 / `deductible`.
   */
  deduction?: boolean
}>()
const emit = defineEmits<{
  (e: 'update:modelValue', v: CashVatLine[]): void
  /** Sedí SUROVÝ rozpad na celkovou částku? Rodič na tom drží blokaci uložení. */
  (e: 'update:matches', v: boolean): void
}>()

const { t } = useI18n()

type Mode = 'top' | 'bottom'
const mode = ref<Mode>('top')

interface Row {
  rate: number; gross: number | null; base: number | null; vat: number | null
  deduction: CashVatDeduction; deductionPercent: number; treatment: CashTaxTreatment
  // Pořízení dlouhodobého majetku za hotové → doplňující ř. 47 přiznání (migrace 1784).
  fixedAsset: boolean
}

const VAT_DEDUCTIONS: CashVatDeduction[] = ['full', 'proportional', 'reduced', 'none']
const TAX_TREATMENTS: CashTaxTreatment[] = ['deductible', 'non_deductible', 'not_expense']

function round2(n: number): number { return Math.round(n * 100) / 100 }
function cents(n: number): number { return Math.round((n || 0) * 100) }
function num(v: number | null): number { return Number(v) || 0 }

const defaultRate = computed(() => props.rates[0] ?? 21)

function newRow(): Row {
  return {
    rate: defaultRate.value, gross: null, base: null, vat: null,
    deduction: 'full', deductionPercent: 100, treatment: 'deductible',
    fixedAsset: false,
  }
}

const rows = ref<Row[]>([])

// Inicializace z modelValue (editace draftu) nebo jeden prázdný řádek.
function seedFromModel(): void {
  if (props.modelValue.length > 0) {
    rows.value = props.modelValue.map(l => ({
      rate: l.vat_rate,
      gross: round2(l.base_amount + l.vat_amount),
      base: l.base_amount,
      vat: l.vat_amount,
      deduction: l.vat_deduction ?? 'full',
      deductionPercent: l.vat_deduction_percent ?? 100,
      treatment: l.tax_treatment ?? 'deductible',
      fixedAsset: l.is_fixed_asset ?? false,
    }))
  } else {
    rows.value = [newRow()]
  }
}
seedFromModel()

/**
 * Přepočet řádku dle režimu z primárního vstupu (gross v top, base v bottom).
 *
 * Výpočet shora jde podle § 37 odst. 2 ZDPH: daň = cena včetně daně × sazba/(100+sazba),
 * základ je zbytek. NE naopak (základ = cena/(1+sazba/100), daň dopočítat) — to není
 * zákonný vzorec a rozcházel se s `InvoiceMath::compute()`, kterým se počítají faktury.
 * Rozdíl je haléřový, ale reálný: při 12 % u 4 547 z 200 000 částek do 2 000 Kč
 * (např. 1,26 Kč → 1,12/0,14 vs 1,13/0,13); při 21 % nikdy. Pokladna je přitom jediné
 * místo, kde je hodnota z frontendu autoritativní — `CashDocumentService::validateVatLines()`
 * ověřuje jen, že součet sedí na celkovou částku, koeficient nepřepočítává.
 */
function recompute(row: Row): void {
  const r = row.rate
  if (mode.value === 'top') {
    const g = num(row.gross)
    row.vat = r > 0 ? round2((g * r) / (100 + r)) : 0
    row.base = round2(g - num(row.vat))
  } else {
    const b = num(row.base)
    row.vat = round2(b * r / 100)
    row.gross = round2(b + num(row.vat))
  }
}

function onPrimaryInput(row: Row): void { recompute(row) }
function onRateChange(row: Row): void { recompute(row) }

function addRow(): void { rows.value.push(newRow()) }
function removeRow(i: number): void { if (rows.value.length > 1) rows.value.splice(i, 1) }

function switchMode(m: Mode): void {
  if (mode.value === m) return
  mode.value = m
  for (const row of rows.value) recompute(row)
}

// Jediný zdroj pravdy pro rodiče: rozpad s dorovnaným posledním řádkem (Σ == total).
const emitted = computed<CashVatLine[]>(() => {
  const rs = rows.value.filter(r => r.rate > 0)
  if (rs.length === 0) return []
  const lines: CashVatLine[] = rs.map(r => ({
    vat_rate: r.rate,
    base_amount: round2(num(r.base)),
    vat_amount: round2(num(r.vat)),
    // Klasifikace jede s řádkem i tehdy, když se UI nezobrazuje — jinak by ji
    // uložení draftu z pohledu bez `deduction` zahodilo na serverové defaulty.
    vat_deduction: r.deduction,
    vat_deduction_percent: r.deduction === 'proportional' ? round2(r.deductionPercent) : 100,
    tax_treatment: r.treatment,
    // Ř. 47 se týká jen pořízení; u příjmového dokladu se příznak nenabízí a server
    // ho stejně zahodí (`CashDocumentService::normalize()`).
    is_fixed_asset: props.deduction ? r.fixedAsset : false,
  }))
  const totalC = cents(props.total)
  const sumC = lines.reduce((s, l) => s + cents(l.base_amount) + cents(l.vat_amount), 0)
  const residual = totalC - sumC
  if (residual !== 0) {
    const last = lines[lines.length - 1]
    last.vat_amount = round2(last.vat_amount + residual / 100)
  }
  return lines
})

watch(emitted, v => emit('update:modelValue', v), { deep: true, immediate: true })

// Jeden řádek + top režim → primární vstup navázán na celkovou částku dokladu.
watch(() => props.total, (tot) => {
  if (rows.value.length === 1 && mode.value === 'top') {
    rows.value[0].gross = tot > 0 ? round2(tot) : null
    recompute(rows.value[0])
  }
}, { immediate: true })

const sumBase = computed(() => emitted.value.reduce((s, l) => s + l.base_amount, 0))
const sumVat = computed(() => emitted.value.reduce((s, l) => s + l.vat_amount, 0))
const sumTotal = computed(() => round2(sumBase.value + sumVat.value))
// Badge srovnává SUROVÉ řádky (před dorovnáním) — emitted sedí vždy, takže by
// indikátor jinak nikdy nevaroval. Tolerance 1 haléř na zaokrouhlovací šum.
const rawSumC = computed(() =>
  rows.value.filter(r => r.rate > 0).reduce((s, r) => s + cents(num(r.base)) + cents(num(r.vat)), 0),
)
const matches = computed(() => Math.abs(rawSumC.value - cents(props.total)) <= 1)
// Rozdíl, o který se poslední řádek dorovnává. Dokud byl jen barevný badge, uživatel
// viděl číslo, které si komponenta domyslela (celý zbytek spadl do DPH posledního
// řádku), a nedozvěděl se o tom — proto ho ukazujeme i slovy.
const residual = computed(() => round2((cents(props.total) - rawSumC.value) / 100))
watch(matches, v => emit('update:matches', v), { immediate: true })
</script>

<template>
  <div class="border border-neutral-200 rounded-md p-3 space-y-3 bg-neutral-50/50">
    <div class="flex items-center justify-between gap-2">
      <span class="text-sm font-medium text-neutral-700">{{ t('cash.form.vat_mode_vat') }}</span>
      <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden text-xs">
        <button type="button" @click="switchMode('top')"
          class="cursor-pointer px-2.5 h-7"
          :class="mode === 'top' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-100'">
          {{ t('cash.form.entry_from_top') }}
        </button>
        <button type="button" @click="switchMode('bottom')"
          class="cursor-pointer px-2.5 h-7 border-l border-neutral-300"
          :class="mode === 'bottom' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-100'">
          {{ t('cash.form.entry_from_bottom') }}
        </button>
      </div>
    </div>

    <div class="space-y-2">
      <div v-for="(row, i) in rows" :key="i" class="space-y-1.5">
      <div class="grid grid-cols-12 gap-2 items-end">
        <div class="col-span-3">
          <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('cash.form.vat_rate') }}</label>
          <select v-model.number="row.rate" @change="onRateChange(row)"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="r in rates" :key="r" :value="r">{{ r }} %</option>
          </select>
        </div>
        <div class="col-span-3">
          <label class="block text-[11px] text-neutral-500 mb-0.5">
            {{ mode === 'top' ? t('cash.form.total_incl') : t('cash.form.vat_base') }}
          </label>
          <input v-if="mode === 'top'" v-model.number="row.gross" @input="onPrimaryInput(row)"
            type="number" step="0.01" min="0"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
          <input v-else v-model.number="row.base" @input="onPrimaryInput(row)"
            type="number" step="0.01" min="0"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
        </div>
        <div class="col-span-3">
          <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('cash.form.vat_base') }}</label>
          <input v-model.number="row.base" type="number" step="0.01" min="0"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
        </div>
        <div class="col-span-2">
          <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('cash.form.vat_amount') }}</label>
          <input v-model.number="row.vat" type="number" step="0.01" min="0"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
        </div>
        <div class="col-span-1 flex items-center justify-center h-9">
          <button type="button" @click="removeRow(i)" :disabled="rows.length <= 1"
            :title="t('cash.form.vat_rate_remove')" :aria-label="t('cash.form.vat_rate_remove')"
            :class="btnIconSm('danger')">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          </button>
        </div>
      </div>

      <!-- Nárok na odpočet a daňová uznatelnost (jen na vstupu — u prodeje nedávají smysl). -->
      <div v-if="deduction" class="grid grid-cols-12 gap-2 items-end">
        <div class="col-span-5">
          <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('cash.form.vat_deduction') }}</label>
          <select v-model="row.deduction"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="d in VAT_DEDUCTIONS" :key="d" :value="d">{{ t(`purchase_invoice.vat_deduction.${d}`) }}</option>
          </select>
        </div>
        <div class="col-span-3">
          <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('purchase_invoice.vat_deduction_percent') }}</label>
          <input v-model.number="row.deductionPercent" type="number" step="0.01" min="0" max="100"
            :disabled="row.deduction !== 'proportional'"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right disabled:bg-neutral-100 disabled:text-neutral-400" />
        </div>
        <div class="col-span-4">
          <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('cash.form.tax_treatment') }}</label>
          <select v-model="row.treatment"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="tr in TAX_TREATMENTS" :key="tr" :value="tr">{{ t(`cash.tax_treatment.${tr}`) }}</option>
          </select>
        </div>
        <label class="col-span-12 inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="row.fixedAsset" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          <span>{{ t('cash.form.is_fixed_asset') }}</span>
        </label>
      </div>
      </div>
    </div>
    <p v-if="deduction" class="text-[11px] text-neutral-500">{{ t('cash.form.vat_deduction_hint') }}</p>
    <p v-if="deduction" class="text-[11px] text-neutral-500">{{ t('cash.form.is_fixed_asset_hint') }}</p>

    <p v-if="!matches" class="text-xs px-3 py-2 rounded-md bg-warning-50 text-warning-700">
      {{ t('cash.form.vat_mismatch_hint', { amount: formatMoney(residual) }) }}
    </p>

    <div class="flex flex-wrap items-center justify-between gap-2">
      <button type="button" @click="addRow" :class="btnOutlineSm('primary')">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('cash.form.vat_rate_add') }}
      </button>
      <div class="text-xs flex items-center gap-3">
        <span class="text-neutral-500">{{ t('cash.form.vat_base') }}: <strong class="font-mono">{{ formatMoney(sumBase) }}</strong></span>
        <span class="text-neutral-500">{{ t('cash.form.vat_amount') }}: <strong class="font-mono">{{ formatMoney(sumVat) }}</strong></span>
        <span class="px-2 py-0.5 rounded font-medium"
          :class="matches ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
          {{ formatMoney(sumTotal) }}
        </span>
      </div>
    </div>
  </div>
</template>
