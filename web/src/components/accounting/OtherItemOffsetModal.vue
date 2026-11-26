<script setup lang="ts">
/**
 * Vyrovnání ostatní pohledávky / závazku zápočtem (OtherItemOffsetService):
 *
 *   post   → zaúčtuje interní doklad zápočtu (pohledávka: protiúčet MD / účet položky D,
 *            závazek obráceně) a jeho řádek přiřadí jako úhradu,
 *   attach → zápočet je už zaúčtovaný (ruční zápis, interní doklad); vybraný řádek deníku
 *            na účtu položky se jen přiřadí.
 *
 * Částečné vyrovnání je povolené, výchozí částka je zbývající dluh.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherItemsApi, type OtherItem, type OtherItemOffsetContext, type OtherItemOffsetLine } from '@/api/otherItems'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { apiErrorCode } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import OpsTabBar from '@/components/property/payments/operations/OpsTabBar.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'

type Tab = 'post' | 'attach'

const props = defineProps<{
  item: OtherItem
  /** Zaúčtovat interní doklad smí jen s právem zaúčtování deníku. */
  canPost: boolean
  /** Přiřadit existující zápis smí jen s právem zápisu do deníku. */
  canAttach: boolean
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done', item: OtherItem): void
}>()

const { t, te } = useI18n()
const toast = useToast()

const tabs = computed(() => [
  ...(props.canPost ? [{ key: 'post', label: t('other_items.offset.tab_post') }] : []),
  ...(props.canAttach ? [{ key: 'attach', label: t('other_items.offset.tab_attach') }] : []),
])
const tab = ref<Tab>(props.canPost ? 'post' : 'attach')
const context = ref<OtherItemOffsetContext | null>(null)
const accounts = ref<ChartAccount[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')

const accountCode = ref('')
const date = ref(appIsoDate())
const amount = ref<number | null>(null)
const note = ref('')

const search = ref('')
const lines = ref<OtherItemOffsetLine[]>([])
const linesLoading = ref(false)
const linesLoaded = ref(false)
const selectedLine = ref<OtherItemOffsetLine | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let linesRequest = 0

const itemId = computed(() => Number(props.item.id))
const remaining = computed(() => context.value?.remaining ?? Number(props.item.remaining_amount ?? 0))
const itemAccount = computed(() => context.value?.account?.code ?? '')
const receivable = computed(() => props.item.side === 'receivable')
// Účtovat lze na analytiku nebo syntetiku bez analytik (stejně jako zápočet faktury). Protiúčet
// je rozvahový účet mimo DPH 343 a účet položky (server to ověří stejně).
const postingAccounts = computed(() => {
  const parents = new Set(accounts.value.map(a => a.parent_id).filter((v): v is number => v !== null))
  const itemSynthetic = itemAccount.value.slice(0, 3)
  return accounts.value.filter(a => a.is_active && !parents.has(a.id)
    && ['asset', 'liability', 'equity'].includes(String(a.account_type))
    && !a.account_code.startsWith('343') && (itemSynthetic === '' || !a.account_code.startsWith(itemSynthetic)))
})
const counterAccount = computed(() => postingAccounts.value.find(a => a.account_code === accountCode.value) ?? null)
const postingHint = computed(() => {
  if (!counterAccount.value || !itemAccount.value) return ''
  return receivable.value
    ? `${counterAccount.value.account_code} MD / ${itemAccount.value} D`
    : `${itemAccount.value} MD / ${counterAccount.value.account_code} D`
})
const maxAmount = computed(() => tab.value === 'attach' && selectedLine.value
  ? Math.min(selectedLine.value.free_amount, remaining.value)
  : remaining.value)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [ctx, all] = await Promise.all([
      otherItemsApi.offsetContext(itemId.value),
      props.canPost ? accountingApi.listAccounts() : Promise.resolve([] as ChartAccount[]),
    ])
    context.value = ctx
    accounts.value = all
    amount.value = ctx.remaining
    accountCode.value = ctx.suggested_accounts[0]?.account_code ?? ''
    if (!ctx.supported) error.value = t('other_items.offset.unsupported')
    if (tab.value === 'attach') void loadLines()
  } catch (e) {
    error.value = errorText(e)
  } finally {
    loading.value = false
  }
}

