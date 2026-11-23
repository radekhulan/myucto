<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChartAccount } from '@/api/accounting'
import type { OtherItemPostingLine } from '@/api/otherItems'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'

const props = defineProps<{ modelValue: OtherItemPostingLine[]; accounts: ChartAccount[]; total: number }>()
const emit = defineEmits<{ 'update:modelValue': [OtherItemPostingLine[]] }>()
const { t } = useI18n()
const sumCents = computed(() => props.modelValue.reduce((sum, line) => sum + Math.round(Number(line.amount || 0) * 100), 0))
const totalCents = computed(() => Math.round(Number(props.total || 0) * 100))
const balanced = computed(() => sumCents.value === totalCents.value && totalCents.value > 0)

function updateLine(index: number, changes: Partial<OtherItemPostingLine>) {
  emit('update:modelValue', props.modelValue.map((line, position) => position === index ? { ...line, ...changes } : line))
}

function removeLine(index: number) {
  if (props.modelValue.length < 2) return
  emit('update:modelValue', props.modelValue.filter((_, position) => position !== index))
}

function addLine() {
  emit('update:modelValue', [...props.modelValue, { account_code: '', amount: 0 }])
}
</script>

<template>
  <div class="space-y-3">
    <p class="text-sm text-neutral-500">{{ t('other_items.posting_lines_hint') }}</p>
    <div v-for="(line, index) in modelValue" :key="index" class="flex flex-wrap items-start gap-2">
      <label class="min-w-48 flex-1 text-sm font-medium">{{ t('other_items.posting_line_account', { number: index + 1 }) }}
        <ChartAccountSelect :model-value="line.account_code" :accounts="accounts" :placeholder="t('other_items.choose_account')" class="mt-1 block" @update:model-value="updateLine(index, { account_code: $event })" />
      </label>
      <label class="w-36 text-sm font-medium">{{ t('other_items.posting_line_amount') }}
        <input :value="line.amount" type="number" min="0.01" step="0.01" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-right" @input="updateLine(index, { amount: Number(($event.target as HTMLInputElement).value) })" />
      </label>
      <button v-if="modelValue.length > 1" type="button" :class="btnOutline('danger')" class="self-end whitespace-nowrap" @click="removeLine(index)">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
        {{ t('common.delete') }}
      </button>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="addLine">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('other_items.add_posting_line') }}
      </button>
      <span class="text-sm tabular-nums" :class="balanced ? 'text-success-600' : 'text-danger-600'">
        {{ t('other_items.posting_lines_total') }}: {{ formatMoney(sumCents / 100, 'CZK') }} / {{ formatMoney(totalCents / 100, 'CZK') }}
      </span>
    </div>
  </div>
</template>
