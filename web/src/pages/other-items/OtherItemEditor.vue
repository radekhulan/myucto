<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import type { Client } from '@/api/clients'
import { otherItemKindsApi, otherItemsApi, type OtherItemKind, type OtherItemPayload, type OtherItemPostingLine, type OtherItemSide } from '@/api/otherItems'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { appIsoDate } from '@/utils/date'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import OtherItemPostingLines from '@/components/accounting/OtherItemPostingLines.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import { useDocumentDimensions } from '@/composables/useDocumentDimensions'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const supplier = useSupplierStore()
const toast = useToast()
const editId = computed(() => Number(route.params.id || 0))
const isEdit = computed(() => editId.value > 0)
const isDoubleEntry = computed(() => supplier.currentSupplier?.accounting_mode === 'double_entry')
const docDims = useDocumentDimensions('other-items')
const dimsReady = ref(false)
const loading = ref(false)
const saving = ref(false)
const splitPosting = ref(false)
const postingLines = ref<OtherItemPostingLine[]>([])
const accounts = ref<ChartAccount[]>([])
const kinds = ref<OtherItemKind[]>([])
/** Druh, který koncept nese z uložení — zůstává ve výběru, i když ho mezitím skryli. */
const storedKind = ref<string | null>(null)
const today = appIsoDate()
const form = reactive({
  side: (route.query.side === 'receivable' ? 'receivable' : 'payable') as OtherItemSide,
  kind: 'other',
  title: '',
  partner_id: null as number | null,
  partner_name: '',
  issued_on: today,
  accounting_on: today,
  due_on: today,
  amount: null as number | null,
  variable_symbol: '',
  account_code: '',
  counter_account_code: '',
  note: '',
})

const BUILTIN_KINDS = ['rent', 'deposit', 'loan', 'insurance', 'fee', 'claim'] as const
// Vlastní druh se ve výběru odliší předponou, vestavěné druhy zůstávají kódem.
const CUSTOM_PREFIX = 'custom:'
// Skrytý druh se nenabízí; koncept, který ho už nese, ho ve výběru vidí dál.
const offered = (kind: OtherItemKind | undefined, value: string) => !kind || kind.is_active || value === storedKind.value
const kindChoices = computed(() => [
  ...BUILTIN_KINDS.filter(code => offered(
    kinds.value.find(kind => kind.builtin && kind.side === form.side && kind.builtin_code === code), code)),
  'other',
])
const customKindChoices = computed(() => kinds.value.filter(kind =>
  !kind.builtin && kind.side === form.side && offered(kind, `${CUSTOM_PREFIX}${kind.id}`)))
const accountChoices = computed(() => accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))
const balanceAccountChoices = computed(() => accountChoices.value.filter(a =>
  a.account_type === (form.side === 'receivable' ? 'asset' : 'liability'),
))
function onSideChange() {
  form.kind = 'other'
  form.account_code = ''
  form.counter_account_code = ''
  splitPosting.value = false
  postingLines.value = []
}
function startSplitPosting() {
  postingLines.value = [
    { account_code: form.counter_account_code, amount: Number(form.amount || 0) },
    { account_code: '', amount: 0 },
  ]
  splitPosting.value = true
}
function stopSplitPosting() {
  form.counter_account_code = postingLines.value[0]?.account_code || ''
  postingLines.value = []
  splitPosting.value = false
}
function onPartnerNameInput() {
  form.partner_id = null
}
function onPartnerSelected(client: Client | null) {
  form.partner_name = client?.company_name || ''
}

