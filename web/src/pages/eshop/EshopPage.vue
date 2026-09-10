<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import WarehousesPage from '@/pages/stock/Warehouses.vue'
import ManufacturersPage from './Manufacturers.vue'
import CategoriesPage from './Categories.vue'
import AttributesPage from './Attributes.vue'
import TagsPage from './Tags.vue'
import FeeTypesPage from './FeeTypes.vue'
import LocalesPage from './Locales.vue'
import CurrenciesPage from './Currencies.vue'
import ProductImportPage from './ProductImport.vue'
import PricingRules from './PricingRules.vue'
import PriceMatrix from './PriceMatrix.vue'
import ProductMasters from './ProductMasters.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

type Tab = 'manufacturers' | 'categories' | 'attributes' | 'tags' | 'fees' | 'locales' | 'currencies' | 'warehouses' | 'import' | 'pricing' | 'price-matrix' | 'masters'
const tabs: { key: Tab; label: string }[] = [
  { key: 'masters',       label: t('eshop.masters.tab') },
  { key: 'manufacturers', label: t('nav.eshop_manufacturers') },
  { key: 'categories',   label: t('nav.eshop_categories') },
  { key: 'attributes',   label: t('nav.eshop_attributes') },
  { key: 'tags',         label: t('nav.eshop_tags') },
  { key: 'fees',         label: t('nav.eshop_fee_types') },
  { key: 'locales',      label: t('nav.eshop_locales') },
  { key: 'currencies',   label: t('nav.eshop_currencies') },
  { key: 'warehouses',   label: t('nav.stock_warehouses') },
  { key: 'import',       label: t('nav.eshop_import') },
  { key: 'pricing',      label: t('eshop.pricing.tab') },
  { key: 'price-matrix', label: t('eshop.price_matrix.tab') },
]
const keys = tabs.map(tt => tt.key) as string[]
const tab = ref<Tab>(keys.includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'manufacturers')

watch(tab, (v) => {
  if (route.query.tab !== v) router.replace({ query: { ...route.query, tab: v } })
})
watch(() => route.query.tab, (v) => {
  if (typeof v === 'string' && keys.includes(v) && v !== tab.value) tab.value = v as Tab
})
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('nav.section_eshop') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.page_subtitle') }}</p>
    </div>

    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in tabs" :key="tt.key"
        @click="tab = tt.key"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt.key
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt.label }}
      </button>
    </div>

    <KeepAlive>
      <WarehousesPage v-if="tab === 'warehouses'" />
      <ProductMasters v-else-if="tab === 'masters'" />
      <ManufacturersPage v-else-if="tab === 'manufacturers'" />
      <CategoriesPage v-else-if="tab === 'categories'" />
      <AttributesPage v-else-if="tab === 'attributes'" />
      <TagsPage v-else-if="tab === 'tags'" />
      <FeeTypesPage v-else-if="tab === 'fees'" />
      <LocalesPage v-else-if="tab === 'locales'" />
      <CurrenciesPage v-else-if="tab === 'currencies'" />
      <ProductImportPage v-else-if="tab === 'import'" />
      <PricingRules v-else-if="tab === 'pricing'" />
      <PriceMatrix v-else />
    </KeepAlive>
  </div>
</template>
