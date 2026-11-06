<script setup lang="ts">
/**
 * Evidence příjmů ze stravovacích služeb pro osvobozené spropitné
 * (§ 6 odst. 9 písm. g) ZDP, od roku 2027).
 *
 * Strop se počítá na serveru a zmrazí se do schváleného mzdového vstupu
 * složky Spropitné. Proto je evidence po schválení spropitného v měsíci zamčená
 * a obrazovka to řekne větou místo zašedlých polí bez vysvětlení.
 */
import { computed, ref, watch, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollBenefitBasketsApi,
  type FoodServiceTipAllocationMode,
  type FoodServiceTipView,
} from '@/api/payrollBenefitBaskets'
import { payrollApi, type PayrollPersonOption } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoneyMinor as money } from '@/composables/useFormat'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ period: string }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const id = useId()

interface AllocationForm {
  employee_id: number | null
  cap: string
}

const view = ref<FoodServiceTipView | null>(null)
const people = ref<PayrollPersonOption[]>([])
const loading = ref(false)
const saving = ref(false)
const revenue = ref('')
const mode = ref<FoodServiceTipAllocationMode>('per_capita')
const recipients = ref<number | null>(null)
const allocations = ref<AllocationForm[]>([])
const evidenceReference = ref('')
const note = ref('')

const canEdit = computed(() => auth.canWrite('payroll.approve') && view.value?.locked !== true)

function toInput(minor: number | null | undefined): string {
  return minor === null || minor === undefined ? '' : (minor / 100).toFixed(2)
}

function toMinor(value: string): number | null {
  const trimmed = value.trim().replace(/\s/g, '').replace(',', '.')
  if (trimmed === '') return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) ? Math.round(parsed * 100) : null
}

function apply(data: FoodServiceTipView): void {
  view.value = data
  const month = data.month
  revenue.value = toInput(month?.revenue_minor)
  mode.value = month?.allocation_mode ?? 'per_capita'
  recipients.value = month?.recipients_count ?? null
  allocations.value = (month?.allocations ?? []).map(row => ({
    employee_id: row.employee_id,
    cap: toInput(row.exempt_cap_minor),
  }))
  evidenceReference.value = month?.evidence_reference ?? ''
  note.value = month?.note ?? ''
}

