<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChartAccount } from '@/api/accounting'
import { otherItemsApi, type OtherItem, type OtherItemEffectivePosting, type OtherItemOverrideEvent, type OtherItemPostingEvent } from '@/api/otherItems'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutlineSm, btnFilledSm } from '@/components/ui/buttonStyles'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'

/**
 * Účinná kontace vzniku a úhrad položky s původem (vlastní, zděděná z druhu, výchozí)
 * a vlastní kontace úhrad. Vznik nese položka v účtu a protiúčtu (editor, přeúčtování),
 * tady se jen ukazuje. Úrok a poplatek rozdělené splátky (úvěr, leasing) mají jen účet
 * nákladu nebo výnosu; ukazují se u půjčky a u položky s rozdělenými splátkami.
 * Změna platí jen pro další zaúčtování.
 */
const props = defineProps<{
  item: OtherItem
  accounts: ChartAccount[]
  canWrite: boolean
  canRepost?: boolean
}>()
const emit = defineEmits<{ (e: 'changed', item: OtherItem): void; (e: 'repost'): void }>()

const { t } = useI18n()
const toast = useToast()
const editing = ref<OtherItemOverrideEvent | null>(null)
const saving = ref(false)
const form = reactive({ money: '', template: '' })
const MONEY_PREFIX: Record<string, string> = { payment_bank: '221', payment_cash: '211' }
const isComponent = (event: OtherItemPostingEvent) => event === 'interest' || event === 'fee'

const showComponents = computed(() => props.item.has_split_installments === true || props.item.kind === 'loan')
const events = computed<OtherItemPostingEvent[]>(() => [
  ...(props.item.status === 'draft' ? ['origin' as const] : []),
  'payment_bank', 'payment_cash',
  ...(showComponents.value ? ['interest' as const, 'fee' as const] : []),
])
const showPosted = computed(() => ['confirmed', 'posted'].includes(String(props.item.status)))
/** Vznik tak, jak je v položce: účet položky a protiúčet nebo rozpad na protiřádky. */
const postedOrigin = computed(() => {
  const receivable = props.item.side === 'receivable'
  const itemCode = props.item.account_code || (receivable ? '315' : '325')
  const own = (props.item.posting_lines || []).map(line => ({ account_code: line.account_code, amount: Number(line.amount) }))
  const counters = own.length > 0 ? own
    : props.item.counter_account_code ? [{ account_code: props.item.counter_account_code, amount: Number(props.item.amount) }] : []
  return {
    item: { account_code: itemCode, side: receivable ? 'debit' : 'credit', amount: Number(props.item.amount) },
    counters: counters.map(line => ({ ...line, side: receivable ? 'credit' : 'debit' })),
  }
})
const active = computed(() => ['draft', 'confirmed', 'posted'].includes(String(props.item.status)))
const rule = (event: OtherItemPostingEvent): OtherItemEffectivePosting | undefined => props.item.posting_rules?.[event]
/** Upravovaný sloupec: peněžní účet úhrady, u úroku a poplatku účet nákladu (závazek) či výnosu (pohledávka). */
const editColumn = (event: OtherItemPostingEvent): 'debit_account_code' | 'credit_account_code' =>
  (props.item.side === 'receivable') !== isComponent(event) ? 'debit_account_code' : 'credit_account_code'
const moneyChoices = computed(() => editing.value === null ? [] : props.accounts.filter(account => account.is_active
  && (isComponent(editing.value!)
    ? account.account_type === (props.item.side === 'receivable' ? 'revenue' : 'expense')
    : account.account_code.startsWith(MONEY_PREFIX[editing.value!]))))

function sourceClass(source: string): string {
  if (source === 'item') return 'bg-primary-50 text-primary-700'
  if (source === 'kind') return 'bg-neutral-100 text-neutral-600'
  return 'bg-neutral-50 text-neutral-500'
}

function startEdit(event: OtherItemOverrideEvent) {
  const own = props.item.posting_overrides?.[event]
  form.money = (own?.[editColumn(event)] ?? '') || ''
  form.template = own?.description_template ?? rule(event)?.description_template ?? ''
  editing.value = event
}

