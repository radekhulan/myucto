<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import type { RevenueCategoryBreakdownItem } from '@/api/dashboard'
import { formatMoneyWhole, formatPercent } from '@/composables/useFormat'
import { useChartColors, categoryColors, CHART_MAX_CATEGORIES } from '@/composables/useTheme'
import ChartEmptyState from './ChartEmptyState.vue'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

// Rozpad tržeb po kategoriích za 12 měsíců, CZK-normalizováno (server přepočítá ×exchange_rate).
const props = defineProps<{ categories: RevenueCategoryBreakdownItem[] }>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart<'doughnut', number[], string> | null = null
const { t, locale } = useI18n()
const colors = useChartColors()

const sliceData = computed(() => {
  const rows = props.categories.filter(c => c.total > 0)
  if (rows.length === 0) return { labels: [] as string[], values: [] as number[], hasOther: false }
  const sorted = [...rows].sort((a, b) => b.total - a.total)
  // Viz TopClientsPieChart: paleta má šest rozlišitelných tónů + neutrální slot
  // pro „Ostatní", proto se slučuje od sedmé kategorie výš.
  const top = sorted.slice(0, CHART_MAX_CATEGORIES)
  const rest = sorted.slice(CHART_MAX_CATEGORIES)
  const labels = top.map(c => c.label || t('stats.revenue_breakdown.uncategorized'))
  const values = top.map(c => c.total)
  const hasOther = rest.length > 0
  if (hasOther) {
    labels.push(t('common.other'))
    values.push(rest.reduce((s, c) => s + c.total, 0))
  }
  return { labels, values, hasOther }
})

function build() {
  if (!canvas.value) return
  if (chart) { chart.destroy(); chart = null }
  const { labels, values, hasOther } = sliceData.value
  if (labels.length === 0) return
  const total = values.reduce((s, v) => s + v, 0)
  chart = new Chart(canvas.value, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: categoryColors(labels.length, hasOther, colors.value.palette),
        borderWidth: 1,
        borderColor: colors.value.border,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'right',
          labels: { boxWidth: 12, font: { size: 11 }, color: colors.value.tick },
        },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed as number
              const pct = total > 0 ? (v / total) * 100 : 0
              return ` ${ctx.label}: ${formatMoneyWhole(v, 'CZK')} (${formatPercent(pct, 1)})`
            },
          },
        },
      },
      cutout: '55%',
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => props.categories, build, { deep: true })
watch(() => locale.value, build)
watch(colors, build)
</script>

<template>
  <div class="relative h-64">
    <ChartEmptyState v-if="sliceData.labels.length === 0" />
    <canvas v-else ref="canvas"></canvas>
  </div>
</template>
