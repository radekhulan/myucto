<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useSupplierStore } from '@/stores/supplier'
import { useRowLink } from '@/composables/useRowLink'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { useHotkey } from '@/composables/useHotkey'
import CodebookImportDialog from '@/components/accounting/CodebookImportDialog.vue'
import { codebookTransferApi } from '@/api/codebookTransfer'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

// V ukázce je zakládání analytiky vidět a formulář jde projít celý, jen se neuloží —
// demo role má na `accounting` jen čtení, takže canWrite() by tlačítko schoval.
const canCreateAccount = computed(() => supplierStore.currentSupplierId > 0 && (auth.canWrite('accounting') || auth.isDemo))

const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const loadError = ref('')
const framework = computed(() => supplierStore.currentSupplier?.id === supplierStore.currentSupplierId ? supplierStore.currentSupplier.accounting_framework ?? 'cz_500' : null)
let loadGeneration = 0
const error = ref('')
const showInactive = ref(false)
const importOpen = ref(false)

// Účtové třídy 0–7 (směrná účtová osnova), + mimobilanční / závěrkové.
const CLASS_KEYS = ['0', '1', '2', '3', '4', '5', '6', '7'] as const
const classKeys = computed<readonly string[]>(() => framework.value === 'cz_504' ? ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'] : CLASS_KEYS)

function accountClass(a: ChartAccount): string {
  const c = (a.account_code || '').charAt(0)
  if (classKeys.value.includes(c)) return c
  if (a.account_type === 'offbalance') return 'offbalance'
  if (a.account_type === 'closing') return 'closing'
  return 'other'
}

interface AccountGroup {
  key: string
  label: string
  rows: ChartAccount[]
}

const groups = computed<AccountGroup[]>(() => {
  const buckets: Record<string, ChartAccount[]> = {}
  for (const a of accounts.value) {
    const k = accountClass(a)
    ;(buckets[k] ||= []).push(a)
  }
  const order = [...classKeys.value, 'offbalance', 'closing', 'other']
  return order
    .filter(k => buckets[k]?.length)
    .map(k => ({
      key: k,
      label: t(`accounting.accounts.${framework.value === 'cz_504' ? 'class_504' : 'class'}.${k}`),
      rows: buckets[k].sort((a, b) => a.account_code.localeCompare(b.account_code)),
    }))
})

// Syntetické účty = možní rodiče pro novou analytiku.
const syntheticAccounts = computed(() =>
  accounts.value.filter(a => a.is_synthetic && a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

async function load() {
  const supplierId = supplierStore.currentSupplierId
  const generation = ++loadGeneration
  loading.value = true
  loadError.value = ''
  accounts.value = []
  if (!Number.isInteger(supplierId) || supplierId <= 0 || framework.value === null) {
    loading.value = false
    return
  }
  try {
    const rows = await accountingApi.listAccounts({ includeInactive: showInactive.value, supplierId })
    if (generation !== loadGeneration || supplierStore.currentSupplierId !== supplierId) return
    accounts.value = rows
  } catch (e: any) {
    if (generation === loadGeneration && supplierStore.currentSupplierId === supplierId) {
      loadError.value = e?.response?.data?.error?.message || t('common.error')
    }
  } finally {
    if (generation === loadGeneration && supplierStore.currentSupplierId === supplierId) loading.value = false
  }
}
watch(() => [supplierStore.currentSupplierId, framework.value], () => {
  showForm.value = false
  deleteTarget.value = null
  importOpen.value = false
  void load()
})
onMounted(load)

const showForm = ref(false)
useHotkey('escape', () => {
  if (showForm.value) showForm.value = false
  if (deleteTarget.value) deleteTarget.value = null
})

const form = reactive({
  parent_id: null as number | null,
  account_code: '',
  name: '',
})

// Tečkovaný tvar (211.100) je od migrace 1322 jediný správný zápis analytiky a
// kód účtu už později změnit nejde — syntetika s tečkou je proto pevná předpona
// a uživatel doplní jen část za ní.
const parentCode = computed(() => syntheticAccounts.value.find(a => a.id === form.parent_id)?.account_code ?? '')

/** Celý kód analytiky; vložený celý kód (019.100) se nezdvojí. */
function fullCode(): string {
  const suffix = form.account_code.trim()
  const prefix = `${parentCode.value}.`
  return suffix.startsWith(prefix) ? suffix : prefix + suffix
}

function openCreate(parentId: number | null = null) {
  error.value = ''
  Object.assign(form, { parent_id: parentId ?? syntheticAccounts.value[0]?.id ?? null, account_code: '', name: '' })
  showForm.value = true
}

async function save() {
  error.value = ''
  // Až po otevření formuláře: v ukázce má jít vyplnit a odeslat, jen se neuloží.
  if (blockDemoMutation()) { showForm.value = false; return }
  if (!form.parent_id) { error.value = t('accounting.accounts.parent_required'); return }
  if (!form.account_code.trim() || !form.name.trim()) { error.value = t('accounting.accounts.code_name_required'); return }
  try {
    await accountingApi.createAccount({
      parent_id: form.parent_id,
      account_code: fullCode(),
      name: form.name.trim(),
    })
    showForm.value = false
    toast.success(t('common.created'))
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}

async function toggleActive(a: ChartAccount) {
  const next = !a.is_active
  if (next === false && !confirm(t('accounting.accounts.deactivate_confirm', { code: a.account_code }))) return
  try {
    await accountingApi.updateAccount(a.id, { is_active: next })
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// Mazání analytiky: kód účtu nejde přejmenovat (visí na něm kontace, pravidla i karty
// jako text), takže smazání je jediná oprava překlepu v kódu. Backend pustí jen účet
// bez jediného pohybu a odkazu — tady se jen ptáme, protože je to nevratné.
const deleteTarget = ref<ChartAccount | null>(null)
const deleting = ref(false)

async function confirmDelete() {
  const account = deleteTarget.value
  if (!account) return
  deleting.value = true
  try {
    await accountingApi.deleteAccount(account.id)
    deleteTarget.value = null
    toast.success(t('accounting.accounts.deleted'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    deleting.value = false
  }
}

/** Proklik na kartu účtu (kmen + analytiky + zůstatky) — odtud dál na opis/knihu/deník. */
function detailLink(a: ChartAccount) {
  return { name: 'accounting-account-detail', params: { accountId: a.id } }
}

const navigateRow = useRowLink()
function openDetail(a: ChartAccount, e: MouseEvent) {
  navigateRow(detailLink(a), e)
}

function typeLabel(type: string): string {
  return t(`accounting.accounts.type.${type}`)
}
function sideLabel(side: string | null): string {
  if (!side) return '—'
  return t(`accounting.journal.side.${side}`)
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.accounts.title') }}</h1>
        <p v-if="framework" class="text-sm text-neutral-500 mt-0.5">{{ t(framework === 'cz_504' ? 'accounting.accounts.subtitle_504' : 'accounting.accounts.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <label class="flex items-center gap-1.5 text-sm text-neutral-600 mr-1">
          <input v-model="showInactive" type="checkbox" class="rounded border-neutral-300 text-primary-600" @change="load" />
          {{ t('accounting.accounts.show_inactive') }}
        </label>
        <button @click="codebookTransferApi.download('accounts')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('codebookTransfer.export') }}
        </button>
        <button v-if="auth.canWrite('accounting')" @click="importOpen = true" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('codebookTransfer.import') }}
        </button>
        <button v-if="canCreateAccount" @click="openCreate()" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.accounts.new') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <p v-else-if="loadError" class="text-sm text-danger-700" role="alert">{{ loadError }}</p>

    <EmptyState v-else-if="accounts.length === 0" boxed icon="doc"
      :title="t('accounting.accounts.empty')"
      :cta="canCreateAccount ? t('accounting.accounts.new') : undefined"
      @action="openCreate()" />

    <div v-else class="space-y-5">
      <div v-for="g in groups" :key="g.key" :data-account-class="g.key" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-3 py-2 bg-neutral-50 border-b border-neutral-100 text-xs font-bold uppercase tracking-wide text-neutral-500">
          {{ g.label }}
        </div>
        <!-- Desktop tabulka -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.accounts.code') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.accounts.name') }}</th>
                <th class="px-3 py-2 text-left font-medium w-32">{{ t('accounting.accounts.type_col') }}</th>
                <th class="px-3 py-2 text-center font-medium w-24">{{ t('accounting.accounts.normal_side') }}</th>
                <th class="px-3 py-2 text-center font-medium w-20">{{ t('users.active') }}</th>
                <th class="px-3 py-2 w-24"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in g.rows" :key="a.id" class="group cursor-pointer hover:bg-neutral-50"
                :class="{ 'opacity-50': !a.is_active }"
                @click="openDetail(a, $event)" @auxclick.prevent="openDetail(a, $event)">
                <td class="px-3 py-2 font-mono whitespace-nowrap" :class="a.is_synthetic ? 'font-semibold' : 'text-neutral-500'">
                  <span v-if="!a.is_synthetic" class="text-neutral-300 mr-1">└</span>
                  <RouterLink :to="detailLink(a)" class="row-link text-primary-600 hover:text-primary-700 hover:underline"
                    :title="t('accounting.accounts.open_detail')" @click.stop>
                    {{ a.account_code }}
                  </RouterLink>
                  <button v-if="canCreateAccount && a.is_synthetic && a.is_active" type="button" data-test="add-analytic"
                    class="ml-1.5 inline-flex items-center justify-center w-5 h-5 align-middle rounded text-primary-600 hover:bg-primary-100 opacity-40 group-hover:opacity-100 focus:opacity-100 cursor-pointer"
                    :title="t('accounting.accounts.new')" :aria-label="`${t('accounting.accounts.new')} ${a.account_code}`"
                    @click.stop="openCreate(a.id)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  </button>
                </td>
                <td class="px-3 py-2">{{ a.name }}</td>
                <td class="px-3 py-2 text-neutral-600">{{ typeLabel(a.account_type) }}</td>
                <td class="px-3 py-2 text-center text-xs">
                  <span v-if="a.normal_side" class="px-1.5 py-0.5 rounded font-medium"
                    :class="a.normal_side === 'debit' ? 'bg-primary-100 text-primary-700' : 'bg-warning-50 text-warning-600'">
                    {{ sideLabel(a.normal_side) }}
                  </span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span v-if="a.is_active" class="text-success-600">✓</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button v-if="auth.canWrite('accounting') && !a.is_synthetic" @click.stop="toggleActive(a)"
                    class="cursor-pointer text-xs"
                    :class="a.is_active ? 'text-danger-500 hover:text-danger-600' : 'text-primary-600 hover:text-primary-700'">
                    {{ a.is_active ? t('accounting.accounts.deactivate') : t('accounting.accounts.reactivate') }}
                  </button>
                  <button v-if="auth.canWrite('accounting') && !a.is_synthetic" @click.stop="deleteTarget = a"
                    class="cursor-pointer text-xs text-danger-500 hover:text-danger-600 ml-3">
                    {{ t('common.delete') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Mobile karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="a in g.rows" :key="`m-${a.id}`" class="p-3 space-y-1" :class="{ 'opacity-50': !a.is_active }">
            <div class="flex items-baseline justify-between gap-2">
              <span class="inline-flex items-center gap-1.5">
                <RouterLink :to="detailLink(a)" class="font-mono text-primary-600"
                  :class="a.is_synthetic ? 'font-semibold' : ''">{{ a.account_code }}</RouterLink>
                <button v-if="canCreateAccount && a.is_synthetic && a.is_active" type="button"
                  class="inline-flex items-center justify-center w-6 h-6 rounded text-primary-600 hover:bg-primary-100 cursor-pointer"
                  :title="t('accounting.accounts.new')" :aria-label="`${t('accounting.accounts.new')} ${a.account_code}`"
                  @click="openCreate(a.id)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                </button>
              </span>
              <span class="text-xs text-neutral-500">{{ typeLabel(a.account_type) }}</span>
            </div>
            <div class="text-neutral-900">{{ a.name }}</div>
            <div class="flex items-center justify-between gap-2 text-xs">
              <span class="text-neutral-500">{{ t('accounting.accounts.normal_side') }}: {{ sideLabel(a.normal_side) }}</span>
              <button v-if="auth.canWrite('accounting') && !a.is_synthetic" @click="toggleActive(a)"
                class="cursor-pointer" :class="a.is_active ? 'text-danger-500' : 'text-primary-600'">
                {{ a.is_active ? t('accounting.accounts.deactivate') : t('accounting.accounts.reactivate') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: nová analytika -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('accounting.accounts.new_title') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.accounts.new_hint') }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.accounts.parent') }}<span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span></label>
            <select v-model="form.parent_id" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="s in syntheticAccounts" :key="s.id" :value="s.id">{{ s.account_code }} - {{ s.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.accounts.code') }}<span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span></label>
            <div class="flex h-10 border border-neutral-300 rounded-md text-sm font-mono overflow-hidden focus-within:ring-2 focus-within:ring-primary-500/40">
              <span class="flex items-center px-3 bg-neutral-50 border-r border-neutral-300 text-neutral-600 select-none" data-test="code-prefix">{{ parentCode }}.</span>
              <input v-model="form.account_code" type="text" required class="flex-1 min-w-0 px-3 outline-none bg-surface"
                :placeholder="t('accounting.accounts.code_placeholder')" :aria-label="t('accounting.accounts.code')" />
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('accounting.accounts.code_dot_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.accounts.name') }}<span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span></label>
            <input v-model="form.name" type="text" required :aria-label="t('accounting.accounts.name')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="showForm = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="save" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('common.create') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Potvrzení smazání analytiky -->
    <div v-if="deleteTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('accounting.accounts.delete_title') }}</h3>
        <p class="text-sm text-neutral-600 mb-1">
          <span class="font-mono">{{ deleteTarget.account_code }}</span> - {{ deleteTarget.name }}
        </p>
        <p class="text-sm text-neutral-500 mb-4">{{ t('accounting.accounts.delete_hint') }}</p>
        <div class="flex justify-end gap-2">
          <button @click="deleteTarget = null" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button @click="confirmDelete" :disabled="deleting" :class="btnFilled('danger')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('common.delete') }}
          </button>
        </div>
      </div>
    </div>

    <CodebookImportDialog v-model="importOpen" kind="accounts" :title="t('codebookTransfer.title_accounts')" @imported="load" />
  </div>
</template>
