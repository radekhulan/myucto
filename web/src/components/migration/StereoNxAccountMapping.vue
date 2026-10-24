<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { StereoAccountMapping, StereoAccountMappingProposal } from '@/api/stereoNx'
import { formatDate, formatMoney } from '@/composables/useFormat'
import StereoNxAccountTargetInput from './StereoNxAccountTargetInput.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { normalizeStereoAccountCode as normalize, stereoTargetAccounts } from './stereoNxAccountTargets'

const props = defineProps<{
  proposal: StereoAccountMappingProposal
  modelValue: StereoAccountMapping
  disabled?: boolean
}>()
const emit = defineEmits<{
  'update:modelValue': [value: StereoAccountMapping]
  valid: [value: boolean]
}>()
const { t } = useI18n()
const vatFields = ['input', 'output', 'settlement'] as const
const hasVat = computed(() => props.proposal.used_accounts.some(account => account.source_code.startsWith('343')))
const unresolvedReverseCharge = computed(() => (props.proposal.vat_counts.reverse_charge_unresolved ?? 0) > 0
  || props.proposal.unresolved.some(item => item.reason === 'vat_reverse_charge_ambiguous'))

const availableAccounts = computed(() => new Map(stereoTargetAccounts(props.proposal.available_accounts ?? []).map(account => [normalize(account.code), account])))
const suggestedAccounts = computed(() => new Map((props.proposal.suggested_accounts ?? []).map(account => [normalize(account.code), account.name])))
function inactiveTarget(value: string | null | undefined): boolean {
  return availableAccounts.value.get(normalize(value ?? ''))?.is_active === false
}
function targetDescription(value: string | null | undefined, fallback: string): string {
  const code = normalize(value ?? '')
  if (!code) return ''
  const existing = availableAccounts.value.get(code)
  if (existing) return t(existing.is_active === false ? 'stereo_nx.mapping.target_inactive' : 'stereo_nx.mapping.target_existing', { name: existing.name })
  return t('stereo_nx.mapping.target_new', { name: suggestedAccounts.value.get(code) || fallback || code })
}
function vatAccountName(field: typeof vatFields[number] | 'unresolved'): string {
  const declared = props.proposal.vat_account_names?.[field]
  if (declared) return declared
  const proposed = props.proposal.vat?.[field]
  const suggested = proposed ? suggestedAccounts.value.get(normalize(proposed)) : undefined
  return suggested || t(field === 'unresolved' ? 'stereo_nx.mapping.vat_unresolved_account_name' : `stereo_nx.mapping.vat_${field}`)
}
function validCode(value: string | null | undefined, source: string): boolean {
  const code = normalize(value ?? '')
  return code.length <= 10 && /^[0-9]{3}[0-9A-Za-z./-]*$/.test(code) && code.slice(0, 3) === source.slice(0, 3) && !inactiveTarget(code)
}
function validBankCode(value: string | undefined): boolean {
  return /^221\.[0-9]{1,6}$/.test(normalize(value ?? '')) && !inactiveTarget(value)
}
function bankIdentity(bank: StereoAccountMappingProposal['banks'][number]): string {
  return bank.identity_key || bank.source_key
}
const bankTargetsValid = computed(() => {
  const owners = new Map<string, string>()
  const targets = new Map<string, string>()
  return props.proposal.banks.every(bank => {
    const target = normalize(props.modelValue.bank_accounts[bank.source_key] ?? '')
    const owner = owners.get(target)
    const identityTarget = targets.get(bankIdentity(bank))
    if (owner !== undefined && owner !== bankIdentity(bank)) return false
    if (identityTarget !== undefined && identityTarget !== target) return false
    owners.set(target, bankIdentity(bank))
    targets.set(bankIdentity(bank), target)
    return true
  })
})
const vatTargetsDistinct = computed(() => new Set(vatFields.map(field => normalize(props.modelValue.vat?.[field] ?? ''))).size === vatFields.length)
const valid = computed(() => props.proposal.used_accounts.every(account =>
  (props.modelValue.vat !== null && account.source_code.startsWith('343')) || validCode(props.modelValue.accounts[account.source_code], account.source_code))
  && props.proposal.banks.every(bank => validBankCode(props.modelValue.bank_accounts[bank.source_key]))
  && bankTargetsValid.value
  && (!props.modelValue.vat || (!unresolvedReverseCharge.value && vatTargetsDistinct.value && vatFields.every(field => validCode(props.modelValue.vat?.[field], '343'))
    && (props.proposal.vat_counts.unresolved === 0 || validCode(props.modelValue.vat.unresolved, '343')))))
watch(valid, value => emit('valid', value), { immediate: true })