async function load() {
  loading.value = true
  try {
    const requests: Promise<unknown>[] = []
    if (isDoubleEntry.value) requests.push(accountingApi.listAccounts().then(value => { accounts.value = value }))
    requests.push(otherItemKindsApi.list().then(value => { kinds.value = value }))
    if (isEdit.value) requests.push(otherItemsApi.get(editId.value).then(item => {
      if (item.status !== 'draft') {
        void router.replace({ name: 'other-item-detail', params: { id: editId.value } })
        return
      }
      form.side = item.side
      form.kind = item.kind_id ? `${CUSTOM_PREFIX}${item.kind_id}` : item.kind
      storedKind.value = form.kind
      form.title = item.title
      form.partner_id = item.partner_id ?? null
      form.partner_name = item.partner_name || ''
      form.issued_on = item.issued_on || today
      form.accounting_on = item.accounting_on || item.issued_on || today
      form.due_on = item.due_on || today
      form.amount = Number(item.amount)
      form.variable_symbol = item.variable_symbol || ''
      form.account_code = item.account_code || ''
      form.counter_account_code = item.counter_account_code || ''
      const lines = item.posting_lines || []
      splitPosting.value = lines.length > 1
      postingLines.value = splitPosting.value ? lines.map(line => ({ account_code: line.account_code, amount: Number(line.amount) })) : []
      if (!splitPosting.value && lines.length === 1) form.counter_account_code = lines[0].account_code
      form.note = item.note || ''
    }))
    if (isEdit.value) requests.push(docDims.load(editId.value))
    await Promise.all(requests)
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
    dimsReady.value = true
  }
}

// Výchozí dimenze protistrany doplní prázdné typy hlavičky.
docDims.watchDefaults(
  () => ({ client_id: form.partner_id }),
  () => dimsReady.value,
  () => !isEdit.value,
)