async function loadLines() {
  const seq = ++linesRequest
  linesLoading.value = true
  try {
    const rows = await otherItemsApi.offsetLines(itemId.value, search.value.trim())
    if (seq !== linesRequest) return
    lines.value = rows
    linesLoaded.value = true
  } catch (e) {
    if (seq === linesRequest) error.value = errorText(e)
  } finally {
    if (seq === linesRequest) linesLoading.value = false
  }
}

function pickLine(line: OtherItemOffsetLine) {
  selectedLine.value = line
  amount.value = Math.min(line.free_amount, remaining.value)
}

watch(tab, value => {
  error.value = context.value && !context.value.supported ? t('other_items.offset.unsupported') : ''
  amount.value = remaining.value
  selectedLine.value = null
  if (value === 'attach' && !linesLoaded.value) void loadLines()
})
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { void loadLines() }, 300)
})
onMounted(load)

/**
 * Kód chyby s vlastním překladem (other_items.error.*) má přednost před českou hláškou serveru.
 * Podrobnost (zbývající částka, datum vzniku položky, číslo zápisu) posílá server v `details`.
 */
function errorText(e: unknown): string {
  const code = apiErrorCode(e)
  if (code.startsWith('other_items.error.') && te(code)) return t(code, errorParams((e as any)?.response?.data?.error?.details))
  return (e as any)?.response?.data?.error?.message || t('common.error')
}

function errorParams(details: unknown): Record<string, string> {
  const raw = details && typeof details === 'object' ? details as Record<string, unknown> : {}
  const money = (v: unknown) => typeof v === 'number' ? formatMoney(v, props.item.currency) : ''
  return {
    remaining: money(raw.remaining),
    free: money(raw.free),
    item_date: typeof raw.item_date === 'string' ? formatDate(raw.item_date) : '',
    document_no: typeof raw.document_no === 'string' ? raw.document_no : '',
    account: typeof raw.account === 'string' ? raw.account : '',
  }
}

