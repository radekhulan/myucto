<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TaxSource } from '@/api/nonprofitTax'
import type { DecisionDraft } from '@/utils/nonprofitTaxDraft'
import { activityName, centsText } from '@/utils/nonprofitTaxDraft'
const props = defineProps<{ source: TaxSource; cohorts: { code: string; evidence: string }[]; manual?: boolean; profileKind?: string; qualifiedPurposes?: string[] }>()
const model = defineModel<DecisionDraft>({ required: true })
const { t } = useI18n()
const titles = computed(() => props.source.kind === 'expense'
  ? ['cost', 'nonbusiness_cost', 'excluded_cost', 'withholding_interest_cost']
  : props.source.svj_owner_facts ? [props.source.svj_owner_facts.legal_basis === 'zdp_18_2f_2' ? 'svj_management_payment' : 'svj_service_payment'] : ['business', 'nonbusiness', 'rent', 'advertising', 'membership', 'gift', 'public_operating_grant', 'public_investment_grant', 'bank_interest', 'other_taxable'])
const booleans = computed(() => {
  if (props.manual) return model.value.title === 'gift' ? ['claim_exemption'] : []
  if (model.value.title === 'gift') return model.value.facts.claim_exemption === true ? ['claim_exemption', 'qualifying_purpose'] : ['claim_exemption']
  if (model.value.title === 'membership') return ['qualifying_membership']
  if (model.value.title.startsWith('public_')) return ['public_budget']
  if (model.value.title === 'bank_interest') return ['domestic_bank_account', ...(props.profileKind === 'svj' ? [] : ['foundation_endowment', ...(model.value.facts.foundation_endowment === true ? ['foundation_purpose_only'] : [])])]
  return []
})
</script>
<template>
  <article class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="font-semibold">{{ source.account_code }} · {{ source.date }}<template v-if="activityName(source)"> · {{ activityName(source) }}</template></h3>
      <span class="font-mono tabular-nums">{{ centsText(source.amount_cents) }} {{ t('nonprofitTax.czk') }}</span>
    </div>
    <p class="text-xs text-neutral-600">{{ t('nonprofitTax.sourceReference', { line: source.line_id, origin: source.origin.line_id }) }} · {{ t('nonprofitTax.preliminaryRegime') }}: {{ t(`nonprofitTax.regimes.${source.tax_regime ?? 'unknown'}`) }}</p>
    <p v-if="source.svj_owner_facts" data-test="svj-owner-facts" class="text-sm text-neutral-600">{{ t('nonprofitTaxNative.ownerEvidence') }} {{ source.svj_owner_facts.legal_basis }}</p>
    <div class="grid gap-3 md:grid-cols-2">
      <label v-if="manual" class="block text-sm">{{ t('nonprofitTax.finalRegime') }}<select v-model="model.final_regime" required data-test="final-regime" class="form-input mt-1 h-9 w-full"><option value="">{{ t('nonprofitTax.choose') }}</option><option v-for="regime in ['subject', 'exempt', 'out_of_scope']" :key="regime" :value="regime">{{ t(`nonprofitTax.regimes.${regime}`) }}</option></select></label>
      <label v-if="manual && source.kind === 'revenue'" class="block text-sm">{{ t('nonprofitTax.manual.finalMethod') }}<select v-model="model.final_method" required data-test="final-method" class="form-input mt-1 h-9 w-full"><option value="">{{ t('nonprofitTax.choose') }}</option><option v-for="method in ['return', 'none', 'final_withholding']" :key="method" :value="method">{{ t(`nonprofitTax.manual.methods.${method}`) }}</option></select></label>
      <label class="block text-sm">{{ t('nonprofitTax.legalTitle') }}<select v-model="model.title" :required="manual" class="form-input mt-1 h-9 w-full"><option value="">{{ t('nonprofitTax.choose') }}</option><option v-for="title in titles" :key="title" :value="title">{{ t(title.startsWith('svj_') ? `nonprofitTaxNative.titles.${title}` : `nonprofitTax.titles.${title}`) }}</option></select></label>
      <label v-if="cohorts.length" class="block text-sm">{{ t('nonprofitTax.cohort') }}<select v-model="model.cohort" class="form-input mt-1 h-9 w-full"><option value="">{{ t('nonprofitTax.choose') }}</option><option v-for="cohort in cohorts" :key="cohort.code" :value="cohort.code">{{ cohort.code }}</option></select></label>
      <label v-if="source.kind === 'expense' && model.title === 'nonbusiness_cost' && qualifiedPurposes?.length" class="block text-sm">{{ t('nonprofitTaxNative.qualifiedPurpose') }}<select v-model="model.facts.saving_qualified_purpose" data-test="saving-purpose" class="form-input mt-1 h-9 w-full"><option :value="undefined">{{ t('nonprofitTax.choose') }}</option><option v-for="purpose in qualifiedPurposes" :key="purpose" :value="purpose">{{ t(`nonprofitTaxNative.purposes.${purpose}`) }}</option></select></label>
      <label v-if="source.kind === 'expense'" class="block text-sm">{{ t('nonprofitTax.deductibleBeforeFunding') }}<input v-model="model.deductible" :required="manual" inputmode="decimal" class="form-input mt-1 h-9 w-full" /></label>
      <label v-if="manual || source.kind === 'expense'" class="block text-sm">{{ t('nonprofitTax.legalBasis') }}<input v-model="model.legal_basis" :required="manual" class="form-input mt-1 h-9 w-full" /></label>
      <label v-for="fact in booleans" :key="fact" class="block text-sm">{{ t(`nonprofitTax.facts.${fact}`) }}<select v-model="model.facts[fact]" :required="manual" class="form-input mt-1 h-9 w-full"><option :value="undefined">{{ t('nonprofitTax.choose') }}</option><option :value="true">{{ t('nonprofitTax.yes') }}</option><option :value="false">{{ t('nonprofitTax.no') }}</option></select></label>
      <template v-if="!manual && model.title === 'gift' && model.facts.claim_exemption === false">
        <label class="block text-sm">{{ t('nonprofitTax.facts.income_treatment') }}<select v-model="model.facts.income_treatment" class="form-input mt-1 h-9 w-full"><option value="">{{ t('nonprofitTax.choose') }}</option><option value="nonbusiness_activity">{{ t('nonprofitTax.nonbusinessAttribution') }}</option><option value="separate_taxable_income">{{ t('nonprofitTax.separateAttribution') }}</option></select></label>
        <label class="block text-sm">{{ t('nonprofitTax.facts.income_attribution_evidence') }}<textarea :value="String(model.facts.income_attribution_evidence ?? '')" rows="2" class="form-input mt-1 w-full" @input="model.facts.income_attribution_evidence = ($event.target as HTMLTextAreaElement).value" /></label>
      </template>
      <label v-if="model.title === 'gift' && model.facts.claim_exemption === true" class="block text-sm">{{ t('nonprofitTax.facts.election_evidence') }}<textarea :value="String(model.facts.election_evidence ?? '')" rows="2" class="form-input mt-1 w-full" @input="model.facts.election_evidence = ($event.target as HTMLTextAreaElement).value" /></label>
      <template v-if="manual ? model.final_method === 'final_withholding' : model.title === 'bank_interest' && model.facts.foundation_endowment !== true">
        <label v-for="fact in ['gross_interest_cents', 'withholding_cents']" :key="fact" class="block text-sm">{{ t(`nonprofitTax.facts.${fact}`) }}<input v-model="model.facts[fact]" :required="manual" inputmode="decimal" class="form-input mt-1 h-9 w-full" /></label>
      </template>
    </div>
    <p v-if="source.mandatory_non_deductible" class="text-sm text-warning-700">{{ t('nonprofitTax.mandatoryExcluded') }}</p>
    <p v-if="!manual && model.title === 'gift'" class="text-sm text-warning-700">{{ t('nonprofitTax.giftHint') }}</p>
    <label class="block text-sm">{{ t('nonprofitTax.evidence') }}<textarea v-model="model.evidence" :required="manual" rows="2" maxlength="2000" class="form-input mt-1 w-full" /></label>
  </article>
</template>
