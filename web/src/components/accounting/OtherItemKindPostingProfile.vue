<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChartAccount } from '@/api/accounting'
import type { DimensionMap } from '@/api/dimensions'
import { otherItemKindsApi, type OtherItemKind, type OtherItemPostingEvent, type OtherItemPostingRuleInput } from '@/api/otherItems'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilledSm, btnOutlineSm } from '@/components/ui/buttonStyles'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'

/**
 * Účetní profil jednoho druhu: kontace vzniku (účet položky a protiúčet), úhrady z banky
 * a z pokladny (peněžní účet; strana položky je vždy účet položky), popis zápisu a výchozí
 * dimenze nového dokladu. Návrh pro vestavěný druh se jen nabízí, nic se neuloží samo.
 */
const props = defineProps<{ kind: OtherItemKind; accounts: ChartAccount[]; canWrite: boolean }>()
const emit = defineEmits<{ (e: 'saved'): void }>()

const { t } = useI18n()
const toast = useToast()
const saving = ref(false)
const events: OtherItemPostingEvent[] = ['origin', 'payment_bank', 'payment_cash']
const MONEY_PREFIX: Record<string, string> = { payment_bank: '221', payment_cash: '211' }
const receivable = computed(() => props.kind.side === 'receivable')
type Column = 'debit_account_code' | 'credit_account_code'

/** Sloupec účtu položky: vznik pohledávky jde na MD, úhrada pohledávky na D, závazek obráceně. */
function itemColumn(event: OtherItemPostingEvent): Column {
  const debit = event === 'origin' ? receivable.value : !receivable.value
  return debit ? 'debit_account_code' : 'credit_account_code'
}
const otherColumn = (event: OtherItemPostingEvent): Column =>
  itemColumn(event) === 'debit_account_code' ? 'credit_account_code' : 'debit_account_code'

interface EventForm { item: string; other: string; template: string; active: boolean; dims: DimensionMap }
const form = reactive<Record<OtherItemPostingEvent, EventForm>>(Object.fromEntries(events.map(event => {
  const profile = props.kind.posting_profiles?.[event] ?? null
  const dims: DimensionMap = {}
  for (const row of profile?.dimensions ?? []) dims[row.type_id] = row.value_id
  return [event, {
    item: profile?.[itemColumn(event)] ?? '',
    other: profile?.[otherColumn(event)] ?? '',
    template: profile?.description_template ?? '',
    active: profile?.is_active ?? true,
    dims,
  }]
})) as Record<OtherItemPostingEvent, EventForm>)

const activeAccounts = computed(() => props.accounts.filter(account => account.is_active)
  .sort((a, b) => a.account_code.localeCompare(b.account_code)))
const balanceAccounts = computed(() => activeAccounts.value.filter(account =>
  account.account_type === (receivable.value ? 'asset' : 'liability')))
const moneyAccounts = (event: OtherItemPostingEvent) => activeAccounts.value.filter(account =>
  account.account_code.startsWith(MONEY_PREFIX[event]))
const suggestion = computed(() => props.kind.posting_suggestion ?? null)

function useSuggestion() {
  if (!suggestion.value) return
  form.origin.item = suggestion.value[itemColumn('origin')]
  form.origin.other = suggestion.value[otherColumn('origin')]
}

function payload(event: OtherItemPostingEvent): OtherItemPostingRuleInput | null {
  const row = form[event]
  const item = event === 'origin' ? row.item.trim() : ''
  const other = row.other.trim()
  const dimensions = event === 'origin'
    ? Object.entries(row.dims).filter(([, value]) => value).map(([type, value]) => ({ type_id: Number(type), value_id: Number(value) }))
    : []
  if (!item && !other && !row.template.trim() && dimensions.length === 0) return null
  return {
    [itemColumn(event)]: item || null,
    [otherColumn(event)]: other || null,
    description_template: row.template.trim() || null,
    dimensions,
    is_active: row.active,
  } as unknown as OtherItemPostingRuleInput
}