function updateAccount(source: string, value: string): void {
  emit('update:modelValue', { ...props.modelValue, accounts: { ...props.modelValue.accounts, [source]: normalize(value) } })
}
function updateBank(source: string, value: string): void {
  const bank = props.proposal.banks.find(item => item.source_key === source)
  const accounts = { ...props.modelValue.bank_accounts, [source]: normalize(value) }
  if (bank) for (const alias of props.proposal.banks) {
    if (bankIdentity(alias) === bankIdentity(bank)) accounts[alias.source_key] = normalize(value)
  }
  emit('update:modelValue', { ...props.modelValue, bank_accounts: accounts })
}
function updateVat(field: typeof vatFields[number] | 'unresolved', value: string): void {
  if (!props.modelValue.vat) return
  const vat = { ...props.modelValue.vat }
  if (field === 'unresolved') vat.unresolved = normalize(value) || null
  else vat[field] = normalize(value)
  emit('update:modelValue', { ...props.modelValue, vat })
}
function toggleVat(enabled: boolean): void {
  emit('update:modelValue', { ...props.modelValue, vat: enabled
    ? { ...(props.proposal.vat ?? { input: '343.100', output: '343.200', settlement: '343.900', unresolved: null }) }
    : null })
}
</script>

<template>
  <div class="space-y-5" data-testid="stereo-account-mapping">
    <div>
      <h2 class="text-lg font-semibold">{{ t('stereo_nx.mapping.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-600">{{ t('stereo_nx.mapping.hint') }}</p>
      <p class="mt-2 text-xs text-neutral-500">{{ t('stereo_nx.mapping.format_hint') }}</p>
    </div>
    <div v-if="!proposal.used_accounts.length" class="rounded-md border border-neutral-200 px-4 py-3 text-sm text-neutral-600">{{ t('stereo_nx.mapping.empty') }}</div>
    <div v-else class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="w-full text-left text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-600">
          <tr><th class="px-3 py-2">{{ t('stereo_nx.mapping.source') }}</th><th class="px-3 py-2">{{ t('stereo_nx.mapping.name') }}</th><th class="px-3 py-2 text-right">{{ t('stereo_nx.mapping.usage') }}</th><th class="px-3 py-2">{{ t('stereo_nx.mapping.target') }}</th></tr>
        </thead>
        <tbody class="divide-y divide-neutral-200">
          <tr v-for="account in proposal.used_accounts" :key="account.source_code" :data-source-account="account.source_code">
            <td class="px-3 py-3 font-mono">{{ account.source_code }}</td><td class="px-3 py-3">{{ account.name }}</td><td class="px-3 py-3 text-right tabular-nums">{{ account.count }}</td>
            <td class="px-3 py-3">
              <span v-if="modelValue.vat && account.source_code.startsWith('343')" class="inline-flex rounded-full bg-primary-50 px-2 py-1 text-xs text-primary-700" data-testid="stereo-vat-auto">{{ t('stereo_nx.mapping.vat_auto') }}</span>
              <template v-else>
                <StereoNxAccountTargetInput :model-value="modelValue.accounts[account.source_code]" :test-id="`stereo-account-${account.source_code}`" :label="t('stereo_nx.mapping.target_for', { source: account.source_code })" :source-code="account.source_code" :accounts="proposal.available_accounts ?? []" :invalid="!validCode(modelValue.accounts[account.source_code], account.source_code)" class="w-56" :disabled="disabled" @update:model-value="updateAccount(account.source_code, $event)" />
                <p :data-testid="`stereo-account-status-${account.source_code}`" class="mt-1 max-w-xs text-xs" :class="inactiveTarget(modelValue.accounts[account.source_code]) ? 'text-danger-600' : 'text-neutral-600'">{{ targetDescription(modelValue.accounts[account.source_code], account.name) }}</p>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <section v-if="proposal.banks.length" class="rounded-lg border border-neutral-200 p-4" data-testid="stereo-bank-mapping">
      <h3 class="font-medium">{{ t('stereo_nx.mapping.banks_title') }}</h3>
      <p class="mt-1 text-sm text-neutral-600">{{ t('stereo_nx.mapping.banks_hint') }}</p>
      <div class="mt-3 space-y-3">
        <label v-for="bank in proposal.banks" :key="bank.source_key" class="flex flex-wrap items-center justify-between gap-3 text-sm">
          <span class="min-w-0 break-words"><strong>{{ bank.label }}</strong><span class="ml-2 font-mono text-neutral-500">{{ bank.source_key }}</span></span>
          <span class="block max-w-sm">
            <StereoNxAccountTargetInput :model-value="modelValue.bank_accounts[bank.source_key]" :test-id="`stereo-bank-account-${bank.source_key}`" :label="t('stereo_nx.mapping.bank_target_for', { bank: bank.label })" source-code="221" bank :accounts="proposal.available_accounts ?? []" :invalid="!validBankCode(modelValue.bank_accounts[bank.source_key])" class="w-56" :disabled="disabled" @update:model-value="updateBank(bank.source_key, $event)" />
            <span :data-testid="`stereo-bank-status-${bank.source_key}`" class="mt-1 block text-xs" :class="inactiveTarget(modelValue.bank_accounts[bank.source_key]) ? 'text-danger-600' : 'text-neutral-600'">{{ targetDescription(modelValue.bank_accounts[bank.source_key], bank.label) }}</span>
          </span>
        </label>
      </div>
    </section>
    <section v-if="hasVat" class="rounded-lg border border-neutral-200 p-4" data-testid="stereo-vat-mapping">
      <label class="flex items-start gap-3 text-sm font-medium"><input :checked="modelValue.vat !== null" data-testid="stereo-vat-enabled" type="checkbox" class="mt-1" :disabled="disabled" @change="toggleVat(($event.target as HTMLInputElement).checked)" />{{ t('stereo_nx.mapping.vat_title') }}</label>
      <p class="mt-2 text-sm text-neutral-600">{{ t('stereo_nx.mapping.vat_hint') }}</p>
      <div v-if="modelValue.vat" class="mt-4 space-y-4">
        <div class="grid gap-4 sm:grid-cols-3">
          <label v-for="field in vatFields" :key="field" class="block text-sm"><span class="block font-medium">{{ t(`stereo_nx.mapping.vat_${field}`) }}</span><span class="mt-1 block text-xs text-neutral-500">{{ t('stereo_nx.mapping.vat_count', { count: proposal.vat_counts[field] }) }}</span><StereoNxAccountTargetInput :model-value="modelValue.vat[field]" :test-id="`stereo-vat-${field}`" :label="t(`stereo_nx.mapping.vat_${field}`)" source-code="343" :accounts="proposal.available_accounts ?? []" :invalid="!validCode(modelValue.vat[field], '343')" class="mt-2" :disabled="disabled" @update:model-value="updateVat(field, $event)" /><span :data-testid="`stereo-vat-status-${field}`" class="mt-1 block text-xs" :class="inactiveTarget(modelValue.vat[field]) ? 'text-danger-600' : 'text-neutral-600'">{{ targetDescription(modelValue.vat[field], vatAccountName(field)) }}</span></label>
        </div>
        <div v-if="proposal.vat_counts.unresolved > 0" class="rounded-lg border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-700" role="alert">
          <template v-if="unresolvedReverseCharge">
            <p data-testid="stereo-vat-reverse-charge-warning">{{ t('stereo_nx.mapping.reverse_charge_hint') }}</p>
            <div class="mt-3 flex flex-wrap gap-2"><button type="button" :class="btnOutline('warning')" class="whitespace-nowrap" data-testid="stereo-vat-manual" :disabled="disabled" @click="toggleVat(false)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('stereo_nx.mapping.use_manual_vat') }}</button></div>
          </template>
          <template v-else>
            <p>{{ t('stereo_nx.mapping.vat_unresolved_hint', { count: proposal.vat_counts.unresolved }) }}</p>
            <label class="mt-3 block font-medium">{{ t('stereo_nx.mapping.vat_unresolved') }}<StereoNxAccountTargetInput :model-value="modelValue.vat.unresolved" test-id="stereo-vat-unresolved" :label="t('stereo_nx.mapping.vat_unresolved')" source-code="343" :accounts="proposal.available_accounts ?? []" :invalid="!validCode(modelValue.vat.unresolved, '343')" class="mt-1 max-w-sm text-neutral-800" :disabled="disabled" @update:model-value="updateVat('unresolved', $event)" /><span data-testid="stereo-vat-status-unresolved" class="mt-1 block text-xs font-normal" :class="inactiveTarget(modelValue.vat.unresolved) ? 'text-danger-600' : 'text-neutral-600'">{{ targetDescription(modelValue.vat.unresolved, vatAccountName('unresolved')) }}</span></label>
          </template>
          <ul v-if="proposal.unresolved.length" class="mt-4 space-y-3">
            <li v-for="(item, index) in proposal.unresolved" :key="index" class="rounded-md border border-warning-500/20 bg-surface p-3 text-neutral-700" data-testid="stereo-vat-unresolved-row">
              <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><span class="font-medium">{{ item.document_no || t('stereo_nx.unnumbered_document') }}</span><span v-if="item.date" class="text-xs">{{ formatDate(item.date) }}</span><strong v-if="item.amount_cents !== undefined" class="tabular-nums">{{ formatMoney(item.amount_cents / 100, 'CZK') }}</strong></div>
              <p v-if="item.description" class="mt-1 break-words text-sm">{{ item.description }}</p>
              <p class="mt-2 text-xs"><span v-if="item.debit || item.credit">{{ t('stereo_nx.mapping.posting', { debit: item.debit || '—', credit: item.credit || '—' }) }}</span><span v-else>{{ item.side }} {{ item.source_code }}</span><span v-if="item.source_name" class="ml-2">{{ item.source_name }}</span></p>
              <p class="mt-2 text-xs text-warning-700">{{ t(item.is_opening || item.reason === 'opening_balance' ? 'stereo_nx.mapping.opening_balance_hint' : item.reason === 'vat_reverse_charge_ambiguous' ? 'stereo_nx.mapping.reverse_charge_hint' : 'stereo_nx.mapping.ambiguous_vat_hint') }}</p>
              <details class="mt-2 text-xs text-neutral-500"><summary class="cursor-pointer">{{ t('stereo_nx.mapping.source_reference') }}</summary><code class="mt-1 block break-all">{{ item.source_key }}</code></details>
            </li>
          </ul>
        </div>
      </div>
    </section>
    <p v-if="!valid" class="text-sm text-danger-600" role="alert">{{ t('stereo_nx.mapping.invalid') }}</p>
  </div>
</template>
