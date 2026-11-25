<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { ICONS } from '@/components/ui/buttonStyles'
import FundingWorkspace from '@/components/accounting/funding/FundingWorkspace.vue'
import FundingWorkPanel from '@/components/accounting/funding/FundingWorkPanel.vue'
const { t } = useI18n()
const route = useRoute(), router = useRouter()
const supplier = useSupplierStore(), auth = useAuthStore()
const id = computed(() => Number(supplier.currentSupplierId))
const canManage = computed(() => auth.can('accounting.periods.close', 'write'))
// Pracovní evidenci mění zápisové právo účetnictví jen z přihlášené relace (RequestAuthorization::canManageNonprofitFundingWork).
const canManageWork = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('accounting', 'write')))
type Tab = 'work' | 'confirmed'
const tabs = computed(() => [
 { key: 'work' as const, label: t('nonprofitFunding.tabs.work'), icon: ICONS.table },
 { key: 'confirmed' as const, label: t('nonprofitFunding.tabs.confirmed'), icon: ICONS.badgeCheck },
])
const tab = computed<Tab>(() => route.query.tab === 'confirmed' ? 'confirmed' : 'work')
// Záložky přes v-show (vzor Členské příspěvky): rozpracovaný stav přežije přepnutí; J6 se načte až při první návštěvě.
const visited = ref<Record<Tab, boolean>>({ work: false, confirmed: false })
const workPanel = ref<{ reload: () => Promise<void> } | null>(null)
watch(tab, (value, previous) => {
 // Návrat na průběžné čerpání načte data znovu: v J6 se mohl změnit potvrzený zdroj (upozornění na souběh).
 if (value === 'work' && previous === 'confirmed' && visited.value.work) void workPanel.value?.reload()
 visited.value[value] = true
}, { immediate: true })
function setTab(next: Tab) { if (tab.value !== next) void router.replace({ query: { ...route.query, tab: next === 'work' ? undefined : next } }) }
function onTabKey(event: KeyboardEvent) {
 if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
 event.preventDefault()
 setTab(tab.value === 'work' ? 'confirmed' : 'work')
 const host = (event.currentTarget as HTMLElement | null)?.parentElement
 requestAnimationFrame(() => host?.querySelector<HTMLElement>('[aria-selected="true"]')?.focus())
}
</script>
<template>
 <div v-if="id" :key="id">
  <h1 class="text-xl font-semibold">{{ t('nonprofitFunding.title') }}</h1>
  <!-- Přepínač agend je záložka, ne tlačítko (vzor Členské příspěvky). -->
  <div role="tablist" class="mb-4 mt-4 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-neutral-200" :aria-label="t('nonprofitFunding.title')" data-section="funding-tabs">
   <button v-for="item in tabs" :id="`funding-tab-${item.key}`" :key="item.key" type="button" role="tab" :data-tab="item.key" :aria-controls="`funding-panel-${item.key}`"
    :aria-selected="tab === item.key" :tabindex="tab === item.key ? 0 : -1"
    class="-mb-px inline-flex cursor-pointer items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm transition-colors"
    :class="tab === item.key ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
    @click="setTab(item.key)" @keydown="onTabKey">
    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" /></svg>{{ item.label }}
   </button>
  </div>
  <section v-show="tab === 'work'" id="funding-panel-work" role="tabpanel" aria-labelledby="funding-tab-work" data-panel="work"><FundingWorkPanel v-if="visited.work" ref="workPanel" :supplier="id" :can-manage="canManageWork" /></section>
  <section v-show="tab === 'confirmed'" id="funding-panel-confirmed" role="tabpanel" aria-labelledby="funding-tab-confirmed" data-panel="confirmed"><FundingWorkspace v-if="visited.confirmed" :supplier="id" :can-manage="canManage" embedded /></section>
 </div>
</template>