async function save() {
  if (saving.value) return
  saving.value = true
  try {
    await otherItemKindsApi.savePostingProfiles(props.kind, Object.fromEntries(events.map(event => [event, payload(event)])))
    toast.success(t('other_items.posting.kinds.saved'))
    emit('saved')
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form class="space-y-4 rounded-md border border-neutral-200 bg-neutral-50 p-3" data-test="kind-posting-profile" @submit.prevent="save">
    <p class="text-xs text-neutral-500">{{ t('other_items.posting.kinds.hint') }}</p>
    <div v-if="suggestion" class="flex flex-wrap items-center gap-2 text-xs" data-test="kind-posting-suggestion">
      <span class="text-neutral-600">{{ t('other_items.posting.kinds.suggestion', { debit: suggestion.debit_account_code, credit: suggestion.credit_account_code }) }}</span>
      <button v-if="canWrite && suggestion.available" type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="useSuggestion">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>
        {{ t('other_items.posting.kinds.use_suggestion') }}
      </button>
      <span v-else-if="!suggestion.available" class="text-warning-700">{{ t('other_items.posting.kinds.suggestion_unavailable') }}</span>
    </div>
    <fieldset v-for="event in events" :key="event" :disabled="!canWrite" class="space-y-2" :data-test="`kind-posting-${event}`">
      <legend class="text-sm font-semibold">{{ t(`other_items.posting.event.${event}`) }}</legend>
      <div class="grid gap-3 sm:grid-cols-2">
        <label v-if="event === 'origin'" class="text-sm font-medium">{{ t('other_items.posting.kinds.item_account') }} ({{ t(itemColumn(event) === 'debit_account_code' ? 'other_items.posting.debit' : 'other_items.posting.credit') }})
          <ChartAccountSelect v-model="form[event].item" :accounts="balanceAccounts" :placeholder="receivable ? '315' : '325'" class="mt-1 block" />
        </label>
        <div v-else class="text-sm">
          <span class="font-medium">{{ t('other_items.posting.kinds.item_account') }} ({{ t(itemColumn(event) === 'debit_account_code' ? 'other_items.posting.debit' : 'other_items.posting.credit') }})</span>
          <p class="mt-2 text-neutral-500">{{ t('other_items.posting.item_account') }}</p>
        </div>
        <label class="text-sm font-medium">{{ t(event === 'origin' ? 'other_items.posting.kinds.counter_account' : 'other_items.posting.money_account') }} ({{ t(otherColumn(event) === 'debit_account_code' ? 'other_items.posting.debit' : 'other_items.posting.credit') }})
          <ChartAccountSelect v-model="form[event].other" :accounts="event === 'origin' ? activeAccounts : moneyAccounts(event)"
            :placeholder="event === 'origin' ? t('other_items.choose_account') : MONEY_PREFIX[event]" class="mt-1 block" />
          <span v-if="event !== 'origin'" class="mt-1 block text-xs font-normal text-neutral-500">{{ t(`other_items.posting.money_hint.${event}`) }}</span>
        </label>
        <label class="text-sm font-medium sm:col-span-2">{{ t('other_items.posting.description_template') }}
          <input v-model="form[event].template" maxlength="255" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
        </label>
        <div v-if="event === 'origin'" class="sm:col-span-2">
          <p class="mb-1 text-sm font-medium">{{ t('other_items.posting.kinds.dimensions') }}</p>
          <DimensionFields v-model="form.origin.dims" :disabled="!canWrite" />
        </div>
        <label class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="form[event].active" type="checkbox" class="rounded border-neutral-300" />
          {{ t('other_items.posting.kinds.active') }}
        </label>
      </div>
    </fieldset>
    <p class="text-xs text-neutral-500">{{ t('other_items.posting.description_template_hint') }} {{ t('other_items.posting.future_only') }}</p>
    <div v-if="canWrite" class="flex flex-wrap gap-2">
      <button type="submit" :disabled="saving" :class="[btnFilledSm('primary'), 'whitespace-nowrap']">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ t('common.save') }}
      </button>
    </div>
  </form>
</template>
