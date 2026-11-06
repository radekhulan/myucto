<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import FrameworkConversionWorkspace from '@/components/accounting/FrameworkConversionWorkspace.vue'
const store = useSupplierStore()
const auth = useAuthStore()
const { t } = useI18n()
const supplier = computed(() => Number(store.currentSupplierId))
// SVJ nepřevádí z hlavní knihy: převod 500 → 504 pro něj backend odmítá
// (conversion_svj_adoption_required), proto stránka místo formuláře vysvětlí postup.
const svj = computed(() => store.currentSupplier?.entity_profile === 'svj')
</script>

<template>
  <section v-if="svj" class="space-y-5" data-section="svj-guide">
    <header>
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('frameworkConversion.svjGuide.title') }}</h1>
      <p class="text-sm text-neutral-500">{{ t('frameworkConversion.svjGuide.why') }}</p>
    </header>
    <div class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-5 text-sm text-neutral-700 shadow-sm">
      <p>{{ t('frameworkConversion.svj') }}</p>
      <h2 class="font-semibold text-neutral-900">{{ t('frameworkConversion.svjGuide.how') }}</h2>
      <ol class="list-decimal space-y-2 pl-5">
        <li>{{ t('frameworkConversion.svjGuide.newSvj') }}</li>
        <li>{{ t('frameworkConversion.svjGuide.existingSvj') }}</li>
      </ol>
      <div class="flex flex-wrap items-center gap-3 pt-2">
        <RouterLink
          v-if="auth.can('property')"
          :to="{ name: 'property-openings' }"
          :class="[btnOutline('primary'), 'whitespace-nowrap']"
          data-test="svj-openings-link"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          {{ t('frameworkConversion.svjGuide.openingsLink') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-settings' }"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          data-test="svj-settings-link"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t('frameworkConversion.svjGuide.settingsLink') }}
        </RouterLink>
      </div>
    </div>
  </section>
  <FrameworkConversionWorkspace v-else-if="supplier" :key="supplier" :supplier="supplier" />
</template>
