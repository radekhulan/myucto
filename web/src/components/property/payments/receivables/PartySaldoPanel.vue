<script setup lang="ts">
import DateInput from '@/components/ui/DateInput.vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyReceivablesApi as api, type PartySaldoPage } from '@/api/propertyReceivables'
import type { PropertyObject } from '@/api/propertyRegistry'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { useBusyScope } from '../operations/busy'
import { failureMessage, moneyText } from '../operations/format'
import { inputClass } from '../operations/ui'

const props = defineProps<{ supplier: number; units: PropertyObject[] }>()
const { t } = useI18n()
const scope = useBusyScope()
const date = ref(new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Prague' }).format(new Date()))
const limit = ref(50)
const page = ref<PartySaldoPage | null>(null)
// Součty domu vrací jen první stránka, další stránky je přebírají.
const totals = ref<PartySaldoPage['totals']>(null)
const cursors = ref<Array<string | undefined>>([undefined])
const position = ref(0)
const loading = ref(false)
const error = ref('')
const form = ref<HTMLFormElement | null>(null)
let epoch = 0

const unitCodes = computed(() => new Map(props.units.map(unit => [unit.id, unit.code])))

function clear() {
  epoch++
  page.value = null
  totals.value = null
  cursors.value = [undefined]
  position.value = 0
  error.value = ''
  if (loading.value) {
    loading.value = false
    scope.end()
  }
}
watch(() => [props.supplier, date.value, limit.value], clear)
onBeforeUnmount(clear)

async function load(index = 0) {
  if (loading.value || scope.busy.value || !form.value?.reportValidity()) return
  const after = index === 0 ? undefined : cursors.value[index]
  const ticket = ++epoch
  loading.value = true
  scope.begin()
  error.value = ''
  try {
    const result = await api.partySaldo(props.supplier, { date: date.value, limit: limit.value, ...(after ? { after } : {}) })
    if (ticket === epoch) {
      page.value = result
      if (result.totals) totals.value = result.totals
      position.value = index
      cursors.value = cursors.value.slice(0, index + 1)
      if (result.next_cursor) cursors.value.push(result.next_cursor)
    }
  } catch (e) {
    if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed'))
  } finally {
    if (ticket === epoch) {
      loading.value = false
      scope.end()
    }
  }
}

function partyText(item: PartySaldoPage['items'][number]): string {
  if (!item.parties.length) return t('propertySaldo.parties.unattributed')
  return item.parties.map(party => party.label || t('propertySaldo.personId', { id: party.id })).join(', ')
}

function unitText(ids: number[]): string {
  return ids.map(id => unitCodes.value.get(id) ?? String(id)).join(', ')
}

const actions = computed<ActionItem[]>(() => [
  { key: 'party-saldo-load', label: t('propertySaldo.parties.load'), icon: 'search', variant: 'primary', tier: 'primary', disabled: loading.value || scope.busy.value, run: () => load() },
])
</script>

<template>
  <section class="space-y-4" data-section="party-saldo">
    <h2 class="font-semibold">{{ t('propertySaldo.parties.title') }}</h2>
    <p class="text-sm">{{ t('propertySaldo.parties.help') }}</p>
    <form ref="form" class="space-y-3" @submit.prevent="load()">
      <fieldset :disabled="loading" class="flex flex-wrap items-end gap-3">
        <label class="flex min-w-0 flex-col gap-1 text-sm">
          {{ t('propertySaldo.parties.date') }}
          <DateInput v-model="date" required :class="inputClass" data-field="party-saldo-date" />
        </label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">
          {{ t('propertySaldo.parties.pageSize') }}
          <select v-model="limit" :class="inputClass" data-field="party-saldo-limit">
            <option v-for="n in [25, 50, 100]" :key="n" :value="n">{{ n }}</option>
          </select>
        </label>
      </fieldset>
      <ActionBar :actions="actions" />
    </form>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <p v-if="loading">{{ t('propertyPayments.loading') }}</p>
    <template v-if="page">
      <dl v-if="totals" class="grid gap-x-3 gap-y-1 text-sm sm:grid-cols-2" data-party-totals>
        <dt>{{ t('propertySaldo.parties.totalReceivable') }}</dt>
        <dd data-total="receivable">{{ moneyText(totals.receivable, t('propertyPayments.unknown')) }}</dd>
        <dt>{{ t('propertySaldo.parties.totalCredit') }}</dt>
        <dd data-total="credit">{{ moneyText(totals.credit, t('propertyPayments.unknown')) }}</dd>
      </dl>
      <article v-for="item in page.items" :key="item.party_ids.join(',') || '-'" class="space-y-2 rounded-lg border border-neutral-200 p-4" data-party-row>
        <h3 class="font-semibold">{{ partyText(item) }}</h3>
        <p class="text-sm text-neutral-500">{{ t('propertySaldo.parties.units', { units: unitText(item.unit_ids) }) }}</p>
        <p v-if="item.liability_review_required" class="text-warning-700" data-review>{{ t('propertySaldo.parties.review') }}</p>
        <dl class="grid gap-x-3 gap-y-1 text-sm sm:grid-cols-2">
          <dt>{{ t('propertySaldo.parties.receivable') }}</dt>
          <dd data-amount="receivable">{{ moneyText(item.receivable_cents, t('propertyPayments.unknown')) }}</dd>
          <dt>{{ t('propertySaldo.parties.credit') }}</dt>
          <dd data-amount="credit">{{ moneyText(item.credit_cents, t('propertyPayments.unknown')) }}</dd>
        </dl>
      </article>
      <p v-if="!page.items.length">{{ t('propertySaldo.parties.empty') }}</p>
      <p class="text-xs text-neutral-500">{{ t('propertyPayments.card.noNetting') }}</p>
      <div class="flex flex-wrap gap-2">
        <PropertyButton icon="chevron" :disabled="position === 0 || loading || scope.busy.value" data-action="party-saldo-previous" @click="load(position - 1)">{{ t('propertySaldo.previous') }}</PropertyButton>
        <PropertyButton icon="chevron" :disabled="!page.next_cursor || loading || scope.busy.value" data-action="party-saldo-next" @click="load(position + 1)">{{ t('propertySaldo.next') }}</PropertyButton>
      </div>
    </template>
  </section>
</template>