async function save(event: OtherItemOverrideEvent, reset = false) {
  if (saving.value) return
  saving.value = true
  try {
    const column = editColumn(event)
    const updated = await otherItemsApi.setPostingOverrides(Number(props.item.source_id || props.item.id), {
      [event]: reset ? null : {
        debit_account_code: column === 'debit_account_code' ? form.money.trim() || null : null,
        credit_account_code: column === 'credit_account_code' ? form.money.trim() || null : null,
        description_template: form.template.trim() || null,
      },
    })
    editing.value = null
    toast.success(t('other_items.posting.saved'))
    emit('changed', updated)
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="mt-4 rounded-lg border border-neutral-200 bg-surface p-4" data-test="other-item-posting-rules">
    <h2 class="font-semibold">{{ t('other_items.posting.title') }}</h2>
    <p class="mt-1 text-sm text-neutral-500">{{ t('other_items.posting.hint') }}</p>
    <div v-if="showPosted" class="mt-3 border-b border-neutral-100 pb-3" data-test="posted-origin">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 text-sm">
          <span class="font-medium">{{ t('other_items.posting.event.origin') }}</span>
          <ul class="mt-1 space-y-0.5 tabular-nums">
            <li v-for="(line, index) in [postedOrigin.item, ...postedOrigin.counters]" :key="index" class="flex flex-wrap gap-2">
              <span class="w-8 text-neutral-500">{{ t(line.side === 'debit' ? 'other_items.posting.debit' : 'other_items.posting.credit') }}</span>
              <span class="font-mono">{{ line.account_code }}</span>
              <span class="text-neutral-500">{{ formatMoney(line.amount, item.currency) }}</span>
            </li>
            <li v-if="postedOrigin.counters.length === 0" class="text-warning-700">{{ t('other_items.posting.missing_counter') }}</li>
          </ul>
          <p class="mt-1 text-xs text-neutral-500">{{ t('other_items.posting.repost_hint') }}</p>
        </div>
        <button v-if="canRepost" type="button" :disabled="Number(item.paid_amount) > 0" :title="Number(item.paid_amount) > 0 ? t('other_items.repost_paid') : undefined"
                :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" data-test="posting-repost" @click="emit('repost')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
          {{ t('other_items.repost') }}
        </button>
      </div>
    </div>
    <div class="mt-3 divide-y divide-neutral-100">
      <div v-for="event in events" :key="event" class="py-3" :data-test="`posting-rule-${event}`">
        <div v-if="rule(event)" class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 text-sm">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium">{{ t(`other_items.posting.event.${event}`) }}</span>
              <span class="rounded px-1.5 py-0.5 text-xs" :class="sourceClass(rule(event)!.source)" data-test="posting-source">{{ t(`other_items.posting.source.${rule(event)!.source}`) }}</span>
              <RouterLink v-if="rule(event)!.source === 'kind'" :to="{ name: 'other-items', query: { kinds: '1' } }" class="text-xs text-primary-700 hover:underline">{{ t('other_items.posting.open_kinds') }}</RouterLink>
            </div>
            <ul v-if="event === 'origin'" class="mt-1 space-y-0.5 tabular-nums">
              <li v-for="(line, index) in rule(event)!.lines || []" :key="index" class="flex flex-wrap gap-2">
                <span class="w-8 text-neutral-500">{{ t(line.side === 'debit' ? 'other_items.posting.debit' : 'other_items.posting.credit') }}</span>
                <span class="font-mono">{{ line.account_code }}</span>
                <span class="text-neutral-500">{{ formatMoney(line.amount, item.currency) }}</span>
              </li>
              <li v-if="(rule(event)!.posting_lines || []).length === 0" class="text-warning-700">{{ t('other_items.posting.missing_counter') }}</li>
            </ul>
            <p v-else-if="isComponent(event)" class="mt-1 tabular-nums">
              <span class="text-neutral-500">{{ t(editColumn(event) === 'debit_account_code' ? 'other_items.posting.debit' : 'other_items.posting.credit') }}</span> <span class="font-mono">{{ rule(event)!.account_code }}</span>
              <span class="ml-2 text-xs text-neutral-500">{{ t('other_items.posting.component_money') }}</span>
            </p>
            <p v-else class="mt-1 tabular-nums">
              <span class="text-neutral-500">{{ t('other_items.posting.debit') }}</span> <span class="font-mono">{{ rule(event)!.debit_account_code }}</span>
              <span class="mx-2 text-neutral-300">/</span>
              <span class="text-neutral-500">{{ t('other_items.posting.credit') }}</span> <span class="font-mono">{{ rule(event)!.credit_account_code }}</span>
            </p>
            <p v-if="rule(event)!.description" class="mt-0.5 text-xs text-neutral-500">{{ rule(event)!.description }}</p>
            <p v-if="event === 'payment_cash'" class="mt-0.5 text-xs text-neutral-500">{{ t('other_items.posting.cash_note') }}</p>
          </div>
          <div v-if="canWrite && active && event !== 'origin' && editing !== event" class="flex flex-wrap gap-1">
            <button type="button" :disabled="saving" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" data-test="posting-own" @click="startEdit(event as OtherItemOverrideEvent)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              {{ t('other_items.posting.set_own') }}
            </button>
            <button v-if="rule(event)!.source === 'item'" type="button" :disabled="saving" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" data-test="posting-reset" @click="save(event as OtherItemOverrideEvent, true)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
              {{ t('other_items.posting.reset') }}
            </button>
          </div>
        </div>
        <form v-if="editing === event" class="mt-3 grid gap-3 sm:grid-cols-2" data-test="posting-form" @submit.prevent="save(event as OtherItemOverrideEvent)">
          <label class="text-sm font-medium">{{ isComponent(event) ? t(`other_items.posting.component_account.${event}`) : t('other_items.posting.money_account') }}
            <ChartAccountSelect v-model="form.money" :accounts="moneyChoices" :placeholder="isComponent(event) ? rule(event)?.account_code ?? '' : MONEY_PREFIX[event]" class="mt-1 block" />
            <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t(`other_items.posting.money_hint.${event}`) }}</span>
          </label>
          <label class="text-sm font-medium">{{ t('other_items.posting.description_template') }}
            <input v-model="form.template" maxlength="255" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
            <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('other_items.posting.description_template_hint') }}</span>
          </label>
          <div class="flex flex-wrap gap-2 sm:col-span-2">
            <button type="submit" :disabled="saving" :class="[btnFilledSm('primary'), 'whitespace-nowrap']">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('common.save') }}
            </button>
            <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="editing = null">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
          </div>
        </form>
      </div>
    </div>
    <p class="mt-2 text-xs text-neutral-500">{{ t('other_items.posting.future_only') }}</p>
  </section>
</template>
