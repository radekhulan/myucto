<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { SettlementPersonChange } from '@/api/propertySettlements'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import SettlementPeoplePicker from './SettlementPeoplePicker.vue'
import { settlementInput as input } from './settlementForm'
defineProps<{ supplier: number; labels?: Record<number, string> }>()
const model = defineModel<SettlementPersonChange>({ required: true }), { t } = useI18n()
</script>
<template><div class="space-y-3"><div class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.dossier.role') }}<select v-model="model.role" :class="input"><option v-for="v in ['service_recipient','creditor','debtor'] as const" :key="v" :value="v">{{ t(`propertySettlements.dossier.roles.${v}`) }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.dossier.changeBasis') }}<select v-model="model.basis" :class="input"><option v-for="v in ['agreement','assignment','inheritance','other'] as const" :key="v" :value="v">{{ t(`propertySettlements.dossier.bases.${v}`) }}</option></select></label></div><fieldset><legend>{{ t('propertySettlements.dossier.fromPersons') }}</legend><SettlementPeoplePicker v-model="model.from_ids" :supplier="supplier" :labels="labels" /></fieldset><fieldset><legend>{{ t('propertySettlements.dossier.toPersons') }}</legend><SettlementPeoplePicker v-model="model.to_ids" :supplier="supplier" :labels="labels" /></fieldset><PropertyInstantInput v-model="model.effective_at" :label="t('propertySettlements.dossier.effectiveAt')" required /><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.dossier.changeEvidence') }}<input v-model="model.evidence" :class="input" required /></label></div></template>
