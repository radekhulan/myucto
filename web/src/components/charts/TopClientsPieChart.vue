<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import type { TopClient } from '@/api/dashboard'
import { formatMoneyWhole, formatPercent } from '@/composables/useFormat'
import { useChartColors, categoryColors, CHART_MAX_CATEGORIES } from '@/composables/useTheme'
import ChartEmptyState from './ChartEmptyState.vue'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

// Po sjednocení na total_czk (CZK přepočet přes i.exchange_rate) už neřešíme currency filter —
// graf vždy ukazuje agregovaný obrat klienta v CZK.
const props = defineProps<{ clients: TopClient[] }>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart<'doughnut', number[], string> | null = null
const { t, locale } = useI18n()
const colors = useChartColors()

const sliceData = computed(() => {
  if (props.clients.length === 0) return { labels: [] as string[], values: [] as number[], hasOther: false }
  const sorted = [...props.clients].sort((a, b) => b.total_czk - a.total_czk)
  // Slučujeme od sedmé položky výš: paleta má šest rozlišitelných tónů a sedmý
  // neutrální slot pro „Ostatní". Dřív se bralo osm a barvy se pak cyklovaly,
  // takže dva klienti vyšli stejnou barvou.
  const top = sorted.slice(0, CHART_MAX_CATEGORIES)
  const rest = sorted.slice(CHART_MAX_CATEGORIES)
  const labels = top.map(c => c.company_name)
  const values = top.map(c => c.total_czk)
  const hasOther = rest.length > 0
  if (hasOther) {
    labels.push(t('common.other'))
    values.push(rest.reduce((s, c) => s + c.total_czk, 0))
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
watch(() => props.clients, build, { deep: true })
watch(() => locale.value, build)
watch(colors, build)
</script>

<template>
  <div class="relative h-64">
    <ChartEmptyState v-if="sliceData.labels.length === 0" />
    <canvas v-else ref="canvas"></canvas>
  </div>
</template>
