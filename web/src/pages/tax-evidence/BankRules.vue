<script setup lang="ts">
/**
 * Pravidla bankovních pohybů v daňové evidenci (issue #140). Pohyb bez dokladu
 * (poplatek, převod mezi vlastními účty, vklad) pravidlo ignoruje a/nebo zařadí
 * v peněžním deníku. Zařazení výdaje = daňová uznatelnost, stejně jako u pravidel nákladů.
 */
import { computed, onMounted, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import type { TaxBucketOverride } from '@/api/taxEvidence'
import {
  taxEvidenceBankRulesApi,
  type BankRuleDirection,
  type TaxEvidenceBankRule,
  type TaxEvidenceBankRulePayload,
} from '@/api/taxEvidenceBankRules'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const pageId = useId()
const canWrite = computed(() => auth.canWrite('tax_evidence.classification.write'))

const BUCKETS: TaxBucketOverride[] = [
  'income_taxable', 'income_exempt', 'income_nontax',
  'expense_taxable', 'expense_nontax', 'transfer', 'private',
]
const BUCKET_KEY: Record<TaxBucketOverride, string> = {
  income_taxable: 'tax_evidence.cash_journal.class_income_taxable',
  income_exempt: 'tax_evidence.cash_journal.class_income_exempt',
  income_nontax: 'tax_evidence.cash_journal.class_income_nontax',
  expense_taxable: 'tax_evidence.cash_journal.class_expense_taxable',
  expense_nontax: 'tax_evidence.cash_journal.class_expense_nontax',
  transfer: 'tax_evidence.cash_journal.class_transfer',
  private: 'tax_evidence.cash_journal.class_private',
}
const DIRECTIONS: BankRuleDirection[] = ['any', 'incoming', 'outgoing']
const DIRECTION_KEY: Record<BankRuleDirection, string> = {
  any: 'tax_evidence.bank_rules.direction_any',
  incoming: 'tax_evidence.bank_rules.direction_incoming',
  outgoing: 'tax_evidence.bank_rules.direction_outgoing',
}

const items = ref<TaxEvidenceBankRule[]>([])
const loading = ref(true)
const busy = ref(false)
const showForm = ref(false)
const editingId = ref<number | null>(null)
const fieldErrors = ref<Record<string, string>>({})

function emptyForm(): TaxEvidenceBankRulePayload {
  return {
    name: '', priority: 100, is_active: true, direction: 'any',
    counterparty_account: null, variable_symbol: null, text_contains: null,
    amount_min: null, amount_max: null, action_ignore: true, tax_bucket: null,
  }
}
const form = ref<TaxEvidenceBankRulePayload>(emptyForm())
const validForm = computed(() => form.value.name.trim() !== ''
  && !!(form.value.counterparty_account || form.value.variable_symbol || form.value.text_contains)
  && (form.value.action_ignore || form.value.tax_bucket !== null))

async function load() {
  loading.value = true
  try {
    items.value = await taxEvidenceBankRulesApi.list()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function openNew() {
  editingId.value = null
  form.value = emptyForm()
  fieldErrors.value = {}
  showForm.value = true
}
function openEdit(r: TaxEvidenceBankRule) {
  editingId.value = r.id
  const { id: _id, hit_count: _h, last_hit_at: _l, ...payload } = r
  form.value = { ...payload }
  fieldErrors.value = {}
  showForm.value = true
}

async function save() {
  busy.value = true
  fieldErrors.value = {}
  try {
    if (editingId.value === null) {
      await taxEvidenceBankRulesApi.create(form.value)
    } else {
      await taxEvidenceBankRulesApi.update(editingId.value, form.value)
    }
    toast.success(t('common.saved'))
    showForm.value = false
    await load()
  } catch (e: any) {
    fieldErrors.value = e?.response?.data?.error?.fields ?? {}
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function remove(r: TaxEvidenceBankRule) {
  if (!window.confirm(t('tax_evidence.bank_rules.delete_confirm', { name: r.name }))) return
  busy.value = true
  try {
    await taxEvidenceBankRulesApi.delete(r.id)
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function applyAll() {
  busy.value = true
  try {
    const res = await taxEvidenceBankRulesApi.apply()
    toast.success(t('tax_evidence.bank_rules.applied', { count: res.applied }))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

function onBucket(value: string) {
  form.value.tax_bucket = value === '' ? null : value as TaxBucketOverride
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('tax_evidence.bank_rules.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('tax_evidence.bank_rules.subtitle') }}</p>
      </div>
      <div v-if="canWrite" class="flex flex-wrap gap-2">
        <button :disabled="busy || items.length === 0" :class="btnOutline('success')" class="whitespace-nowrap" data-test="bank-rules-apply" @click="applyAll">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('tax_evidence.bank_rules.apply') }}
        </button>
        <button :class="btnFilled('primary')" class="whitespace-nowrap" data-test="bank-rules-new" @click="openNew">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('tax_evidence.bank_rules.new') }}
        </button>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="loading" class="p-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="items.length === 0" icon="cycle"
        :title="t('tax_evidence.bank_rules.empty')"
        :cta="canWrite ? t('tax_evidence.bank_rules.new') : undefined"
        @action="openNew" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500">
            <tr>
              <th class="text-left font-medium px-3 py-2">{{ t('tax_evidence.bank_rules.col_name') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('tax_evidence.bank_rules.col_criteria') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('tax_evidence.bank_rules.col_action') }}</th>
              <th class="text-right font-medium px-3 py-2">{{ t('tax_evidence.bank_rules.col_priority') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('tax_evidence.bank_rules.col_usage') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in items" :key="r.id" class="border-t border-neutral-100" :class="{ 'opacity-50': !r.is_active }">
              <td class="px-3 py-2 font-medium text-neutral-700">{{ r.name }}</td>
              <td class="px-3 py-2 text-xs text-neutral-500">
                <div>{{ t(DIRECTION_KEY[r.direction]) }}</div>
                <div v-if="r.counterparty_account" class="font-mono">{{ r.counterparty_account }}</div>
                <div v-if="r.variable_symbol" class="font-mono">VS {{ r.variable_symbol }}</div>
                <div v-if="r.text_contains" class="italic">„{{ r.text_contains }}"</div>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <span v-if="r.action_ignore" class="text-xs px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-600 font-medium">{{ t('tax_evidence.bank_rules.action_ignore') }}</span>
                <span v-if="r.tax_bucket" class="ml-1 text-xs px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 font-medium">{{ t(BUCKET_KEY[r.tax_bucket]) }}</span>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ r.priority }}</td>
              <td class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">
                <div>{{ t('tax_evidence.bank_rules.usage', { count: r.hit_count }) }}</div>
                <div v-if="r.last_hit_at" class="text-neutral-400">{{ formatDate(r.last_hit_at) }}</div>
              </td>
              <td class="px-3 py-2">
                <div v-if="canWrite" class="flex flex-wrap items-center justify-end gap-1">
                  <button :class="btnOutlineSm('primary')" :title="t('common.edit')" @click="openEdit(r)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button :disabled="busy" :class="btnOutlineSm('danger')" :title="t('common.delete')" @click="remove(r)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal v-if="showForm" :title="editingId === null ? t('tax_evidence.bank_rules.new') : t('tax_evidence.bank_rules.edit')" @close="showForm = false">
      <p class="text-sm text-neutral-600 mb-4">{{ t('tax_evidence.bank_rules.form_help') }}</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label :for="`${pageId}-name`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.bank_rules.form_name') }} *</label>
          <input :id="`${pageId}-name`" v-model="form.name" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div class="sm:col-span-2 border border-neutral-200 rounded-md p-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="sm:col-span-2 text-xs font-medium text-neutral-500">{{ t('tax_evidence.bank_rules.criteria_group') }}</div>
          <div>
            <label :for="`${pageId}-direction`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.bank_rules.form_direction') }}</label>
            <select :id="`${pageId}-direction`" v-model="form.direction" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="d in DIRECTIONS" :key="d" :value="d">{{ t(DIRECTION_KEY[d]) }}</option>
            </select>
          </div>
          <div>
            <label :for="`${pageId}-account`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.bank_rules.form_account') }}</label>
            <input :id="`${pageId}-account`" v-model="form.counterparty_account" placeholder="19-123456789" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
          </div>
          <div>
            <label :for="`${pageId}-vs`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.bank_rules.form_vs') }}</label>
            <input :id="`${pageId}-vs`" v-model="form.variable_symbol" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
          </div>
          <div>
            <label :for="`${pageId}-text`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.bank_rules.form_text') }}</label>
            <input :id="`${pageId}-text`" v-model="form.text_contains" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.bank_rules.form_amount') }}</label>
            <div class="flex items-center gap-2">
              <input v-model.number="form.amount_min" type="number" step="0.01" min="0" class="w-full min-w-0 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
              <span class="text-neutral-400">–</span>
              <input v-model.number="form.amount_max" type="number" step="0.01" min="0" class="w-full min-w-0 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
            </div>
          </div>
          <p class="sm:col-span-2 text-xs" :class="fieldErrors.criteria ? 'text-danger-500' : 'text-neutral-400'">{{ t('tax_evidence.bank_rules.criteria_hint') }}</p>
        </div>
        <div class="sm:col-span-2 border border-neutral-200 rounded-md p-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="sm:col-span-2 text-xs font-medium text-neutral-500">{{ t('tax_evidence.bank_rules.action_group') }}</div>
          <label class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer h-9">
            <input v-model="form.action_ignore" type="checkbox" class="rounded border-neutral-300" />
            {{ t('tax_evidence.bank_rules.form_ignore') }}
          </label>
          <div>
            <label :for="`${pageId}-bucket`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.bank_rules.form_bucket') }}</label>
            <select :id="`${pageId}-bucket`" :value="form.tax_bucket ?? ''" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
              @change="onBucket(($event.target as HTMLSelectElement).value)">
              <option value="">{{ t('tax_evidence.bank_rules.bucket_none') }}</option>
              <option v-for="b in BUCKETS" :key="b" :value="b">{{ t(BUCKET_KEY[b]) }}</option>
            </select>
          </div>
          <p class="sm:col-span-2 text-xs" :class="fieldErrors.action ? 'text-danger-500' : 'text-neutral-400'">{{ t('tax_evidence.bank_rules.action_hint') }}</p>
        </div>
        <div>
          <label :for="`${pageId}-priority`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.bank_rules.form_priority') }}</label>
          <input :id="`${pageId}-priority`" v-model.number="form.priority" type="number" min="0" max="999" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer h-9">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
            {{ t('tax_evidence.bank_rules.form_active') }}
          </label>
        </div>
      </div>
      <div class="flex flex-wrap justify-end gap-2 mt-4">
        <button :class="btnOutline('neutral')" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button :disabled="busy || !validForm" :class="btnFilled('primary')" data-test="bank-rules-save" @click="save">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
      </div>
    </Modal>
  </div>
</template>
