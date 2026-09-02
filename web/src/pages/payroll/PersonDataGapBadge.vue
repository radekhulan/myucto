<script setup lang="ts">
/**
 * Značka „u téhle osoby něco chybí" pro řádek seznamu zaměstnanců.
 *
 * PROČ: chybějící údaj se účetní dosud ozval až na konci cesty — u podání nebo
 * u plateb. Na seznamu lidí přitom stačí jedno číslo, aby to viděla dřív, než
 * měsíc vůbec začne.
 *
 * Dvě úrovně jsou schválně oddělené a NESČÍTAJÍ se do jednoho čísla: „bez toho
 * měsíc neprojde" a „doplní se, až bude čas" nejsou stejně naléhavé a společný
 * součet by z pěti nepodstatných maličkostí udělal poplach.
 *
 * Značka je tlačítko, ne štítek: vede rovnou na první chybějící údaj na kartě
 * osoby. Štítek, který jen popisuje problém, je pro uživatele totéž jako nic —
 * pořád ho musí najít sám.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  PayrollPersonDataGap,
  PayrollPersonDataGapCounts,
} from '@/api/payroll'

const props = defineProps<{
  gaps: PayrollPersonDataGap[]
  counts: PayrollPersonDataGapCounts | null
  /** Do `data-test`, ať se dá v testu i v podpoře najít konkrétní řádek. */
  testId: string
}>()

const emit = defineEmits<{ open: [gap: PayrollPersonDataGap] }>()

const { t } = useI18n()

const blockingGaps = computed(
  () => props.gaps.filter(gap => gap.severity === 'blocking'),
)
const advisoryGaps = computed(
  () => props.gaps.filter(gap => gap.severity === 'advisory'),
)

/**
 * Počty se berou ze serveru, ale když je (starší volající, test) nepošle,
 * dopočítají se ze seznamu. Značka nesmí zmizet jen proto, že chybí souhrn.
 */
const blockingCount = computed(
  () => props.counts?.blocking ?? blockingGaps.value.length,
)
const advisoryCount = computed(
  () => props.counts?.advisory ?? advisoryGaps.value.length,
)

/** Výčet lidských názvů do tooltipu — číslo samo neřekne, o co jde. */
function names(gaps: PayrollPersonDataGap[]): string {
  return gaps.map(gap => t(`payroll.people.data_gap.${gap.key}`)).join(', ')
}

function open(gaps: PayrollPersonDataGap[]): void {
  const first = gaps[0]
  if (first !== undefined) emit('open', first)
}
</script>

<template>
  <span v-if="blockingCount > 0 || advisoryCount > 0" class="inline-flex flex-wrap gap-1.5">
    <button
      v-if="blockingCount > 0"
      type="button"
      class="rounded-full bg-danger-50 px-2 py-1 text-xs font-medium text-danger-700 underline-offset-2 hover:bg-danger-100 hover:underline focus:outline-none focus:ring-2 focus:ring-danger-500/40"
      :title="names(blockingGaps)"
      :data-test="`${testId}-blocking`"
      @click.stop.prevent="open(blockingGaps)"
    >
      {{ t('payroll.people.data_gap_badge.blocking', { count: blockingCount }, blockingCount) }}
    </button>
    <button
      v-if="advisoryCount > 0"
      type="button"
      class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700 underline-offset-2 hover:bg-warning-100 hover:underline focus:outline-none focus:ring-2 focus:ring-warning-500/40"
      :title="names(advisoryGaps)"
      :data-test="`${testId}-advisory`"
      @click.stop.prevent="open(advisoryGaps)"
    >
      {{ t('payroll.people.data_gap_badge.advisory', { count: advisoryCount }, advisoryCount) }}
    </button>
  </span>
</template>
