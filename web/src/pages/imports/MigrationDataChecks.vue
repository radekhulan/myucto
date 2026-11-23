<script setup lang="ts">
import { computed, onActivated, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { apiErrorMessage } from '@/api/errors'
import { migrationChecksApi, type MigrationCheckOverview, type MigrationCheckStatus } from '@/api/migrationChecks'
import { formatMoney } from '@/composables/useFormat'

const { t, te } = useI18n()

const overview = ref<MigrationCheckOverview | null>(null)
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    overview.value = await migrationChecksApi.overview()
  } catch (e) {
    error.value = apiErrorMessage(e, t('migrationChecks.load_failed'))
  } finally {
    loading.value = false
  }
}

let mounted = false
onMounted(() => { mounted = true; void load() })
onActivated(() => { if (mounted) void load() })

const checks = computed(() => overview.value?.checks ?? [])
const totals = computed(() => ({
  fixable: checks.value.filter(c => c.status === 'fixable').length,
  review: checks.value.filter(c => c.status === 'review' || c.status === 'error').length,
  ok: checks.value.filter(c => c.status === 'ok').length,
}))

const actions = computed<ActionItem[]>(() => [
  { key: 'refresh', label: t('migrationChecks.refresh'), icon: 'cycle', tier: 'secondary', variant: 'neutral', loading: loading.value, run: () => { void load() } },
  { key: 'wizards', label: t('migrationChecks.back_to_migrations'), icon: 'chevronLeft', tier: 'overflow', variant: 'neutral', to: { name: 'imports-overview' } },
])

function statusClass(status: MigrationCheckStatus): string {
  return status === 'ok' ? 'bg-success-50 text-success-700'
    : status === 'fixable' ? 'bg-warning-50 text-warning-700'
    : status === 'error' ? 'bg-danger-50 text-danger-700'
    : 'bg-primary-50 text-primary-700'
}

function checkTitle(key: string, fallback: string): string {
  return te(`migrationChecks.checks.${key}.title`) ? t(`migrationChecks.checks.${key}.title`) : fallback
}

function sourceLabel(source: string): string {
  return te(`migrationChecks.sources.${source}`) ? t(`migrationChecks.sources.${source}`) : source
}
</script>

<template>
  <div class="max-w-full" data-testid="migration-checks">
    <div class="flex items-start justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('migrationChecks.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5 max-w-3xl">{{ t('migrationChecks.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <p v-if="error" class="mb-4 rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700" data-testid="migration-checks-error">{{ error }}</p>

    <template v-if="overview">
      <EmptyState v-if="!overview.sources.length" boxed accent="neutral" icon="clipboardCheck"
        :title="t('migrationChecks.no_migration_title')" :message="t('migrationChecks.no_migration_hint')" data-testid="migration-checks-empty" />

      <template v-else>
        <div class="flex flex-wrap items-center gap-2 mb-4 text-sm">
          <span class="text-neutral-500">{{ t('migrationChecks.sources_label') }}:</span>
          <span v-for="s in overview.sources" :key="s" :data-testid="`source-${s}`" class="text-xs px-2 py-0.5 rounded font-medium bg-primary-50 text-primary-700">{{ sourceLabel(s) }}</span>
          <span class="ml-auto flex flex-wrap gap-x-4 gap-y-1 text-neutral-600">
            <span data-testid="total-fixable">{{ t('migrationChecks.total_fixable', { count: totals.fixable }) }}</span>
            <span>{{ t('migrationChecks.total_review', { count: totals.review }) }}</span>
            <span>{{ t('migrationChecks.total_ok', { count: totals.ok }) }}</span>
          </span>
        </div>

        <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('migrationChecks.col_check') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('migrationChecks.col_status') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('migrationChecks.col_count') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('migrationChecks.col_fixable') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('migrationChecks.col_amount') }}</th>
                <th class="px-3 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in checks" :key="c.key" class="align-top" :data-testid="`check-row-${c.key}`">
                <td class="px-3 py-2">
                  <RouterLink :to="{ name: 'imports-check-detail', params: { key: c.key } }" class="font-medium text-primary-700 hover:underline">{{ checkTitle(c.key, c.title) }}</RouterLink>
                  <div class="text-xs text-neutral-500">{{ t(`migrationChecks.checks.${c.key}.hint`) }}</div>
                  <ul v-if="c.notes.length" class="mt-1 text-xs text-warning-700 list-disc pl-4">
                    <li v-for="(n, i) in c.notes" :key="i">{{ n }}</li>
                  </ul>
                </td>
                <td class="px-3 py-2">
                  <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="statusClass(c.status)" :data-testid="`check-status-${c.key}`">{{ t(`migrationChecks.status.${c.status}`) }}</span>
                  <div v-if="!c.fixable && c.status !== 'ok'" class="mt-1 text-xs text-neutral-500">{{ t('migrationChecks.report_only') }}</div>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ c.count }}</td>
                <td class="px-3 py-2 text-right tabular-nums">
                  {{ c.fixable_count }}
                  <div v-if="c.locked_count" class="text-xs text-neutral-500 whitespace-nowrap">{{ t('migrationChecks.locked_count', { count: c.locked_count }) }}</div>
                </td>
                <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ c.count ? formatMoney(c.amount_czk) : '' }}</td>
                <td class="px-3 py-2 text-right">
                  <RouterLink :to="{ name: 'imports-check-detail', params: { key: c.key } }" class="whitespace-nowrap"
                    :class="btnOutlineSm(c.status === 'fixable' ? 'warning' : 'neutral')" :data-testid="`check-open-${c.key}`">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                    {{ t('migrationChecks.open_detail') }}
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="md:hidden space-y-3">
          <RouterLink v-for="c in checks" :key="c.key" :to="{ name: 'imports-check-detail', params: { key: c.key } }"
            class="block bg-surface border border-neutral-200 rounded-lg shadow-sm p-4" :data-testid="`check-card-${c.key}`">
            <div class="flex items-start justify-between gap-2">
              <span class="font-medium text-neutral-900">{{ checkTitle(c.key, c.title) }}</span>
              <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="statusClass(c.status)">{{ t(`migrationChecks.status.${c.status}`) }}</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-600">
              <span>{{ t('migrationChecks.col_count') }}: {{ c.count }}</span>
              <span>{{ t('migrationChecks.col_fixable') }}: {{ c.fixable_count }}</span>
              <span v-if="c.count">{{ formatMoney(c.amount_czk) }}</span>
            </div>
          </RouterLink>
        </div>
      </template>
    </template>
    <p v-else-if="loading" class="text-sm text-neutral-500">{{ t('migrationChecks.loading') }}</p>
  </div>
</template>