async function load(): Promise<void> {
  loading.value = true
  try {
    apply(await payrollBenefitBasketsApi.foodServiceTips(props.period))
    if (people.value.length === 0) {
      people.value = await payrollApi.peopleOptions()
    }
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.food_service_tips.load_failed')))
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (saving.value) return
  saving.value = true
  try {
    apply(await payrollBenefitBasketsApi.saveFoodServiceTips(props.period, {
      revenue_minor: toMinor(revenue.value) ?? 0,
      allocation_mode: mode.value,
      recipients_count: mode.value === 'per_capita' ? recipients.value : null,
      allocations: mode.value === 'employer_determined'
        ? allocations.value
          .filter(row => row.employee_id !== null)
          .map(row => ({ employee_id: row.employee_id as number, exempt_cap_minor: toMinor(row.cap) ?? 0 }))
        : [],
      evidence_reference: evidenceReference.value.trim() || null,
      note: note.value.trim() || null,
    }))
    toast.success(t('payroll.food_service_tips.saved'))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.food_service_tips.save_failed')))
  } finally {
    saving.value = false
  }
}

watch(() => props.period, () => { void load() }, { immediate: true })
</script>

<template>
  <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm" data-test="food-service-tips">
    <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.food_service_tips.title') }}</h2>
    <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.food_service_tips.hint') }}</p>

    <p v-if="loading" class="mt-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>

    <template v-else-if="view">
      <p
        v-if="view.locked"
        class="mt-3 rounded-md bg-warning-50 px-4 py-3 text-sm text-warning-700"
        data-test="food-service-tips-locked"
      >
        {{ t('payroll.food_service_tips.locked', { count: view.approved_recipients.length }) }}
      </p>

      <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.food_service_tips.revenue') }}</span>
          <input
            v-model="revenue"
            type="text"
            inputmode="decimal"
            :disabled="!canEdit"
            data-test="food-service-tips-revenue"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.food_service_tips.mode') }}</span>
          <select
            v-model="mode"
            :disabled="!canEdit"
            data-test="food-service-tips-mode"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
            <option value="per_capita">{{ t('payroll.food_service_tips.mode_per_capita') }}</option>
            <option value="employer_determined">{{ t('payroll.food_service_tips.mode_employer_determined') }}</option>
          </select>
        </label>

        <label v-if="mode === 'per_capita'" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.food_service_tips.recipients') }}</span>
          <input
            v-model.number="recipients"
            type="number"
            min="1"
            :disabled="!canEdit"
            data-test="food-service-tips-recipients"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
          >
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.food_service_tips.recipients_hint') }}</span>
        </label>

        <fieldset v-else class="sm:col-span-2">
          <legend class="mb-1 text-xs font-medium text-neutral-600">{{ t('payroll.food_service_tips.allocations') }}</legend>
          <div
            v-for="(row, index) in allocations"
            :key="index"
            class="mb-2 flex flex-wrap items-end gap-2"
            data-test="food-service-tips-allocation"
          >
            <select
              v-model="row.employee_id"
              :disabled="!canEdit"
              :aria-label="t('payroll.food_service_tips.employee')"
              class="h-9 min-w-48 flex-1 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
            >
              <option v-for="person in people" :key="person.id" :value="person.id">{{ person.full_name }}</option>
            </select>
            <input
              v-model="row.cap"
              type="text"
              inputmode="decimal"
              :disabled="!canEdit"
              :aria-label="t('payroll.food_service_tips.cap')"
              :placeholder="t('payroll.food_service_tips.cap')"
              class="h-9 w-40 rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
            >
            <button
              type="button"
              :class="[btnOutline('danger'), 'whitespace-nowrap']"
              :disabled="!canEdit"
              @click="allocations = allocations.filter((_, position) => position !== index)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
              {{ t('payroll.food_service_tips.remove_allocation') }}
            </button>
          </div>
          <button
            type="button"
            :class="[btnOutline('neutral'), 'whitespace-nowrap']"
            :disabled="!canEdit"
            data-test="food-service-tips-add-allocation"
            @click="allocations = [...allocations, { employee_id: people[0]?.id ?? null, cap: '' }]"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ t('payroll.food_service_tips.add_allocation') }}
          </button>
        </fieldset>

        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.food_service_tips.evidence_reference') }}</span>
          <input
            :id="`${id}-evidence`"
            v-model="evidenceReference"
            type="text"
            maxlength="500"
            :disabled="!canEdit"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.food_service_tips.note') }}</span>
          <input
            v-model="note"
            type="text"
            maxlength="500"
            :disabled="!canEdit"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
        </label>
      </div>

      <dl v-if="view.month" class="mt-3 grid gap-1 text-sm text-neutral-700 sm:grid-cols-2" data-test="food-service-tips-caps">
        <div>
          <dt class="inline text-neutral-500">{{ t('payroll.food_service_tips.employer_cap') }}:</dt>
          <dd class="inline font-medium tabular-nums">{{ money(view.month.employer_cap_minor) }}</dd>
        </div>
        <div v-if="view.month.per_capita_cap_minor !== null">
          <dt class="inline text-neutral-500">{{ t('payroll.food_service_tips.per_capita_cap') }}:</dt>
          <dd class="inline font-medium tabular-nums">{{ money(view.month.per_capita_cap_minor) }}</dd>
        </div>
      </dl>

      <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button
          type="button"
          :class="[btnOutline('primary'), 'whitespace-nowrap']"
          :disabled="!canEdit || saving"
          data-test="food-service-tips-save"
          @click="save"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t('payroll.food_service_tips.save') }}
        </button>
      </div>
    </template>
  </section>
</template>
