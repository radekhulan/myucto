<script setup lang="ts">
/**
 * Rychlý filtr „měsíc a rok" nad dlouhým seznamem.
 *
 * Why: přehledy podání i zpráv datové schránky rostou o desítky řádků měsíčně
 * a nic se z nich nemaže. Se stránkováním se sice dolistuje všechno, ale
 * hledání konkrétního měsíce je listování naslepo.
 *
 * Nabídka roků se bere z DAT (server vrací, ve kterých letech firma něco má),
 * ne z pevného rozsahu — prázdný rok v nabídce vede jen k prázdnému seznamu.
 * Výchozí stav je „Vše", takže filtr nikdy nic neschová, dokud si o to uživatel
 * neřekne; opak by znamenal, že uživatel nevidí řádky a neví proč.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  year: number | null
  month: number | null
  /** Roky, ve kterých data opravdu jsou; od nejnovějšího. */
  years?: number[]
  /** Kolik řádků filtru odpovídá — ať je poznat, že se seznam zúžil. */
  total?: number | null
}>(), {
  years: () => [],
  total: null,
})

const emit = defineEmits<{
  'update:year': [value: number | null]
  'update:month': [value: number | null]
}>()

const { t, locale } = useI18n()

/** Názvy měsíců z prohlížeče podle jazyka aplikace — vlastní tabulka by zestárla. */
const months = computed(() => {
  const formatter = new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  return Array.from({ length: 12 }, (_value, index) => ({
    value: index + 1,
    label: formatter.format(new Date(2026, index, 1)),
  }))
})

const active = computed(() => props.year !== null || props.month !== null)

function toValue(raw: string): number | null {
  return raw === '' ? null : Number(raw)
}

const selectClass = 'h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface'
</script>

<template>
  <div class="flex flex-wrap items-center gap-2" data-test="period-filter">
    <select
      :value="month ?? ''"
      :class="selectClass"
      :aria-label="t('period_filter.month')"
      data-test="period-filter-month"
      @change="emit('update:month', toValue(($event.target as HTMLSelectElement).value))"
    >
      <option value="">{{ t('period_filter.all_months') }}</option>
      <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
    </select>

    <select
      :value="year ?? ''"
      :class="selectClass"
      :aria-label="t('period_filter.year')"
      data-test="period-filter-year"
      @change="emit('update:year', toValue(($event.target as HTMLSelectElement).value))"
    >
      <option value="">{{ t('period_filter.all_years') }}</option>
      <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
    </select>

    <button
      v-if="active"
      type="button"
      class="cursor-pointer text-sm text-neutral-500 hover:text-neutral-800 underline"
      data-test="period-filter-clear"
      @click="emit('update:year', null); emit('update:month', null)"
    >
      {{ t('period_filter.clear') }}
    </button>

    <span v-if="total !== null" class="text-xs text-neutral-500" data-test="period-filter-count">
      {{ t('period_filter.count', { count: total }) }}
    </span>
  </div>
</template>
