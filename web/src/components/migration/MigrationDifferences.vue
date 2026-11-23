<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MigrationWizardDifference } from '@/composables/useMigrationWizard'

/**
 * Zkouška nanečisto selhala jen na rozdílech k přijetí (doklad se nepřevedl, převod nesedí
 * na zdroj nad haléřové zaokrouhlení): seznam rozdílů a vědomé přijetí před ostrým převodem.
 * Texty se berou z jmenného prostoru `prefix` (`money_s3`, `pohoda`, `premier`).
 */
const props = withDefaults(defineProps<{ differences: MigrationWizardDifference[]; prefix?: string; disabled?: boolean }>(), { prefix: 'money_s3', disabled: false })
const accepted = defineModel<boolean>({ default: false })
const { t, te } = useI18n()

function k(key: string): string {
  return `${props.prefix}.${key}`
}

function stepLabel(step: string): string {
  return te(k(`steps.${step}`)) ? t(k(`steps.${step}`)) : step
}

const items = computed(() => props.differences.map((d, i) => ({ ...d, key: `${d.runId}-${i}`, stepLabel: stepLabel(d.step) })))
</script>

<template>
  <div class="mt-4 rounded-lg border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-700" data-testid="migration-differences">
    <h3 class="font-semibold">{{ t(k('differences.title'), { count: differences.length }) }}</h3>
    <p class="mt-1">{{ t(k('differences.hint')) }}</p>
    <ul class="mt-3 max-h-72 space-y-1 overflow-y-auto">
      <li v-for="d in items" :key="d.key" class="rounded border border-warning-500/20 bg-surface px-3 py-2 whitespace-pre-line text-neutral-700">
        <span class="font-medium">{{ d.stepLabel }}:</span> {{ d.text }}
      </li>
    </ul>
    <label class="mt-3 flex cursor-pointer items-start gap-3">
      <input v-model="accepted" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" :disabled="disabled" data-testid="migration-accept-differences" />
      <span>{{ t(k('differences.accept')) }}</span>
    </label>
  </div>
</template>