async function submit() {
  error.value = ''
  const value = Number(amount.value)
  if (!(value > 0)) { error.value = t('other_items.offset.amount_required'); return }
  saving.value = true
  try {
    let updated: OtherItem
    if (tab.value === 'post') {
      if (!counterAccount.value) { error.value = t('other_items.offset.account_required'); return }
      updated = await otherItemsApi.offset(itemId.value, {
        account_id: counterAccount.value.id, date: date.value, amount: value, note: note.value.trim() || null,
      })
    } else {
      if (!selectedLine.value) { error.value = t('other_items.offset.line_required'); return }
      updated = await otherItemsApi.attachOffset(itemId.value, { line_id: selectedLine.value.line_id, amount: value })
    }
    toast.success(t(tab.value === 'post' ? 'other_items.offset.posted' : 'other_items.offset.attached'))
    emit('done', updated)
  } catch (e) {
    error.value = errorText(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :title="t('other_items.offset.title')" width-class="max-w-xl" @close="emit('close')">
    <div class="space-y-4 text-sm" data-test="offset-modal">
      <p class="text-neutral-600">
        {{ t('other_items.offset.intro', { number: item.document_no || '', remaining: formatMoney(remaining, item.currency) }) }}
      </p>
      <OpsTabBar v-if="tabs.length > 1" :model-value="tab" :tabs="tabs" :disabled="saving" @update:model-value="tab = $event as Tab" />

      <div v-if="loading" class="py-3 text-center text-neutral-500">{{ t('common.loading') }}</div>

      <template v-else-if="context?.supported">
        <section v-if="tab === 'post'" role="tabpanel" class="space-y-3" data-test="offset-post">
          <div>
            <label class="block font-medium" for="other-item-offset-account">{{ t('other_items.offset.account') }}</label>
            <ChartAccountSelect v-model="accountCode" input-id="other-item-offset-account" :accounts="postingAccounts"
                                :placeholder="t('other_items.choose_account')" class="mt-1 block" />
            <div v-if="context.suggested_accounts.length" class="mt-2 flex flex-wrap items-center gap-1.5" data-test="offset-suggested">
              <span class="text-xs text-neutral-500">{{ t('other_items.offset.suggested') }}</span>
              <button v-for="suggested in context.suggested_accounts" :key="suggested.account_id" type="button"
                      :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :title="suggested.name"
                      :aria-pressed="accountCode === suggested.account_code" @click="accountCode = suggested.account_code">
                <span class="font-mono">{{ suggested.account_code }}</span>
              </button>
            </div>
            <p class="mt-1 text-xs text-neutral-500">{{ t(receivable ? 'other_items.offset.account_hint_receivable' : 'other_items.offset.account_hint_payable') }}</p>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block font-medium">{{ t('other_items.offset.date') }}
              <DateInput v-model="date" required class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
            </label>
            <label class="block font-medium">{{ t('other_items.offset.amount') }}
              <input v-model.number="amount" type="number" min="0.01" step="0.01" :max="maxAmount" required
                     class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-right tabular-nums" />
            </label>
          </div>
          <label class="block font-medium">{{ t('other_items.offset.note') }}
            <input v-model="note" type="text" maxlength="500" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
          <p class="text-xs text-neutral-500">{{ t('other_items.offset.partial_hint') }}</p>
          <p v-if="postingHint" class="text-xs text-neutral-500" data-test="offset-posting-hint">
            {{ t('other_items.offset.will_post') }}: <span class="font-mono">{{ postingHint }}</span> - {{ formatMoney(Number(amount || 0), item.currency) }}
          </p>
        </section>

        <section v-else role="tabpanel" class="space-y-3" data-test="offset-attach">
          <p class="text-xs text-neutral-500">{{ t('other_items.offset.attach_hint', { account: itemAccount, side: t(context.side === 'credit' ? 'other_items.posting.credit' : 'other_items.posting.debit') }) }}</p>
          <label class="block font-medium">{{ t('other_items.offset.search_line') }}
            <input v-model="search" type="search" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
          <div v-if="linesLoading" class="text-xs text-neutral-500">{{ t('common.loading') }}</div>
          <p v-else-if="linesLoaded && !lines.length" class="text-neutral-500" data-test="offset-no-lines">{{ t('other_items.offset.no_lines') }}</p>
          <div v-if="lines.length" class="max-h-56 overflow-y-auto rounded-md border border-neutral-200" role="listbox" :aria-label="t('other_items.offset.search_line')">
            <button v-for="line in lines" :key="line.line_id" type="button" role="option" :aria-selected="selectedLine?.line_id === line.line_id"
                    class="flex w-full flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-3 py-2 text-left hover:bg-neutral-50"
                    :class="selectedLine?.line_id === line.line_id ? 'bg-primary-50' : ''" data-test="offset-line" @click="pickLine(line)">
              <span class="min-w-0">
                <span class="font-medium">{{ line.document_no || `#${line.entry_id}` }}</span>
                <span class="ml-2 text-neutral-500">{{ formatDate(line.entry_date) }}<template v-if="line.description"> · {{ line.description }}</template></span>
              </span>
              <span class="tabular-nums">
                <strong>{{ formatMoney(line.free_amount, 'CZK') }}</strong>
                <span v-if="line.free_amount !== line.line_amount" class="ml-1 text-xs text-neutral-500">/ {{ formatMoney(line.line_amount, 'CZK') }}</span>
              </span>
            </button>
          </div>
          <label v-if="selectedLine" class="block font-medium">{{ t('other_items.offset.amount') }}
            <input v-model.number="amount" type="number" min="0.01" step="0.01" :max="maxAmount" required
                   class="mt-1 block h-9 w-40 rounded-md border border-neutral-300 bg-surface px-2 text-right tabular-nums" />
          </label>
        </section>
      </template>

      <p v-if="error" class="text-danger-600" role="alert" data-test="offset-error">{{ error }}</p>
    </div>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" :disabled="saving || loading || !context?.supported || (tab === 'attach' && !selectedLine)"
                :class="btnFilled('success')" class="whitespace-nowrap" data-test="offset-submit" @click="submit">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="tab === 'post' ? ICONS.swap : ICONS.link" /></svg>
          {{ saving ? t('common.saving') : t(tab === 'post' ? 'other_items.offset.submit_post' : 'other_items.offset.submit_attach') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
