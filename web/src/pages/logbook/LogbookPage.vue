<script setup lang="ts">
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIsPhone } from '@/composables/useIsPhone'
import { useRoute, useRouter } from 'vue-router'
import TripsTab from './TripsTab.vue'
import CarsTab from './CarsTab.vue'
import FuelingsTab from './FuelingsTab.vue'
import CategoriesTab from './CategoriesTab.vue'
import TemplatesTab from './TemplatesTab.vue'
import SummariesTab from './SummariesTab.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

type Tab = 'trips' | 'cars' | 'fuel' | 'templates' | 'categories' | 'summary'
const tabs: Tab[] = ['trips', 'cars', 'fuel', 'templates', 'categories', 'summary']
const tabLabelKey: Record<Tab, string> = {
  trips: 'logbook.tab_trips', cars: 'logbook.tab_cars', fuel: 'logbook.tab_fuel',
  templates: 'logbook.tab_templates', categories: 'logbook.tab_categories', summary: 'logbook.tab_summary',
}
const tab = ref<Tab>((tabs as string[]).includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'trips')

// Telefon: šest záložek se nevejde. Jízdy, Tankování a Auta jsou v segmentovém
// přepínači, zbytek ve výběru „Více". Desktop (i úzký panel) drží pruh záložek.
const isPhone = useIsPhone()
const phonePrimaryTabs: Tab[] = ['trips', 'fuel', 'cars']
const phoneMoreTabs = tabs.filter(v => !phonePrimaryTabs.includes(v))
const phoneMoreValue = computed(() => phoneMoreTabs.includes(tab.value) ? tab.value : '')
function onPhoneMore(e: Event) {
  const v = (e.target as HTMLSelectElement).value as Tab
  if (v) tab.value = v
}

watch(tab, (v) => {
  if (route.query.tab !== v) router.replace({ query: { ...route.query, tab: v } })
})

// Rychlé vytvoření z topbaru / „+" v menu přijde jako ?new=trip|fuel → přepni
// na správný tab a otevři modal nového záznamu. Každý tab poslouchá jen svůj
// token (žádné křížové otevírání). `new` z URL hned odstraníme, ať refresh /
// tlačítko zpět modal znovu neotevřou.
const openNewTripToken = ref(0)
const openNewFuelToken = ref(0)

async function handleNewQuery() {
  const n = route.query.new
  if (n !== 'trip' && n !== 'fuel') return
  tab.value = n === 'trip' ? 'trips' : 'fuel'
  const q = { ...route.query }; delete q.new
  await router.replace({ query: q })
  await nextTick()
  if (n === 'trip') openNewTripToken.value++
  else openNewFuelToken.value++
}
onMounted(handleNewQuery)

// Klik na menu „Kniha jízd" (= /logbook bez query) → reset filtrů na default,
// stejně jako přehled faktur. Záložky poslouchají na resetToken.
const resetToken = ref(0)
watch(() => route.query, (q) => {
  if (Object.keys(q).length === 0) {
    tab.value = 'trips'
    resetToken.value++
  } else {
    handleNewQuery()
  }
})
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('logbook.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('logbook.subtitle') }}</p>
    </div>

    <div v-if="isPhone" class="mb-4 space-y-2" data-test="logbook-phone-tabs">
      <div role="tablist" class="grid grid-cols-3 gap-1 rounded-lg bg-neutral-100 p-1">
        <button v-for="tt in phonePrimaryTabs" :key="tt" type="button" role="tab"
          :aria-selected="tab === tt" :data-test="`logbook-phone-tab-${tt}`"
          @click="tab = tt"
          class="cursor-pointer min-h-10 rounded-md px-1.5 py-1 text-[13px] leading-tight text-center transition"
          :class="tab === tt ? 'bg-surface text-primary-700 font-medium shadow-sm' : 'text-neutral-600'">
          {{ t(tabLabelKey[tt]) }}
        </button>
      </div>
      <label class="flex items-center gap-2 text-sm">
        <span class="text-neutral-500 whitespace-nowrap">{{ t('logbook.phone_tabs_more') }}</span>
        <select :value="phoneMoreValue" data-test="logbook-phone-more" @change="onPhoneMore"
          class="flex-1 min-w-0 h-10 rounded-md border bg-surface px-2 text-sm"
          :class="phoneMoreValue ? 'border-primary-500/60 text-primary-700 font-medium' : 'border-neutral-300 text-neutral-700'">
          <option value="" disabled>{{ t('logbook.phone_tabs_more_placeholder') }}</option>
          <option v-for="tt in phoneMoreTabs" :key="tt" :value="tt">{{ t(tabLabelKey[tt]) }}</option>
        </select>
      </label>
    </div>
    <div v-else class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in tabs" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t(tabLabelKey[tt]) }}
      </button>
    </div>

    <KeepAlive>
      <TripsTab v-if="tab === 'trips'" :reset-token="resetToken" :open-new-token="openNewTripToken" />
      <CarsTab v-else-if="tab === 'cars'" :reset-token="resetToken" />
      <FuelingsTab v-else-if="tab === 'fuel'" :reset-token="resetToken" :open-new-token="openNewFuelToken" />
      <TemplatesTab v-else-if="tab === 'templates'" :reset-token="resetToken" />
      <CategoriesTab v-else-if="tab === 'categories'" :reset-token="resetToken" />
      <SummariesTab v-else :reset-token="resetToken" />
    </KeepAlive>
  </div>
</template>
