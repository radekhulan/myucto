<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
// Kontrola dat po převodu čte účetnictví (stejné právo jako její routa).
const canCheck = computed(() => auth.canRead('accounting'))

const systems = [
  { key: 'myucto', path: '/imports/myucto', name: 'MyÚčto / MyInvoice', category: 'accounting' },
  { key: 'money', path: '/imports/money-s3', name: 'Money S3', category: 'accounting' },
  { key: 'pohoda', path: '/imports/pohoda', name: 'POHODA', category: 'accounting' },
  { key: 'premier', path: '/imports/premier', name: 'PREMIER', category: 'accounting' },
  { key: 'stereo', path: '/imports/stereo-nx', name: 'Stereo NX', category: 'accounting' },
  { key: 'abra', path: '/imports/abra-flexi', name: 'ABRA Flexi', category: 'accounting' },
  { key: 'pamica', path: '/imports/pamica', name: 'PAMICA', category: 'payroll' },
]
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-8">
    <div class="relative overflow-hidden rounded-2xl border border-primary-200 bg-gradient-to-br from-primary-50 via-surface to-accent-50 px-6 py-8 sm:px-9 sm:py-10">
      <div class="pointer-events-none absolute -right-14 -top-20 h-56 w-56 rounded-full border-[28px] border-primary-100 opacity-60" aria-hidden="true" />
      <div class="mb-3 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h11m0 0-3-3m3 3-3 3M20 17H9m0 0 3-3m-3 3 3 3" />
        </svg>
      </div>
      <h1 class="relative text-2xl font-semibold text-neutral-900 sm:text-3xl">{{ t('migration_overview.title') }}</h1>
      <p class="relative mt-3 max-w-3xl text-sm leading-6 text-neutral-600 sm:text-base">{{ t('migration_overview.intro') }}</p>
    </div>

    <section aria-labelledby="migration-systems-title">
      <h2 id="migration-systems-title" class="text-lg font-semibold text-neutral-900">{{ t('migration_overview.systems_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('migration_overview.systems_hint') }}</p>
      <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-xl border border-warning-500/30 bg-warning-50 px-5 py-4 text-sm text-warning-700 sm:col-span-2 xl:col-span-3" data-testid="migration-overview-support-notice">
          <i18n-t keypath="migration_overview.support_notice" tag="p">
            <template #recommend><strong><i18n-t keypath="migration_overview.support_notice_recommend" tag="span"><template #contact><a href="https://myucto.cz/support#placena" target="_blank" rel="noopener" class="underline hover:no-underline">{{ t('migration_overview.support_notice_contact') }}</a></template></i18n-t></strong></template>
          </i18n-t>
          <RouterLink to="/admin/support" class="mt-1 inline-block font-medium underline hover:no-underline">{{ t('migration_overview.support_notice_link') }}</RouterLink>
        </div>
        <RouterLink v-for="system in systems" :key="system.key" :to="system.path"
          class="group flex min-h-52 flex-col rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm transition-all hover:-translate-y-1 hover:border-primary-400 hover:bg-primary-50 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600">
          <div class="flex items-start justify-between gap-3">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl" :class="system.category === 'payroll' ? 'bg-accent-50 text-accent-600' : 'bg-primary-100 text-primary-700'">
              <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path v-if="system.category === 'payroll'" stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9v-2a7 7 0 0 1 14 0v2H5z" />
                <path v-else stroke-linecap="round" stroke-linejoin="round" d="M7 3h8l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm8 0v5h4M9 12h6m-6 4h6" />
              </svg>
            </span>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="system.category === 'payroll' ? 'bg-accent-50 text-accent-700' : 'bg-primary-50 text-primary-700'">{{ t(`migration_overview.${system.category}_tag`) }}</span>
          </div>
          <h3 class="mt-5 text-lg font-semibold text-neutral-900">{{ system.name }}</h3>
          <p class="mt-1 flex-1 text-sm leading-5 text-neutral-600">{{ t(`migration_overview.${system.key}_hint`) }}</p>
          <span class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-primary-700">{{ t('migration_overview.open') }}<svg class="h-4 w-4 transition-transform group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg></span>
        </RouterLink>
        <a href="https://myucto.cz/#kontakt" target="_blank" rel="noopener" data-testid="migration-overview-custom"
          class="group flex min-h-52 flex-col rounded-xl border border-dashed border-neutral-300 bg-surface p-5 shadow-sm transition-all hover:-translate-y-1 hover:border-primary-400 hover:bg-primary-50 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600">
          <div class="flex items-start justify-between gap-3">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-warning-50 text-warning-700">
              <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m-9 6 3-3h11a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14z" />
              </svg>
            </span>
            <span class="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-700">{{ t('migration_overview.custom_tag') }}</span>
          </div>
          <h3 class="mt-5 text-lg font-semibold text-neutral-900">{{ t('migration_overview.custom_title') }}</h3>
          <p class="mt-1 flex-1 text-sm leading-5 text-neutral-600">{{ t('migration_overview.custom_hint') }}</p>
          <span class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-primary-700">{{ t('migration_overview.custom_contact') }}<svg class="h-4 w-4 transition-transform group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7m0 0H9m8 0v8" /></svg></span>
        </a>
        <RouterLink v-if="canCheck" :to="{ name: 'imports-checks' }" data-testid="migration-overview-checks"
          class="group flex min-h-52 flex-col rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm transition-all hover:-translate-y-1 hover:border-primary-400 hover:bg-primary-50 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600">
          <div class="flex items-start justify-between gap-3">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-success-50 text-success-700">
              <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4" />
              </svg>
            </span>
            <span class="rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700">{{ t('migration_overview.checks_tag') }}</span>
          </div>
          <h3 class="mt-5 text-lg font-semibold text-neutral-900">{{ t('migration_overview.checks_title') }}</h3>
          <p class="mt-1 flex-1 text-sm leading-5 text-neutral-600">{{ t('migration_overview.checks_hint') }}</p>
          <span class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-primary-700">{{ t('migration_overview.checks_open') }}<svg class="h-4 w-4 transition-transform group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg></span>
        </RouterLink>
      </div>
    </section>
  </div>
</template>
