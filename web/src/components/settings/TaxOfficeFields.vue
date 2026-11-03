<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type TaxOffice, type TaxOfficeAccountKind, type TaxOfficeWorkplace } from '@/api/settings'

// Výběr finančního úřadu a územního pracoviště z číselníku finanční správy.
// Hodnoty jsou kódy z EPO (c_ufo, c_pracufo); kód, který v číselníku není
// (starší data), zůstane vybraný a jen se označí.
const office = defineModel<string | null | undefined>('office')
const workplace = defineModel<string | null | undefined>('workplace')

const { t } = useI18n()

const offices = ref<TaxOffice[]>([])
const workplaces = ref<TaxOfficeWorkplace[]>([])
const loadFailed = ref(false)

onMounted(async () => {
  try {
    const catalog = await settingsApi.taxOffices()
    offices.value = catalog.offices
    workplaces.value = catalog.workplaces
  } catch {
    loadFailed.value = true
  }
})

const normalize = (code: string | null | undefined) => (code ?? '').trim().replace(/^0+(?=\d)/, '')

const officeCode = computed(() => normalize(office.value))
const workplaceCode = computed(() => (workplace.value ?? '').trim())
const selectedOffice = computed(() => offices.value.find(o => o.code === officeCode.value) ?? null)
const officeWorkplaces = computed(() => workplaces.value.filter(w => w.office_code === officeCode.value))
const unknownOffice = computed(() => officeCode.value !== '' && offices.value.length > 0 && !selectedOffice.value)
const unknownWorkplace = computed(() => workplaceCode.value !== '' && workplaces.value.length > 0
  && !officeWorkplaces.value.some(w => w.code === workplaceCode.value))

const ACCOUNT_KINDS: TaxOfficeAccountKind[] = ['vat', 'dppo', 'dpfo', 'employment', 'withholding']
const accounts = computed(() => ACCOUNT_KINDS
  .map(kind => ({ kind, account: selectedOffice.value?.accounts[kind] ?? null }))
  .filter((row): row is { kind: TaxOfficeAccountKind; account: string } => row.account !== null))

function selectOffice(code: string) {
  office.value = code === '' ? null : code
  // Pracoviště jiného úřadu by v podání neprošlo, proto se vyprázdní.
  if (!workplaces.value.some(w => w.code === workplaceCode.value && w.office_code === code)) workplace.value = null
}

function selectWorkplace(code: string) {
  workplace.value = code === '' ? null : code
}
</script>

<template>
  <div>
    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.financial_office_code') }}</label>
    <select v-if="!loadFailed" data-test="tax-office-select" :value="officeCode" @change="selectOffice(($event.target as HTMLSelectElement).value)"
      class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
      <option value="">{{ t('settings.tax_office.none') }}</option>
      <option v-if="unknownOffice" :value="officeCode">{{ t('settings.tax_office.unknown', { code: officeCode }) }}</option>
      <option v-for="o in offices" :key="o.code" :value="o.code">{{ o.code }} · {{ o.name }}</option>
    </select>
    <input v-else v-model="office" type="text" maxlength="8" placeholder="451"
      class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
    <p class="text-xs text-neutral-500 mt-1">{{ t('settings.financial_office_hint') }}</p>
  </div>
  <div>
    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.workplace_code') }}</label>
    <select v-if="!loadFailed" data-test="tax-workplace-select" :value="workplaceCode" :disabled="officeWorkplaces.length === 0 && !unknownWorkplace"
      @change="selectWorkplace(($event.target as HTMLSelectElement).value)"
      class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-60">
      <option value="">{{ officeCode === '' ? t('settings.tax_office.pick_office_first') : t('settings.tax_office.none') }}</option>
      <option v-if="unknownWorkplace" :value="workplaceCode">{{ t('settings.tax_office.unknown', { code: workplaceCode }) }}</option>
      <option v-for="w in officeWorkplaces" :key="w.code" :value="w.code">{{ w.code }} · {{ w.name }}</option>
    </select>
    <input v-else v-model="workplace" type="text" maxlength="8"
      class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
    <p v-if="officeCode === '13'" class="text-xs text-neutral-500 mt-1">{{ t('settings.tax_office.specialized_hint') }}</p>
  </div>
  <div v-if="selectedOffice && (selectedOffice.data_box || accounts.length)" data-test="tax-office-detail"
    class="md:col-span-2 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-700">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
      <div v-if="selectedOffice.data_box" class="flex justify-between gap-2">
        <span class="text-neutral-500">{{ t('settings.tax_office.data_box') }}</span>
        <span class="font-mono">{{ selectedOffice.data_box }}</span>
      </div>
      <div v-for="row in accounts" :key="row.kind" class="flex justify-between gap-2">
        <span class="text-neutral-500">{{ t(`settings.tax_office.account_${row.kind}`) }}</span>
        <span class="font-mono whitespace-nowrap">{{ row.account }}</span>
      </div>
    </div>
  </div>
</template>
