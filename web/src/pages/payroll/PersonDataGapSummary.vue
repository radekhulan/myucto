<script setup lang="ts">
/**
 * „Co u téhle osoby chybí" — souhrn nahoře na kartě zaměstnance.
 *
 * PROČ: seznam lidí umí říct, ŽE něco chybí. Na kartě musí být vidět CO, a to
 * lidským názvem údaje, ne názvem sloupce — a s tlačítkem, které na to pole
 * doskočí. Vzor je žlutý výčet „doplňte ručně" v `EmploymentRegistrationPanel`;
 * schválně se chová stejně, ať se to uživatel neučí dvakrát.
 *
 * Nic z toho neblokuje uložení. Je to informace, ne závora — proto ani jeden
 * z bloků nenese tlačítko „nelze pokračovat" a oba jdou ignorovat.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollPersonDataGap } from '@/api/payroll'

const props = defineProps<{ gaps: PayrollPersonDataGap[] }>()
const emit = defineEmits<{ open: [gap: PayrollPersonDataGap] }>()

const { t } = useI18n()

const blocking = computed(
  () => props.gaps.filter(gap => gap.severity === 'blocking'),
)
const advisory = computed(
  () => props.gaps.filter(gap => gap.severity === 'advisory'),
)
</script>

<template>
  <div
    v-if="gaps.length > 0"
    class="space-y-3"
    data-test="person-data-gap-summary"
  >
    <!--
      Blokující nálezy mají vlastní rám a vlastní barvu. Sloučené do jednoho
      seznamu by se „bez tohohle měsíc neprojde" ztratilo mezi radami.
    -->
    <div
      v-if="blocking.length > 0"
      class="rounded-md border border-danger-300 bg-danger-50 p-3"
      data-test="person-data-gap-blocking"
    >
      <h3 class="text-xs font-semibold text-danger-800">
        {{ t('payroll.people.data_gap_summary.blocking_title', { count: blocking.length }, blocking.length) }}
      </h3>
      <p class="mt-1 text-xs text-danger-800">
        {{ t('payroll.people.data_gap_summary.blocking_hint') }}
      </p>
      <ul class="mt-2 space-y-1.5 text-xs text-danger-800">
        <li v-for="gap in blocking" :key="gap.key">
          <span class="font-medium">{{ t(`payroll.people.data_gap.${gap.key}`) }}</span>
          — {{ t(`payroll.people.data_gap_where.${gap.key}`) }}
          <button
            type="button"
            class="ml-1 whitespace-nowrap rounded-full bg-danger-100 px-2 py-0.5 font-medium underline underline-offset-2 hover:bg-danger-200 hover:text-danger-900 focus:outline-none focus:ring-2 focus:ring-danger-500/40"
            :data-test="`person-data-gap-open-${gap.key}`"
            @click="emit('open', gap)"
          >
            {{ t('payroll.people.data_gap_summary.open') }}
          </button>
        </li>
      </ul>
    </div>

    <div
      v-if="advisory.length > 0"
      class="rounded-md border border-warning-200 bg-warning-50 p-3"
      data-test="person-data-gap-advisory"
    >
      <h3 class="text-xs font-semibold text-warning-800">
        {{ t('payroll.people.data_gap_summary.advisory_title', { count: advisory.length }, advisory.length) }}
      </h3>
      <p class="mt-1 text-xs text-warning-800">
        {{ t('payroll.people.data_gap_summary.advisory_hint') }}
      </p>
      <ul class="mt-2 space-y-1.5 text-xs text-warning-800">
        <li v-for="gap in advisory" :key="gap.key">
          <span class="font-medium">{{ t(`payroll.people.data_gap.${gap.key}`) }}</span>
          — {{ t(`payroll.people.data_gap_where.${gap.key}`) }}
          <button
            type="button"
            class="ml-1 whitespace-nowrap rounded-full bg-warning-100 px-2 py-0.5 font-medium underline underline-offset-2 hover:bg-warning-200 hover:text-warning-900 focus:outline-none focus:ring-2 focus:ring-warning-500/40"
            :data-test="`person-data-gap-open-${gap.key}`"
            @click="emit('open', gap)"
          >
            {{ t('payroll.people.data_gap_summary.open') }}
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
