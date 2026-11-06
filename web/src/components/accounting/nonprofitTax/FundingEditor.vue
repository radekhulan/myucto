<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TaxSnapshot } from '@/api/nonprofitTax'
import type { AssessmentDraft, PoolDraft } from '@/utils/nonprofitTaxDraft'
import { centsText } from '@/utils/nonprofitTaxDraft'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
const props = defineProps<{ snapshot: TaxSnapshot }>()
const model = defineModel<AssessmentDraft>({ required: true })
const { t } = useI18n()
const sources = computed(() => Object.values({ ...props.snapshot.funding_reference_sources, ...Object.fromEntries(props.snapshot.sources.map(row => [row.id, row])) }))
const candidates = (kind: 'revenue' | 'expense') => sources.value.filter(row => row.kind === kind && (kind === 'expense' || ['gift', 'public_operating_grant', 'public_investment_grant'].includes(model.value.decisions[row.id]?.title ?? ('decision' in row ? row.decision.title : ''))))
const fundingAnchors = computed(() => candidates('revenue').filter(row => row.amount_cents > 0 && (props.snapshot.sources.some(current => current.id === row.id) || model.value.funding_sources.some(pool => pool.anchor === row.id))))
function addPool(kind: 'revenue' | 'expense') {
  const pool: PoolDraft = { anchor: '', members: [], evidence: '', review: false, reviewEvidence: '', reviewBasis: '', reviewAmount: '' }
  model.value[kind === 'revenue' ? 'funding_sources' : 'funding_costs'].push(pool)
}
function anchorSelected(pool: PoolDraft) { if (pool.anchor && !pool.members.includes(pool.anchor)) pool.members.push(pool.anchor) }
</script>
<template>
  <section class="space-y-4">
    <h2 class="text-lg font-semibold">{{ t('nonprofitTax.funding') }}</h2>
    <p class="text-sm text-neutral-600">{{ t('nonprofitTax.fundingHint') }}</p>
    <article v-for="(link, index) in model.funding" :key="index" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3">
      <div class="grid gap-3 md:grid-cols-2">
        <label class="text-sm">{{ t('nonprofitTax.fundingSource') }}<select v-model="link.source_id" class="form-input mt-1 h-9 w-full"><option value="">{{ t('nonprofitTax.choose') }}</option><option v-for="row in fundingAnchors" :key="row.id" :value="row.id">{{ row.date }} · {{ row.account_code }} · {{ row.id }} · {{ centsText(row.amount_cents) }}</option></select></label>
        <label class="text-sm">{{ t('nonprofitTax.fundedCost') }}<select v-model="link.cost_id" class="form-input mt-1 h-9 w-full"><option value="">{{ t('nonprofitTax.choose') }}</option><option v-for="row in snapshot.sources.filter(row => row.kind === 'expense' && row.amount_cents > 0)" :key="row.id" :value="row.id">{{ row.account_code }} · {{ row.id }} · {{ centsText(row.amount_cents) }}</option></select></label>
        <label class="text-sm">{{ t('nonprofitTax.drawdown') }}<input v-model="link.amount" inputmode="decimal" class="form-input mt-1 h-9 w-full" /></label>
        <label class="text-sm">{{ t('nonprofitTax.overlap') }}<input v-model="link.overlap" inputmode="decimal" class="form-input mt-1 h-9 w-full" /></label>
        <label class="text-sm md:col-span-2">{{ t('nonprofitTax.evidence') }}<textarea v-model="link.evidence" rows="2" class="form-input mt-1 w-full" /></label>
      </div>
      <button type="button" :class="btnOutline('danger')" @click="model.funding.splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTax.remove') }}</button>
    </article>
    <button type="button" :class="btnOutline('neutral')" @click="model.funding.push({ source_id: '', cost_id: '', amount: '', evidence: '', overlap: '' })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('nonprofitTax.addFunding') }}</button>
    <div v-for="kind in (['revenue', 'expense'] as const)" :key="kind" class="space-y-3">
      <h3 class="font-semibold">{{ t(kind === 'revenue' ? 'nonprofitTax.sourcePools' : 'nonprofitTax.costPools') }}</h3>
      <article v-for="(pool, index) in model[kind === 'revenue' ? 'funding_sources' : 'funding_costs']" :key="index" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3">
        <label class="block text-sm">{{ t('nonprofitTax.poolAnchor') }}<select v-model="pool.anchor" class="form-input mt-1 h-9 w-full" @change="anchorSelected(pool)"><option value="">{{ t('nonprofitTax.choose') }}</option><option v-for="row in candidates(kind).filter(row => row.amount_cents > 0)" :key="row.id" :value="row.id">{{ row.date }} · {{ row.id }} · {{ centsText(row.amount_cents) }}</option></select></label>
        <fieldset class="space-y-2"><legend class="text-sm font-medium">{{ t('nonprofitTax.poolMembers') }}</legend><label v-for="row in candidates(kind)" :key="row.id" class="flex items-start gap-2 text-sm"><input v-model="pool.members" type="checkbox" :value="row.id" class="mt-1" /><span>{{ row.date }} · {{ row.id }} · {{ centsText(row.amount_cents) }} {{ t('nonprofitTax.czk') }}</span></label></fieldset>
        <label class="block text-sm">{{ t('nonprofitTax.poolEvidence') }}<textarea v-model="pool.evidence" rows="2" class="form-input mt-1 w-full" /></label>
        <template v-if="kind === 'revenue'">
          <label class="flex items-center gap-2 text-sm"><input v-model="pool.review" type="checkbox" />{{ t('nonprofitTax.priorReview') }}</label>
          <div v-if="pool.review" class="grid gap-3 rounded-md bg-warning-50 p-3 md:grid-cols-2">
            <p class="text-sm text-warning-700 md:col-span-2">{{ t('nonprofitTax.externalHint') }}</p>
            <label class="text-sm">{{ t('nonprofitTax.legalBasis') }}<input v-model="pool.reviewBasis" class="form-input mt-1 h-9 w-full" /></label>
            <label class="text-sm">{{ t('nonprofitTax.documentedAdjustment') }}<input v-model="pool.reviewAmount" inputmode="decimal" class="form-input mt-1 h-9 w-full" /></label>
            <label class="text-sm md:col-span-2">{{ t('nonprofitTax.evidence') }}<textarea v-model="pool.reviewEvidence" rows="2" class="form-input mt-1 w-full" /></label>
          </div>
        </template>
        <button type="button" :class="btnOutline('danger')" @click="model[kind === 'revenue' ? 'funding_sources' : 'funding_costs'].splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTax.remove') }}</button>
      </article>
      <button type="button" :class="btnOutline('neutral')" @click="addPool(kind)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('nonprofitTax.addPool') }}</button>
    </div>
  </section>
</template>
