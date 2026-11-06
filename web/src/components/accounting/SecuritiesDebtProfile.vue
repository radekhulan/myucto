<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import DateInput from '@/components/ui/DateInput.vue'
import type { SecuritiesProfile } from '@/api/securities504'
defineProps<{ model: SecuritiesProfile; disabled: boolean }>()
const { t } = useI18n()
const exceptions = ['none', 'securities_dealer', 'foreign_securities_dealer_branch', 'payment_institution', 'foreign_payment_institution_branch', 'electronic_money_institution', 'foreign_electronic_money_institution_branch', 'investment_company', 'investment_fund', 'pension_fund']
</script>
<template>
 <fieldset :disabled="disabled" class="space-y-3 rounded border p-3"><legend>{{ t('securities504.profile') }}</legend>
 <div class="grid gap-3 sm:grid-cols-2"><label v-for="field in (['scope','category','micro_exception'] as const)" :key="field">{{ t(`securities504.${field}`) }}<select v-model="model[field]" required class="block w-full rounded border p-2" :data-field="field"><option value="">{{ t('securities504.choose') }}</option><option v-for="v in field === 'scope' ? ['full','simplified'] : field === 'category' ? ['micro','small','medium','large'] : exceptions" :key="v" :value="v">{{ t(`securities504.options.${v}`) }}</option></select></label>
 <label v-for="field in (['reference','scope_reference','inception_reference'] as const)" :key="field">{{ t(`securities504.${field}`) }}<input v-model="model[field]" required maxlength="4000" class="block w-full rounded border p-2" /></label><label>{{ t('securities504.first_period_start') }}<DateInput v-model="model.first_period_start" required :disabled="disabled" /></label></div>
 <div v-if="model.micro_exception && model.micro_exception !== 'none'" class="grid gap-3 sm:grid-cols-2"><label>{{ t('securities504.registration_reference') }}<input v-model="model.registration_reference" required maxlength="4000" class="block w-full rounded border p-2" /></label><label>{{ t('securities504.registration_from') }}<DateInput v-model="model.registration_from" required :disabled="disabled" /></label></div>
 <article v-for="fact in model.category_facts" :key="fact.period_id" class="rounded border p-3"><h3>{{ fact.starts_on }} - {{ fact.ends_on }}</h3><div class="grid gap-3 sm:grid-cols-2"><label v-for="field in (['assets_net','net_turnover','average_employees','statement_reference','employees_reference'] as const)" :key="field">{{ t(`securities504.${field}`) }}<input v-model="fact[field]" required class="block w-full rounded border p-2" /></label></div></article>
 </fieldset>
</template>
