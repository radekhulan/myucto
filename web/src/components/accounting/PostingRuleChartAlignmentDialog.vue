<script setup lang="ts">
/**
 * „Doplnit podle osnovy" — náhled srovnání předkontací s analytickou osnovou.
 *
 * Dialog je ZÁMĚRNĚ dry-run: server nejdřív vrátí, co by se změnilo, uživatel
 * volby potvrdí a teprve druhé volání zapisuje. Předkontace jsou vstup do
 * účtování celé firmy — tichá hromadná změna by se projevila až na dokladech.
 *
 * Řádky `auto` se ukazují, ale zapsat je nejde: tam si syntetiku s jedinou
 * analytikou přesměruje engine sám (PostingService::singleAnalyticMap) a
 * override by jen přidal položku k údržbě.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi,
  type ChartAlignmentPreview,
  type ChartAlignmentRule,
  type ChartAlignmentSide,
  type ChartAlignmentStatus,
  type ChartAlignmentApplyItem,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [v: boolean]; applied: [] }>()

const { t } = useI18n()
const toast = useToast()

const preview = ref<ChartAlignmentPreview | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const onlyTodo = ref(true)
/** rule_key → zvolené kódy; prázdný řetězec = ponechat, jak je. */
const choice = ref<Record<string, { debit: string; credit: string }>>({})
const picked = ref<Record<string, boolean>>({})

watch(() => props.modelValue, open => { if (open) void load() })

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await accountingApi.postingRuleChartAlignment()
    preview.value = data
    const nextChoice: Record<string, { debit: string; credit: string }> = {}
    const nextPicked: Record<string, boolean> = {}
    for (const rule of data.rules) {
      nextChoice[rule.rule_key] = {
        debit: rule.debit.suggested_code ?? '',
        credit: rule.credit.suggested_code ?? '',
      }
      // Předvybráno je jen to, co má co zapsat — ať „Zapsat" nikdy neznamená
      // víc, než kolik řádků uživatel opravdu vidí vyplněných.
      nextPicked[rule.rule_key] = rule.status === 'suggest'
        && (rule.debit.suggested_code !== null || rule.credit.suggested_code !== null)
    }
    choice.value = nextChoice
    picked.value = nextPicked
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}

const rows = computed<ChartAlignmentRule[]>(() => {
  const all = preview.value?.rules ?? []
  return onlyTodo.value
    ? all.filter(r => r.status === 'suggest' || r.status === 'missing')
    : all
})

/** Změny, které se opravdu odešlou — řádek musí být zaškrtnutý a mít vybraný jiný účet. */
const changes = computed<ChartAlignmentApplyItem[]>(() => {
  const out: ChartAlignmentApplyItem[] = []
  for (const rule of preview.value?.rules ?? []) {
    if (rule.status !== 'suggest' || !picked.value[rule.rule_key]) continue
    const chosen = choice.value[rule.rule_key]
    if (!chosen) continue
    const debit = chosen.debit && chosen.debit !== rule.debit.code ? chosen.debit : null
    const credit = chosen.credit && chosen.credit !== rule.credit.code ? chosen.credit : null
    if (debit === null && credit === null) continue
    out.push({ rule_key: rule.rule_key, debit_account_code: debit, credit_account_code: credit })
  }
  return out
})

function isSelectable(side: ChartAlignmentSide): boolean {
  return side.status === 'suggest' && side.candidates.length > 0
}

