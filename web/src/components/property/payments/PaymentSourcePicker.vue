<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentsApi, type PaymentCandidate, type PaymentCandidateKind } from '@/api/propertyPayments'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { failureMessage } from './operations/format'
import { propertyInput as inputClass } from '@/components/property/propertyFormStyles'

const props = defineProps<{ supplier: number; candidates: PaymentCandidate[]; modelValue: string; canBank: boolean; canCash: boolean; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: string]; found: [items: PaymentCandidate[]] }>()
const { t } = useI18n()
const query = ref('')
const searching = ref(false)
const error = ref('')
let ticket = 0

const keyOf = (c: PaymentCandidate) => `${c.kind}:${c.id}`
const usable = (c: PaymentCandidate) => c.support !== 'fx_incomplete' && (c.existing_owner || (c.kind === 'bank' ? props.canBank : props.canCash))

/** Shoda v načtených zdrojích: VS a SS přesně, protistrana, popis nebo částka obsahem. */
const visible = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.candidates
  return props.candidates.filter(c => c.variable_symbol === q || c.specific_symbol === q
    || [c.partner_name, c.description, c.amount, c.variable_symbol].some(v => (v ?? '').toLowerCase().includes(q)))
})

async function search() {
  const q = query.value.trim()
  if (!q || props.disabled) return
  const current = ++ticket
  searching.value = true
  error.value = ''
  try {
    const kinds: PaymentCandidateKind[] = ['bank', 'cash']
    const pages = await Promise.all(kinds.map(kind => propertyPaymentsApi.candidates(props.supplier, kind, 0, 50, { q })))
    if (current === ticket) emit('found', pages.flatMap(page => page.items))
  } catch (e) {
    if (current === ticket) error.value = failureMessage(e, t('propertyPayments.failed'))
  } finally {
    if (current === ticket) searching.value = false
  }
}
</script>

<template>
  <div class="space-y-2" data-section="source-picker">
    <form class="flex flex-wrap items-end gap-2" role="search" @submit.prevent="search">
      <label class="flex min-w-0 flex-1 flex-col gap-1 text-sm">{{ t('propertyPayments.sourcePicker.search') }}
        <input v-model="query" type="search" :class="inputClass" :disabled="disabled" data-field="source-search" :placeholder="t('propertyPayments.sourcePicker.placeholder')">
      </label>
      <PropertyButton submit icon="search" variant="primary" :disabled="disabled || searching || !query.trim()" data-action="source-search">{{ t('propertyPayments.sourcePicker.searchAction') }}</PropertyButton>
    </form>
    <p v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</p>
    <p v-if="!visible.length" class="text-sm text-neutral-500" data-empty>{{ t('propertyPayments.sourcePicker.empty') }}</p>
    <ul v-else role="listbox" :aria-label="t('propertyPayments.chooseSource')" class="max-h-80 divide-y divide-neutral-200 overflow-auto rounded border border-neutral-200">
      <li v-for="c in visible" :key="keyOf(c)">
        <button type="button" role="option" :aria-selected="modelValue === keyOf(c)" :disabled="disabled || !usable(c)" :data-source="keyOf(c)"
          class="flex w-full flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 text-left text-sm disabled:opacity-50"
          :class="modelValue === keyOf(c) ? 'bg-primary-50 text-primary-700' : 'hover:bg-neutral-50'"
          @click="emit('update:modelValue', keyOf(c))">
          <span class="whitespace-nowrap">{{ c.date }}</span>
          <span class="whitespace-nowrap font-mono font-medium">{{ c.amount }} {{ c.currency }}</span>
          <span v-if="c.variable_symbol" class="whitespace-nowrap font-mono">{{ t('propertyPayments.sourcePicker.vs', { value: c.variable_symbol }) }}</span>
          <span v-if="c.specific_symbol" class="whitespace-nowrap font-mono">{{ t('propertyPayments.sourcePicker.ss', { value: c.specific_symbol }) }}</span>
          <span class="whitespace-nowrap text-neutral-500">{{ t(`propertyPayments.sourcePicker.kind.${c.kind}`) }}</span>
          <span v-if="c.partner_name" class="min-w-0 truncate">{{ c.partner_name }}</span>
          <span v-if="c.description" class="min-w-0 basis-full truncate text-neutral-500">{{ c.description }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>
