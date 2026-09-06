<script setup lang="ts">
import { ref, onMounted, reactive, computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { accountingApi, type PostingRule, type ChartAccount } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import CodebookImportDialog from '@/components/accounting/CodebookImportDialog.vue'
import { codebookTransferApi } from '@/api/codebookTransfer'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
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
const loading = ref(false)
const error = ref('')
const importOpen = ref(false)

const pickable = computed(() =>
  accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)
const accountByCode = computed<Record<string, ChartAccount>>(() => {
  const m: Record<string, ChartAccount> = {}
  for (const a of accounts.value) m[a.account_code] = a
  return m
})

async function load() {
  loading.value = true
  try {
    const [map, accs] = await Promise.all([
      accountingApi.listPostingRules(),
      accountingApi.listAccounts(),
    ])
    rules.value = Object.values(map).sort((a, b) => a.rule_key.localeCompare(b.rule_key))
    accounts.value = accs
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

async function save() {
  error.value = ''
  if (!form.debit_account_code.trim() && !form.credit_account_code.trim()) {
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
  return a ? `${code} — ${a.name}` : code
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div v-if="!embedded">
        <h1 class="text-2xl font-semibold">{{ t('accounting.posting_rules.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.posting_rules.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button @click="codebookTransferApi.download('posting-rules')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('codebookTransfer.export') }}
        </button>
        <button v-if="auth.canWrite('accounting.templates')" @click="importOpen = true" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('codebookTransfer.import') }}
        </button>
      </div>
    </div>

    <datalist :id="`${pageId}-pr-coa-options`">
      <option v-for="a in pickable" :key="a.id" :value="a.account_code">{{ a.account_code }} — {{ a.name }}</option>
    </datalist>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="rules.length === 0" boxed accent="neutral" icon="swap" :title="t('accounting.posting_rules.empty')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
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
            <tr v-for="r in rules" :key="r.rule_key">
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
        <div v-for="r in rules" :key="`m-${r.rule_key}`" class="p-3 space-y-1">
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
        <p class="text-xs text-neutral-500 mb-3 font-mono">{{ form.rule_key }} — {{ form.description }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.posting_rules.debit') }}</label>
            <input v-model="form.debit_account_code" :list="`${pageId}-pr-coa-options`" type="text"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <div class="text-xs text-neutral-500 mt-0.5">{{ accountName(form.debit_account_code || null) }}</div>
          </div>
          <div>
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
  </div>
</template>
