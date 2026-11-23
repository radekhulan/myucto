<script setup lang="ts">
import { computed, onActivated, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { apiErrorMessage } from '@/api/errors'
import {
  migrationCheckItemLink, migrationChecksApi,
  type MigrationCheckDetail, type MigrationCheckItem, type MigrationCheckItemState, type MigrationCheckPreview,
} from '@/api/migrationChecks'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const { t, te } = useI18n()
const route = useRoute()
const toast = useToast()

const PER_PAGE = 50
const key = computed(() => String(route.params.key ?? ''))
const detail = ref<MigrationCheckDetail | null>(null)
const loading = ref(false)
const error = ref('')
const page = ref(1)
const state = ref<MigrationCheckItemState | ''>('')

const previewOpen = ref(false)
const preview = ref<MigrationCheckPreview | null>(null)
const previewLoading = ref(false)
const previewError = ref('')
const fixing = ref(false)
const options = reactive<Record<string, boolean>>({})

async function load() {
  if (!key.value) return
  loading.value = true
  error.value = ''
  try {
    detail.value = await migrationChecksApi.detail(key.value, { page: page.value, per_page: PER_PAGE, ...(state.value ? { state: state.value } : {}) })
  } catch (e) {
    error.value = apiErrorMessage(e, t('migrationChecks.load_failed'))
  } finally {
    loading.value = false
  }
}

let mounted = false
onMounted(() => { mounted = true; void load() })
onActivated(() => { if (mounted) void load() })
watch(key, () => { page.value = 1; state.value = ''; void load() })
watch(page, () => { void load() })

function setState(value: MigrationCheckItemState | '') {
  state.value = value
  if (page.value === 1) void load()
  else page.value = 1
}

const title = computed(() => detail.value
  ? (te(`migrationChecks.checks.${detail.value.key}.title`) ? t(`migrationChecks.checks.${detail.value.key}.title`) : detail.value.title)
  : t('migrationChecks.title'))
// Manuál je samostatná stránka mimo SPA (manual/index.php), router by ho nenašel.
const manualHref = computed(() => {
  if (!detail.value?.manual) return null
  const [chapter, anchor] = detail.value.manual.split('#')
  return `/manual?ch=${encodeURIComponent(chapter)}${anchor ? `#${anchor}` : ''}`
})
const canFix = computed(() => !!detail.value && detail.value.fixable && detail.value.can_fix && detail.value.fixable_count > 0)

async function openPreview() {
  if (!detail.value) return
  previewOpen.value = true
  await loadPreview()
}

async function loadPreview() {
  previewLoading.value = true
  previewError.value = ''
  try {
    preview.value = await migrationChecksApi.preview(key.value, { ...options })
  } catch (e) {
    previewError.value = apiErrorMessage(e, t('migrationChecks.preview_failed'))
  } finally {
    previewLoading.value = false
  }
}

async function runFix() {
  if (!preview.value?.can_fix) return
  fixing.value = true
  try {
    const result = await migrationChecksApi.fix(key.value, preview.value.fingerprint, { ...options })
    toast.success(t('migrationChecks.fix_done', { count: result.fixed_count, amount: formatMoney(result.amount_czk) }))
    for (const note of result.notes) toast.success(note)
    previewOpen.value = false
    preview.value = null
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('migrationChecks.fix_failed')))
    await loadPreview()
  } finally {
    fixing.value = false
  }
}

function toggleOption(name: string, value: boolean) {
  options[name] = value
  void loadPreview()
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'fix', label: t('migrationChecks.fix'), icon: 'check', tier: 'primary', variant: 'warning',
    show: detail.value?.fixable && detail.value?.can_fix, disabled: !canFix.value,
    disabledReason: t('migrationChecks.fix_nothing'), run: () => { void openPreview() },
  },
  { key: 'refresh', label: t('migrationChecks.refresh'), icon: 'cycle', tier: 'secondary', variant: 'neutral', loading: loading.value, run: () => { void load() } },
  { key: 'manual', label: t('migrationChecks.manual'), icon: 'help', tier: 'secondary', variant: 'neutral', show: !!manualHref.value, href: manualHref.value ?? undefined },
  { key: 'back', label: t('migrationChecks.back'), icon: 'chevronLeft', tier: 'overflow', variant: 'neutral', to: { name: 'imports-checks' } },
])

function stateClass(s: MigrationCheckItemState | string): string {
  return s === 'fixable' ? 'bg-warning-50 text-warning-700'
    : s === 'locked' ? 'bg-neutral-100 text-neutral-700'
    : s === 'ok' ? 'bg-success-50 text-success-700'
    : s === 'error' ? 'bg-danger-50 text-danger-700'
    : 'bg-primary-50 text-primary-700'
}