async function apply() {
  if (changes.value.length === 0) return
  busy.value = true
  error.value = ''
  try {
    const result = await accountingApi.applyPostingRuleChartAlignment(changes.value)
    toast.success(t('accounting.posting_rules.alignment.applied', { count: result.applied.length }))
    emit('applied')
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}

function close() {
  emit('update:modelValue', false)
}

const STATUS_CLASS: Record<ChartAlignmentStatus, string> = {
  ok:      'bg-success-50 text-success-600 border-success-500/40',
  auto:    'bg-primary-50 text-primary-700 border-primary-500/40',
  suggest: 'bg-warning-50 text-warning-600 border-warning-500/40',
  context: 'bg-neutral-100 text-neutral-600 border-neutral-200',
  missing: 'bg-danger-50 text-danger-500 border-danger-500/40',
}
const STATUS_ORDER: ChartAlignmentStatus[] = ['suggest', 'missing', 'auto', 'context', 'ok']
</script>

<template>
  <div
    v-if="modelValue"
    class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto"
    @click.self="close"
  >
    <div class="bg-surface rounded-xl shadow-lg max-w-5xl w-full my-8 max-h-[90vh] flex flex-col">
      <header class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between gap-3 shrink-0">
        <div>
          <h3 class="text-lg font-semibold">{{ t('accounting.posting_rules.alignment.title') }}</h3>
          <p class="text-xs text-neutral-500 mt-0.5">{{ t('accounting.posting_rules.alignment.subtitle') }}</p>
        </div>
        <button @click="close" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
      </header>

      <div class="px-5 py-4 space-y-4 overflow-y-auto grow">
        <div v-if="loading" class="text-center text-neutral-500 py-10 text-sm">{{ t('common.loading') }}</div>

        <template v-else-if="preview">
          <!-- Souhrn: kolik čeho, ať je vidět rozsah dřív než tabulka -->
          <div class="flex flex-wrap gap-2">
            <span
              v-for="status in STATUS_ORDER"
              :key="status"
              class="text-xs px-2 py-1 rounded border font-medium whitespace-nowrap"
              :class="STATUS_CLASS[status]"
            >
              {{ t(`accounting.posting_rules.alignment.status_${status}`) }}: {{ preview.counts[status] ?? 0 }}
            </span>
          </div>

          <p v-if="!preview.redirect_enabled" class="text-xs text-warning-600">
            {{ t('accounting.posting_rules.alignment.redirect_off') }}
          </p>

          <label class="flex items-center gap-2 text-sm text-neutral-600">
            <input v-model="onlyTodo" type="checkbox" class="rounded border-neutral-300" />
            {{ t('accounting.posting_rules.alignment.only_todo') }}
          </label>

          <div v-if="rows.length === 0" class="text-sm text-neutral-500 py-6 text-center">
            {{ t('accounting.posting_rules.alignment.nothing_todo') }}
          </div>

          <div v-else class="border border-neutral-200 rounded-lg overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-3 py-2 w-10"></th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accounting.posting_rules.rule_key') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accounting.posting_rules.debit') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accounting.posting_rules.credit') }}</th>
                  <th class="px-3 py-2 text-center font-medium w-28">{{ t('accounting.posting_rules.alignment.status') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="rule in rows" :key="rule.rule_key" class="align-top">
                  <td class="px-3 py-2">
                    <input
                      v-if="rule.status === 'suggest'"
                      v-model="picked[rule.rule_key]"
                      type="checkbox"
                      class="rounded border-neutral-300 mt-1"
                      :aria-label="rule.rule_key"
                    />
                  </td>
                  <td class="px-3 py-2">
                    <div class="font-mono text-xs text-neutral-700">{{ rule.rule_key }}</div>
                    <div class="text-xs text-neutral-500">{{ rule.description }}</div>
                  </td>
                  <td v-for="side in [rule.debit, rule.credit]" :key="`${rule.rule_key}-${side.code}-${side.status}`" class="px-3 py-2">
                    <div class="font-mono text-xs text-neutral-700">{{ side.code || '—' }}</div>
                    <div v-if="side.status === 'auto'" class="text-xs text-primary-700 mt-0.5">
                      → {{ side.effective_code }} <span class="text-neutral-500">({{ t('accounting.posting_rules.alignment.by_engine') }})</span>
                    </div>
                    <div v-else-if="side.status === 'missing'" class="text-xs text-danger-500 mt-0.5">
                      {{ t('accounting.posting_rules.alignment.missing_hint') }}
                    </div>
                    <div v-else-if="side.status === 'context'" class="text-xs text-neutral-500 mt-0.5">
                      {{ t('accounting.posting_rules.alignment.context_hint') }}
                    </div>
                    <select
                      v-else-if="isSelectable(side)"
                      v-model="choice[rule.rule_key][side === rule.debit ? 'debit' : 'credit']"
                      class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-xs font-mono"
                    >
                      <option value="">{{ t('accounting.posting_rules.alignment.keep') }}</option>
                      <option v-for="c in side.candidates" :key="c.account_code" :value="c.account_code">
                        {{ c.account_code }} — {{ c.name }}
                      </option>
                    </select>
                  </td>
                  <td class="px-3 py-2 text-center">
                    <span class="text-xs px-2 py-0.5 rounded border font-medium whitespace-nowrap" :class="STATUS_CLASS[rule.status]">
                      {{ t(`accounting.posting_rules.alignment.status_${rule.status}`) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
      </div>

      <footer class="px-5 py-4 border-t border-neutral-200 flex flex-wrap items-center justify-end gap-2 shrink-0">
        <span class="text-xs text-neutral-500 mr-auto">
          {{ t('accounting.posting_rules.alignment.selected', { count: changes.length }) }}
        </span>
        <button @click="close" :class="btnOutline('neutral')">{{ t('common.close') }}</button>
        <button :disabled="busy || changes.length === 0" @click="apply" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('accounting.posting_rules.alignment.apply') }}
        </button>
      </footer>
    </div>
  </div>
</template>
