<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { autoPostingApi, type BankRuleTemplate } from '@/api/autoPosting'
import type { BankPostingRule } from '@/api/bankPosting'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const emit = defineEmits<{ applied: [BankPostingRule]; close: [] }>()
const { t } = useI18n()

const templates = ref<BankRuleTemplate[]>([])
const loading = ref(true)
const busyKey = ref<string | null>(null)
const error = ref('')

onMounted(async () => {
  try {
    templates.value = await autoPostingApi.listTemplates()
  } catch (e) {
    error.value = apiErrorMessage(e, t('bank.templates.load_error'))
  } finally {
    loading.value = false
  }
})

const hasTemplates = computed(() => templates.value.length > 0)

function directionLabel(template: BankRuleTemplate): string {
  return template.direction === 'incoming'
    ? t('bank.posting.dir_incoming')
    : t('bank.posting.dir_outgoing')
}

function criteria(template: BankRuleTemplate): string {
  const parts: string[] = []
  if (template.counterparty_prefix) {
    parts.push(t('bank.templates.criteria_prefix', { prefix: template.counterparty_prefix }))
  }
  if (template.counterparty_bank) {
    parts.push(t('bank.templates.criteria_bank', { bank: template.counterparty_bank }))
  }
  if (template.vs_value) parts.push(t('bank.templates.criteria_vs', { value: template.vs_value }))
  if (template.message_contains) {
    parts.push(t('bank.templates.criteria_message', { value: template.message_contains }))
  }
  return parts.length > 0 ? parts.join(', ') : t('bank.templates.criteria_any')
}

function sentence(template: BankRuleTemplate): string {
  return t('automation.rules.sentence', {
    direction: directionLabel(template),
    criteria: criteria(template),
    md: template.debit_account_code,
    d: template.credit_account_code,
    mode: t('automation.rules.mode_suggest'),
  })
}

function placeholderField(template: BankRuleTemplate): string {
  if (template.vs_placeholder === '{cssz_vsdp}') return t('bank.templates.field_cssz_vsdp')
  if (template.vs_placeholder === '{health_insurance_number}') return t('bank.templates.field_health_number')
  if (template.vs_placeholder === '{dic_kmen}') return t('bank.templates.field_dic')
  return template.vs_placeholder?.replace(/[{}]/g, '') ?? ''
}

async function applyTemplate(template: BankRuleTemplate) {
  if (busyKey.value || template.already_instantiated || (template.vs_placeholder && !template.vs_value)) return
  busyKey.value = template.template_key
  error.value = ''
  try {
    const result = await autoPostingApi.instantiateTemplate<BankPostingRule>(template.template_key)
    emit('applied', result.rule)
  } catch (e) {
    error.value = apiErrorMessage(e, t('bank.templates.apply_error'))
  } finally {
    busyKey.value = null
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-4 text-left whitespace-normal font-normal" @click.self="emit('close')">
    <div class="bg-surface rounded-xl shadow-lg w-full max-w-3xl max-h-[90vh] flex flex-col text-left">
      <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 sm:px-5">
        <h3 class="text-lg font-semibold text-neutral-800">{{ t('bank.templates.title') }}</h3>
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.close') }}
        </button>
      </div>

      <div class="overflow-y-auto overflow-x-hidden p-4 sm:p-5">
        <div v-if="loading" class="text-center text-neutral-500 py-10 text-sm">{{ t('common.loading') }}</div>
        <EmptyState v-else-if="!hasTemplates" dense accent="neutral" icon="copy" :title="t('bank.templates.empty')" />
        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <article v-for="template in templates" :key="template.template_key"
            class="min-w-0 border border-neutral-200 rounded-lg p-4 flex flex-col items-start gap-3 bg-surface text-left">
            <div class="min-w-0 w-full">
              <h4 class="font-medium text-neutral-800 break-words">{{ template.name }}</h4>
              <p class="text-sm text-neutral-600 mt-1 break-words">{{ sentence(template) }}</p>
            </div>

            <div v-if="template.vs_placeholder" class="text-xs min-w-0 max-w-full">
              <span v-if="template.vs_value"
                class="inline-flex items-center gap-1 rounded-full bg-success-50 text-success-600 px-2 py-1 font-medium">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ t('bank.templates.identifier_ready', { field: placeholderField(template) }) }}
              </span>
              <div v-else class="rounded-md bg-warning-50 text-warning-600 px-2.5 py-2">
                {{ t('bank.templates.missing_id', { field: placeholderField(template) }) }}
                <RouterLink :to="{ name: 'admin-settings' }" class="font-medium underline ml-1" @click="emit('close')">
                  {{ t('bank.templates.open_settings') }}
                </RouterLink>
              </div>
            </div>

            <div class="mt-auto w-full">
              <div v-if="template.already_instantiated" class="flex items-center gap-2 flex-wrap">
                <button type="button" :class="btnFilled('primary')" class="whitespace-nowrap" disabled>
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  {{ t('bank.templates.already_used') }}
                </button>
                <RouterLink v-if="template.rule_id"
                  :to="{ path: '/templates', query: { section: 'posting', rule: String(template.rule_id) } }"
                  class="text-sm text-primary-600 hover:underline whitespace-nowrap" @click="emit('close')">
                  {{ t('bank.templates.open_rule') }}
                </RouterLink>
              </div>
              <button v-else type="button" :class="btnFilled('primary')"
                class="whitespace-nowrap"
                :disabled="busyKey === template.template_key || (!!template.vs_placeholder && !template.vs_value)"
                @click="applyTemplate(template)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ busyKey === template.template_key ? '…' : t('bank.templates.use') }}
              </button>
            </div>
          </article>
        </div>
        <p v-if="error" class="text-sm text-danger-500 mt-3">{{ error }}</p>
      </div>
    </div>
  </div>
</template>