async function save() {
  if (saving.value) return
  if (!form.title.trim() || !form.kind || !form.issued_on || !form.due_on || !form.amount || form.amount <= 0) {
    toast.error(t('other_items.validation_required'))
    return
  }
  if (splitPosting.value && (postingLines.value.some(line => !line.account_code || !Number.isFinite(line.amount) || line.amount <= 0)
    || postingLines.value.reduce((sum, line) => sum + Math.round(line.amount * 100), 0) !== Math.round(Number(form.amount) * 100))) {
    toast.error(t('other_items.posting_lines_invalid'))
    return
  }
  const customKindId = form.kind.startsWith(CUSTOM_PREFIX) ? Number(form.kind.slice(CUSTOM_PREFIX.length)) : null
  const payload: OtherItemPayload = {
    side: form.side,
    kind: customKindId ? 'other' : form.kind,
    kind_id: customKindId,
    title: form.title.trim(),
    partner_id: form.partner_id,
    partner_name: form.partner_name.trim() || null,
    issued_on: form.issued_on,
    accounting_on: form.accounting_on || form.issued_on,
    due_on: form.due_on,
    currency: 'CZK',
    exchange_rate: null,
    amount: Number(form.amount),
    variable_symbol: form.variable_symbol.trim() || null,
    account_code: isDoubleEntry.value ? form.account_code || null : null,
    counter_account_code: isDoubleEntry.value && !splitPosting.value ? form.counter_account_code || null : null,
    // Úprava je částečná — bez rozpadu se posílá prázdný seznam, jinak by zůstal uložený.
    posting_lines: isDoubleEntry.value && splitPosting.value ? postingLines.value : [],
    note: form.note.trim() || null,
  }
  saving.value = true
  try {
    const result = isEdit.value ? await otherItemsApi.update(editId.value, payload) : await otherItemsApi.create(payload)
    await docDims.save(isEdit.value ? editId.value : Number(result.id))
    toast.success(t('common.saved'))
    await router.push({ name: 'other-item-detail', params: { id: result.source_id || result.id } })
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <RouterLink :to="isEdit ? { name: 'other-item-detail', params: { id: editId } } : { name: 'other-items' }" class="text-sm text-primary-700 hover:underline">← {{ t('other_items.back') }}</RouterLink>
        <h1 class="mt-1 text-2xl font-semibold">{{ t(isEdit ? 'other_items.edit' : 'other_items.new') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('other_items.form_hint') }}</p>
      </div>
    </div>
    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <form v-else class="max-w-4xl space-y-4" @submit.prevent="save">
      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-4 font-semibold">{{ t('other_items.details') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="text-sm font-medium">{{ t('other_items.side_label') }}
            <select v-model="form.side" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" @change="onSideChange">
              <option value="receivable">{{ t('other_items.side.receivable') }}</option>
              <option value="payable">{{ t('other_items.side.payable') }}</option>
            </select>
          </label>
          <label class="text-sm font-medium">{{ t('other_items.kind_label') }}
            <select v-model="form.kind" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
              <option v-for="kind in kindChoices" :key="kind" :value="kind">{{ t(`other_items.kind_by_side.${form.side}.${kind}`) }}</option>
              <optgroup v-if="customKindChoices.length" :label="t('other_items.kinds.custom_group')">
                <option v-for="kind in customKindChoices" :key="`${CUSTOM_PREFIX}${kind.id}`" :value="`${CUSTOM_PREFIX}${kind.id}`">{{ kind.name }}</option>
              </optgroup>
            </select>
          </label>
          <label class="text-sm font-medium sm:col-span-2">{{ t('other_items.item') }}
            <input v-model="form.title" required maxlength="255" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
          <label class="text-sm font-medium sm:col-span-2">{{ t('other_items.partner_directory') }}
            <ClientSearchSelect v-model="form.partner_id" :selected-label="form.partner_name" :role="form.side === 'payable' ? 'vendors' : 'customers'" class="mt-1 block" @selected="onPartnerSelected" />
          </label>
          <label class="text-sm font-medium sm:col-span-2">{{ t('other_items.partner') }}
            <input v-model="form.partner_name" maxlength="190" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" @input="onPartnerNameInput" />
          </label>
          <label class="text-sm font-medium">{{ t('other_items.issued_on') }}
            <DateInput v-model="form.issued_on" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('other_items.due_on') }}
            <DateInput v-model="form.due_on" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('other_items.amount') }}
            <input v-model.number="form.amount" required type="number" min="0.01" step="0.01" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-right" />
          </label>
          <label class="text-sm font-medium">{{ t('other_items.currency') }}
            <input value="CZK" readonly class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-neutral-50 px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('other_items.variable_symbol') }}
            <input v-model="form.variable_symbol" maxlength="20" inputmode="numeric" pattern="[0-9]*" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
          <div v-if="docDims.enabled.value" class="sm:col-span-2" data-test="other-item-dimensions">
            <p class="mb-1 block text-sm font-medium text-neutral-700">{{ t('dimensions.header_title') }}</p>
            <DimensionFields v-model="docDims.header.value" :disabled="!docDims.canEdit.value" />
            <p v-if="docDims.hasAutoFilled.value" class="mt-1 text-xs text-neutral-500" data-test="dimension-autofilled">{{ t('dimensions.defaults.autofilled') }}</p>
          </div>
        </div>
      </section>
      <section v-if="isDoubleEntry" class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-1 font-semibold">{{ t('other_items.accounting') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('other_items.accounting_hint') }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="text-sm font-medium">{{ t('other_items.accounting_on') }}
            <DateInput v-model="form.accounting_on" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
          </label>
          <div></div>
          <label class="text-sm font-medium">{{ t('other_items.account_code') }}
            <ChartAccountSelect v-model="form.account_code" :accounts="balanceAccountChoices" :placeholder="t('other_items.choose_account')" class="mt-1 block" />
          </label>
          <label v-if="!splitPosting" class="text-sm font-medium">{{ t('other_items.counter_account_code') }}
            <ChartAccountSelect v-model="form.counter_account_code" :accounts="accountChoices" :placeholder="t('other_items.choose_account')" class="mt-1 block" />
          </label>
        </div>
        <div v-if="splitPosting" class="mt-4">
          <OtherItemPostingLines v-model="postingLines" :accounts="accountChoices" :total="Number(form.amount || 0)" />
        </div>
        <button v-if="!splitPosting" type="button" :class="[btnOutline('neutral'), 'mt-4 whitespace-nowrap']" @click="startSplitPosting">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('other_items.split_posting') }}
        </button>
        <button v-else type="button" :class="[btnOutline('neutral'), 'mt-3 whitespace-nowrap']" @click="stopSplitPosting">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('other_items.single_posting') }}
        </button>
      </section>
      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <label class="text-sm font-medium">{{ t('other_items.note') }}
          <textarea v-model="form.note" rows="3" maxlength="2000" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface p-2" />
        </label>
      </section>
      <div class="flex flex-wrap gap-2">
        <button type="submit" :disabled="saving" :class="btnFilled('primary')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
        <RouterLink :to="isEdit ? { name: 'other-item-detail', params: { id: editId } } : { name: 'other-items' }" :class="btnOutline('neutral')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </RouterLink>
      </div>
    </form>
  </div>
</template>
