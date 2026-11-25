<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useSupplierStore } from '@/stores/supplier'
import { useSupplierSwitch } from '@/composables/useSupplierSwitch'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

/**
 * Nahraná data průvodce převodem patří jiné firmě, než ve které uživatel pracuje
 * (žádná agenda nemá IČO aktuální firmy). Nahrání se ukládá k firmě, proto nestačí
 * „zkontrolovat IČO": je potřeba přepnout do firmy se stejným IČO a nahrát data tam.
 * Firmu se shodným IČO nabídne k přepnutí, chybí-li, odkáže na založení firmy.
 * Bez IČO firmy nebo bez agendy s IČO slot nese vlastní hlášku průvodce.
 */
const props = defineProps<{
  agendas: ReadonlyArray<{ ico: string; company?: string | null }>
  supplierIco: string
  testid: string
}>()
const { t } = useI18n()
const supplierStore = useSupplierStore()
const { switching, switchTo } = useSupplierSwitch()

function digits(value: string | null | undefined): string {
  return (value ?? '').replace(/\D/g, '')
}

const sources = computed(() => {
  const seen = new Map<string, string>()
  for (const a of props.agendas) {
    const ico = digits(a.ico)
    if (ico && ico !== digits(props.supplierIco) && !seen.has(ico)) seen.set(ico, a.company || '')
  }
  return [...seen].map(([ico, company]) => ({
    ico,
    company,
    target: supplierStore.availableSuppliers.find(s => s.id !== supplierStore.currentSupplierId && digits(s.ic) === ico) ?? null,
  }))
})
const current = computed(() => supplierStore.currentSupplier?.company_name ?? '')
</script>

<template>
  <div class="mb-5 space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert" :data-testid="testid">
    <slot v-if="!supplierIco || !sources.length" />
    <template v-else>
      <div v-for="s in sources" :key="s.ico" class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <span v-if="s.target">{{ t('migration_other_company.switch_hint', { company: s.company || s.target.company_name, ico: s.ico, current, ownIco: supplierIco }) }}</span>
        <span v-else>{{ t('migration_other_company.missing_hint', { company: s.company || '—', ico: s.ico, current, ownIco: supplierIco }) }}</span>
        <button v-if="s.target && supplierStore.hasMultiple" type="button" :class="btnOutlineSm('primary')" class="whitespace-nowrap" :disabled="switching"
          :data-testid="`${testid}-switch-${s.ico}`" @click="switchTo(s.target.id)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('migration_other_company.switch', { company: s.target.company_name }) }}
        </button>
        <RouterLink v-else-if="!s.target" :to="{ name: 'admin-suppliers' }":class="btnOutlineSm('primary')" class="whitespace-nowrap" :data-testid="`${testid}-create-${s.ico}`">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('migration_other_company.create') }}
        </RouterLink>
      </div>
    </template>
  </div>
</template>
