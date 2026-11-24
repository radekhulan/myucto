<script setup lang="ts">
import { ref, onMounted, reactive, computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { accountingApi, type PostingRule, type ChartAccount } from '@/api/accounting'
import { codebooksApi } from '@/api/codebooks'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import CodebookImportDialog from '@/components/accounting/CodebookImportDialog.vue'
import PostingRuleChartAlignmentDialog from '@/components/accounting/PostingRuleChartAlignmentDialog.vue'
import { codebookTransferApi } from '@/api/codebookTransfer'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

// embedded = vykresleno jako záložka uvnitř ToolsPage.vue (Nástroje); hlavičku dodává obálka.
defineProps<{ embedded?: boolean }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const pageId = useId()

const rules = ref<PostingRule[]>([])
const accounts = ref<ChartAccount[]>([])
const vatRates = ref<number[]>([])
const loading = ref(false)
const error = ref('')
const importOpen = ref(false)
const alignmentOpen = ref(false)

const pickable = computed(() =>
  accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)
const accountByCode = computed<Record<string, ChartAccount>>(() => {
  const m: Record<string, ChartAccount> = {}
  for (const a of accounts.value) m[a.account_code] = a
  return m
})
const accountById = computed(() => new Map(accounts.value.map(account => [account.id, account])))

const vatGroups = computed(() => ([
  { key: 'invoice.vat.output', side: 'credit_account_code', fallback: '343.200', label: t('accounting.posting_rules.vat_output') },
  { key: 'invoice.vat.input', side: 'debit_account_code', fallback: '343.100', label: t('accounting.posting_rules.vat_input') },
] as const).map(group => {
  const common = rules.value.find(rule => rule.rule_key === group.key)?.[group.side] ?? group.fallback
  const configured = rules.value.filter(rule => rule.rule_key.startsWith(group.key + '.') && rule[group.side])
  const rates = [...new Set([...vatRates.value, ...configured.map(rule => Number(rule.rule_key.slice(group.key.length + 1)))])]
  return {
    ...group,
    common,
    rates: rates.filter(rate => rate > 0).sort((a, b) => b - a).map(rate => {
      const key = `${group.key}.${rate}`
      const code = configured.find(rule => rule.rule_key === key)?.[group.side] ?? null
      return { rate, key, code }
    }),
  }
}))
const ordinaryRules = computed(() => rules.value.filter(rule =>
  !vatGroups.value.some(group => rule.rule_key.startsWith(group.key + '.')),
))
const rateGroup = computed(() => vatGroups.value.find(group => form.rule_key.startsWith(group.key + '.')))
const rateAccounts = computed(() => {
  const group = rateGroup.value
  if (!group) return []
  const root = rootCode(accountByCode.value[group.common]) ?? '343'
  const settlement = rules.value.find(rule => rule.rule_key === 'vat.clearing.output')?.credit_account_code ?? '343.900'
  return pickable.value.filter(account => account.account_code !== settlement && rootCode(account) === root)
})

function rootCode(account: ChartAccount | undefined): string | undefined {
  for (let remaining = accounts.value.length; account && remaining > 0; remaining--) {
    if (account.parent_id === null) return account.is_synthetic ? account.account_code : undefined
    account = accountById.value.get(account.parent_id)
  }
  return undefined
}

async function load() {
  loading.value = true
  try {
    const [map, accs, rates] = await Promise.all([
      accountingApi.listPostingRules(),
      accountingApi.listAccounts({ includeInactive: true }),
      codebooksApi.vatRates(),
    ])
    rules.value = Object.values(map).sort((a, b) => a.rule_key.localeCompare(b.rule_key))
    accounts.value = accs
    vatRates.value = rates.map(rate => rate.rate_percent)
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  // Proklik „Upravit předkontaci" ze sekce Zaúčtování na detailu dokladu (?rule_key=…)
  // otevře rovnou tu jednu předkontaci. Bez toho uživatel přistál na seznamu padesáti
  // systémových klíčů a musel si ten svůj najít — a přesně proto se místo šablony
  // opravovala kontace dokladu. Neznámý klíč se ignoruje: seznam je pořád správná
  // obrazovka, jen bez předvyplnění.
  const wanted = typeof route.query.rule_key === 'string' ? route.query.rule_key : null
  const match = wanted === null ? undefined : rules.value.find(r => r.rule_key === wanted)
  if (match && auth.canWrite('accounting.templates')) openEdit(match)
})

const showForm = ref(false)
useHotkey('escape', () => { if (showForm.value) showForm.value = false })

const form = reactive({
  rule_key: '',
  description: '',
  debit_account_code: '',
  credit_account_code: '',
})

function openEdit(r: PostingRule) {
  error.value = ''
  Object.assign(form, {
    rule_key: r.rule_key,
    description: r.description,
    debit_account_code: r.debit_account_code ?? '',
    credit_account_code: r.credit_account_code ?? '',
  })
  showForm.value = true
}

function openRate(key: string, description: string, side: 'debit_account_code' | 'credit_account_code', code: string | null) {
  error.value = ''
  Object.assign(form, { rule_key: key, description, debit_account_code: '', credit_account_code: '', [side]: code ?? '' })
  showForm.value = true
}

async function save() {
  error.value = ''
  if (!rateGroup.value && !form.debit_account_code.trim() && !form.credit_account_code.trim()) {
    error.value = t('accounting.posting_rules.at_least_one')
    return
  }
  try {
    await accountingApi.putPostingRule(form.rule_key, {
      debit_account_code: form.debit_account_code.trim() || null,
      credit_account_code: form.credit_account_code.trim() || null,
    })
    showForm.value = false
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}

function accountName(code: string | null): string {
  if (!code) return '—'
  const a = accountByCode.value[code]
  return a ? `${code} - ${a.name}` : code
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 max-md:flex-wrap max-md:gap-3">
      <div v-if="!embedded">
        <h1 class="text-2xl font-semibold">{{ t('accounting.posting_rules.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.posting_rules.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 max-md:flex-wrap">
        <button @click="codebookTransferApi.download('posting-rules')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('codebookTransfer.export') }}
        </button>
        <button v-if="auth.canWrite('accounting.templates')" @click="importOpen = true" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('codebookTransfer.import') }}
        </button>
        <button v-if="auth.canWrite('accounting.templates')" @click="alignmentOpen = true" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
          {{ t('accounting.posting_rules.alignment.button') }}
        </button>
      </div>
    </div>

    <datalist :id="`${pageId}-pr-coa-options`">
      <option v-for="a in pickable" :key="a.id" :value="a.account_code">{{ a.account_code }} - {{ a.name }}</option>
    </datalist>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <section v-if="!loading && vatGroups.some(group => group.rates.length)" class="bg-surface border border-neutral-200 rounded-lg p-4 mb-4">
      <h2 class="font-semibold">{{ t('accounting.posting_rules.vat_rates_title') }}</h2>
      <div class="mt-3 grid gap-4 md:grid-cols-2">
        <div v-for="group in vatGroups" :key="group.key">
          <h3 class="text-sm font-medium">{{ group.label }}</h3>
          <p class="text-xs text-neutral-500">{{ t('accounting.posting_rules.vat_common', { account: accountName(group.common) }) }}</p>
          <ul class="mt-2 divide-y divide-neutral-100">
            <li v-for="row in group.rates" :key="row.key" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
              <span>{{ row.rate }} % · {{ row.code ? accountName(row.code) : t('accounting.posting_rules.vat_use_common') }}</span>
              <button v-if="auth.canWrite('accounting.templates')" type="button" :class="btnOutlineSm('neutral')"
                @click="openRate(row.key, `${group.label} ${row.rate} %`, group.side, row.code)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <EmptyState v-if="!loading && ordinaryRules.length === 0" boxed accent="neutral" icon="swap" :title="t('accounting.posting_rules.empty')" />

    <div v-else-if="!loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.posting_rules.rule_key') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.posting_rules.description') }}</th>
              <th class="px-3 py-2 text-left font-medium w-40">{{ t('accounting.posting_rules.debit') }}</th>
              <th class="px-3 py-2 text-left font-medium w-40">{{ t('accounting.posting_rules.credit') }}</th>
              <th class="px-3 py-2 text-center font-medium w-24">{{ t('accounting.posting_rules.origin') }}</th>
              <th class="px-3 py-2 w-20"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in ordinaryRules" :key="r.rule_key">
              <td class="px-3 py-2 font-mono text-xs">{{ r.rule_key }}</td>
              <td class="px-3 py-2 text-neutral-600">{{ r.description }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ r.debit_account_code || '—' }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ r.credit_account_code || '—' }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium"
                  :class="r.supplier_id ? 'bg-primary-100 text-primary-700' : 'bg-neutral-100 text-neutral-500'">
                  {{ r.supplier_id ? t('accounting.posting_rules.override') : t('accounting.posting_rules.global') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">
                <button v-if="auth.canWrite('accounting.templates')" @click="openEdit(r)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">{{ t('common.edit') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Mobile -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="r in ordinaryRules" :key="`m-${r.rule_key}`" class="p-3 space-y-1">
          <div class="flex items-baseline justify-between gap-2">
            <span class="font-mono text-xs text-neutral-700">{{ r.rule_key }}</span>
            <span class="text-xs px-2 py-0.5 rounded font-medium"
              :class="r.supplier_id ? 'bg-primary-100 text-primary-700' : 'bg-neutral-100 text-neutral-500'">
              {{ r.supplier_id ? t('accounting.posting_rules.override') : t('accounting.posting_rules.global') }}
            </span>
          </div>
          <div class="text-sm text-neutral-700">{{ r.description }}</div>
          <div class="flex items-center justify-between gap-2 text-xs">
            <span class="font-mono text-neutral-500">MD {{ r.debit_account_code || '—' }} / D {{ r.credit_account_code || '—' }}</span>
            <button v-if="auth.canWrite('accounting.templates')" @click="openEdit(r)" class="cursor-pointer text-primary-600 text-xs">{{ t('common.edit') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: override -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('accounting.posting_rules.edit_title') }}</h3>
        <p class="text-xs text-neutral-500 mb-3 font-mono">{{ form.rule_key }} - {{ form.description }}</p>
        <div class="space-y-3">
          <label v-if="rateGroup" class="block text-sm font-medium text-neutral-700">
            {{ t('accounting.posting_rules.vat_rates_title') }}
            <select v-model="form[rateGroup.side]" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md text-sm">
              <option value="">{{ t('accounting.posting_rules.vat_use_common') }} ({{ accountName(rateGroup.common) }})</option>
              <option v-for="a in rateAccounts" :key="a.id" :value="a.account_code">{{ accountName(a.account_code) }}</option>
            </select>
          </label>
          <div v-if="!rateGroup">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.posting_rules.debit') }}</label>
            <input v-model="form.debit_account_code" :list="`${pageId}-pr-coa-options`" type="text"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <div class="text-xs text-neutral-500 mt-0.5">{{ accountName(form.debit_account_code || null) }}</div>
          </div>
          <div v-if="!rateGroup">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.posting_rules.credit') }}</label>
            <input v-model="form.credit_account_code" :list="`${pageId}-pr-coa-options`" type="text"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <div class="text-xs text-neutral-500 mt-0.5">{{ accountName(form.credit_account_code || null) }}</div>
          </div>
          <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="showForm = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="save" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('common.save') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <CodebookImportDialog v-model="importOpen" kind="posting-rules" :title="t('codebookTransfer.title_posting_rules')" @imported="load" />
    <PostingRuleChartAlignmentDialog v-model="alignmentOpen" @applied="load" />
  </div>
</template>