function amountOf(i: MigrationCheckItem): string {
  if (i.amount_czk === null) return ''
  const czk = formatMoney(i.amount_czk)
  return i.currency && i.currency !== 'CZK' && i.amount !== null ? `${formatMoney(i.amount, i.currency)} (${czk})` : czk
}

const filters: { value: MigrationCheckItemState | ''; label: string }[] = [
  { value: '', label: 'migrationChecks.filter_all' },
  { value: 'fixable', label: 'migrationChecks.filter_fixable' },
  { value: 'locked', label: 'migrationChecks.filter_locked' },
  { value: 'review', label: 'migrationChecks.filter_review' },
]
</script>

<template>
  <div class="max-w-full" data-testid="migration-check-detail">
    <div class="flex items-start justify-between mb-4 gap-3 flex-wrap">
      <div class="min-w-0">
        <RouterLink :to="{ name: 'imports-checks' }" class="text-sm text-primary-700 hover:underline">{{ t('migrationChecks.back') }}</RouterLink>
        <h1 class="text-2xl font-semibold mt-1 flex flex-wrap items-center gap-2">
          {{ title }}
          <span v-if="detail" class="text-xs px-2 py-0.5 rounded font-medium" :class="stateClass(detail.status)" data-testid="detail-status">{{ t(`migrationChecks.status.${detail.status}`) }}</span>
        </h1>
        <p v-if="detail" class="text-sm text-neutral-500 mt-0.5 max-w-3xl">{{ t(`migrationChecks.checks.${detail.key}.description`) }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <p v-if="error" class="mb-4 rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>

    <template v-if="detail">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4">
        <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-neutral-700">
          <span>{{ t('migrationChecks.col_count') }}: <strong>{{ detail.count }}</strong></span>
          <span>{{ t('migrationChecks.col_fixable') }}: <strong>{{ detail.fixable_count }}</strong> ({{ formatMoney(detail.fixable_amount_czk) }})</span>
          <span>{{ t('migrationChecks.state.locked') }}: <strong>{{ detail.locked_count }}</strong></span>
          <span>{{ t('migrationChecks.state.review') }}: <strong>{{ detail.review_count }}</strong></span>
          <span>{{ t('migrationChecks.col_amount') }}: <strong>{{ formatMoney(detail.amount_czk) }}</strong></span>
        </div>
        <p v-if="!detail.fixable" class="mt-2 text-sm text-neutral-600" data-testid="report-only">{{ t('migrationChecks.report_only_hint') }}</p>
        <p v-else-if="!detail.can_fix && detail.fixable_count" class="mt-2 text-sm text-neutral-600" data-testid="no-fix-right">{{ t('migrationChecks.no_fix_right') }}</p>
        <ul v-if="detail.notes.length" class="mt-2 text-sm text-warning-700 list-disc pl-5">
          <li v-for="(n, i) in detail.notes" :key="i">{{ n }}</li>
        </ul>
      </div>

      <div class="flex flex-wrap gap-2 mb-3">
        <button v-for="f in filters" :key="f.value" type="button" class="whitespace-nowrap"
          :class="state === f.value ? btnFilled('primary') : btnOutline('neutral')" :data-testid="`filter-${f.value || 'all'}`" @click="setState(f.value)">
          {{ t(f.label) }}
        </button>
      </div>

      <EmptyState v-if="!detail.items.length" boxed accent="success" icon="checkCircle" :title="t('migrationChecks.no_findings')" />
      <template v-else>
        <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('migrationChecks.col_document') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('migrationChecks.col_date') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('migrationChecks.col_amount') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('migrationChecks.col_state') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('migrationChecks.col_reason') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(i, idx) in detail.items" :key="`${i.entity_type}-${i.entity_id ?? idx}-${idx}`" class="align-top" data-testid="finding-row">
                <td class="px-3 py-2">
                  <RouterLink v-if="migrationCheckItemLink(i)" :to="migrationCheckItemLink(i)!" class="font-medium text-primary-700 hover:underline">{{ i.label }}</RouterLink>
                  <span v-else class="font-medium">{{ i.label }}</span>
                  <div class="text-xs text-neutral-500">{{ t(`migrationChecks.entity.${i.entity_type}`) }}</div>
                </td>
                <td class="px-3 py-2 whitespace-nowrap">{{ i.date ? formatDate(i.date) : '' }}</td>
                <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ amountOf(i) }}</td>
                <td class="px-3 py-2"><span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="stateClass(i.state)">{{ t(`migrationChecks.state.${i.state}`) }}</span></td>
                <td class="px-3 py-2 text-xs text-neutral-600 max-w-xl">{{ i.reason }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="md:hidden divide-y divide-neutral-100 bg-surface border border-neutral-200 rounded-lg shadow-sm">
          <div v-for="(i, idx) in detail.items" :key="`m-${i.entity_type}-${i.entity_id ?? idx}-${idx}`" class="p-3 text-sm">
            <div class="flex items-start justify-between gap-2">
              <RouterLink v-if="migrationCheckItemLink(i)" :to="migrationCheckItemLink(i)!" class="font-medium text-primary-700">{{ i.label }}</RouterLink>
              <span v-else class="font-medium">{{ i.label }}</span>
              <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="stateClass(i.state)">{{ t(`migrationChecks.state.${i.state}`) }}</span>
            </div>
            <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-neutral-500">
              <span v-if="i.date">{{ formatDate(i.date) }}</span>
              <span>{{ amountOf(i) }}</span>
            </div>
            <p class="mt-1 text-xs text-neutral-600">{{ i.reason }}</p>
          </div>
        </div>
        <PaginationBar v-model:page="page" :per-page="detail.pagination.per_page" :total="detail.pagination.total" />
      </template>
    </template>
    <p v-else-if="loading" class="text-sm text-neutral-500">{{ t('migrationChecks.loading') }}</p>

    <Modal v-if="previewOpen" :title="t('migrationChecks.preview_title', { check: title })" width-class="max-w-4xl" @close="previewOpen = false">
      <div class="space-y-3 text-sm" data-testid="fix-preview">
        <p v-if="previewLoading" class="text-neutral-500">{{ t('migrationChecks.loading') }}</p>
        <p v-if="previewError" class="rounded border border-danger-200 bg-danger-50 px-3 py-2 text-danger-700">{{ previewError }}</p>
        <template v-if="preview && !previewLoading">
          <label v-for="o in detail?.options ?? []" :key="o.key" class="flex items-start gap-2 rounded border border-neutral-200 p-3">
            <input type="checkbox" class="mt-0.5" :checked="!!options[o.key]" :data-testid="`option-${o.key}`"
              @change="toggleOption(o.key, ($event.target as HTMLInputElement).checked)" />
            <span>{{ te(`migrationChecks.options.${o.key}`) ? t(`migrationChecks.options.${o.key}`) : o.label }}</span>
          </label>
          <p class="font-medium" data-testid="preview-summary">{{ t('migrationChecks.preview_summary', { count: preview.count, amount: formatMoney(preview.amount_czk), skipped: preview.skipped.length }) }}</p>
          <p v-if="preview.blocked" class="rounded border border-warning-200 bg-warning-50 px-3 py-2 text-warning-700">{{ preview.blocked }}</p>
          <ul v-if="preview.notes.length" class="list-disc pl-5 text-neutral-600">
            <li v-for="(n, i) in preview.notes" :key="i">{{ n }}</li>
          </ul>
          <div v-if="preview.changes.length" class="max-h-80 overflow-y-auto border border-neutral-200 rounded">
            <table class="w-full text-xs">
              <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide sticky top-0">
                <tr>
                  <th class="px-2 py-1.5 text-left font-medium">{{ t('migrationChecks.col_document') }}</th>
                  <th class="px-2 py-1.5 text-left font-medium">{{ t('migrationChecks.col_date') }}</th>
                  <th class="px-2 py-1.5 text-right font-medium">{{ t('migrationChecks.col_amount') }}</th>
                  <th class="px-2 py-1.5 text-left font-medium">{{ t('migrationChecks.col_change') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(c, idx) in preview.changes" :key="`p-${c.entity_id ?? idx}-${idx}`" class="align-top">
                  <td class="px-2 py-1.5 font-medium">{{ c.label }}</td>
                  <td class="px-2 py-1.5 whitespace-nowrap">{{ c.date ? formatDate(c.date) : '' }}</td>
                  <td class="px-2 py-1.5 text-right tabular-nums whitespace-nowrap">{{ amountOf(c) }}</td>
                  <td class="px-2 py-1.5 text-neutral-600">{{ c.reason }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-neutral-600">{{ t('migrationChecks.preview_audit') }}</p>
        </template>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" data-testid="preview-cancel" @click="previewOpen = false">{{ t('migrationChecks.cancel') }}</button>
          <button type="button" class="whitespace-nowrap inline-flex items-center gap-1.5" :class="btnFilled('warning')" data-testid="preview-confirm"
            :disabled="!preview?.can_fix || fixing || previewLoading" @click="runFix">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ fixing ? t('migrationChecks.fixing') : t('migrationChecks.confirm_fix', { count: preview?.count ?? 0 }) }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
