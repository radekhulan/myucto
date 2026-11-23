<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type AccountingFramework, type AccountingFrameworkState } from '@/api/settings'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { useDemoMode } from '@/composables/useDemoMode'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'

const props = defineProps<{ supplier: AccountingFrameworkState; canChange: boolean }>()
const emit = defineEmits<{ updated: [state: AccountingFrameworkState] }>()
const { t } = useI18n()
const { blockDemoMutation } = useDemoMode()
const auth = useAuthStore()
const framework = ref<AccountingFramework>('cz_500')
const effectiveFrom = ref('')
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const conversionRequired = ref(false)

const recommendedProfile = computed(() => {
  const recommendation = props.supplier.accounting_profile_recommendation
  if (recommendation?.accounting_framework !== 'cz_504') return null
  return recommendation.entity_profile === 'nonprofit' || recommendation.entity_profile === 'svj'
    ? recommendation.entity_profile
    : null
})

watch(() => [props.supplier.id, props.supplier.accounting_framework] as const, () => {
  framework.value = props.supplier.accounting_framework ?? 'cz_500'
  effectiveFrom.value = ''
  error.value = ''
  conversionRequired.value = false
  saved.value = false
}, { immediate: true })

watch([framework, effectiveFrom], () => { saved.value = false })

async function save() {
  if (saving.value || !props.canChange || !effectiveFrom.value || blockDemoMutation()) return
  const supplierId = props.supplier.id
  saving.value = true
  error.value = ''
  conversionRequired.value = false
  saved.value = false
  try {
    const state = await settingsApi.updateAccountingFramework(supplierId, framework.value, effectiveFrom.value)
    await auth.refresh()
    if (props.supplier.id !== supplierId) return
    emit('updated', {
      id: supplierId,
      accounting_framework: state.accounting_framework,
      accounting_framework_history: state.accounting_framework_history,
      tax_accounting_decree_effective: state.tax_accounting_decree_effective,
      tax_accounting_decree_is_derived: state.tax_accounting_decree_is_derived,
    })
    saved.value = true
  } catch (e) {
    if (props.supplier.id === supplierId) {
      error.value = apiErrorMessage(e, t('settings.accounting_framework.save_error'))
      conversionRequired.value = apiErrorCode(e) === 'accounting_framework_conversion_required'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm" data-test="accounting-framework">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.accounting_framework.title') }}</h2>
    <p class="text-sm mb-2">{{ t('settings.accounting_framework.current') }}: {{ t(`settings.accounting_framework.${supplier.accounting_framework ?? 'cz_500'}`) }}</p>
    <p class="text-xs text-neutral-500 mb-4">{{ t('settings.accounting_framework.hint') }}</p>
    <div v-if="recommendedProfile" class="mb-4 rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm text-primary-700" role="note" data-test="framework-recommendation">
      <p class="font-semibold">{{ t('settings.accounting_framework.recommendation.title') }}</p>
      <p class="mt-1" :data-test="`framework-recommendation-${recommendedProfile}`">{{ t(`settings.accounting_framework.recommendation.${recommendedProfile}`) }}</p>
      <p class="mt-2" data-test="framework-recommendation-conversion">{{ t('settings.accounting_framework.recommendation.conversion') }}</p>
      <div class="mt-3 flex flex-wrap items-center gap-3">
        <button v-if="canChange && framework !== 'cz_504'" type="button" :class="[btnOutline('warning'), 'whitespace-nowrap']" :disabled="saving" data-test="apply-recommended-framework" @click="framework = 'cz_504'">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ t('settings.accounting_framework.recommendation.apply') }}
        </button>
        <RouterLink v-if="auth.can('accounting')" :to="{ name: 'accounting-framework-conversion' }" :class="[btnOutline('neutral'), 'whitespace-nowrap']" data-test="framework-conversion-link">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
          {{ t('settings.accounting_framework.recommendation.conversion_link') }}
        </RouterLink>
      </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-xl">
      <label class="block min-w-0 text-xs font-medium text-neutral-700">
        {{ t('settings.accounting_framework.title') }}
        <select v-model="framework" :disabled="saving || !canChange" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 disabled:opacity-60">
          <option value="cz_500">{{ t('settings.accounting_framework.cz_500') }}</option>
          <option value="cz_504">{{ t('settings.accounting_framework.cz_504') }}</option>
        </select>
      </label>
      <label class="block min-w-0 text-xs font-medium text-neutral-700">
        {{ t('settings.accounting_framework.effective_from') }}
        <DateInput v-model="effectiveFrom" :disabled="saving || !canChange" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-60" :aria-label="t('settings.accounting_framework.effective_from')" />
      </label>
    </div>
    <div class="flex flex-wrap items-center gap-3 mt-3">
      <button type="button" :class="[btnOutline('warning'), 'whitespace-nowrap']" :disabled="saving || !canChange || !effectiveFrom" data-test="save-framework" @click="save">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ t('settings.accounting_framework.save') }}
      </button>
      <p v-if="saved" class="text-sm text-success-700" role="status">{{ t('settings.accounting_framework.saved') }}</p>
    </div>
    <p v-if="error" class="mt-3 text-sm text-danger-700" role="alert">{{ error }}</p>
    <div v-if="conversionRequired && auth.can('accounting')" class="mt-2 flex flex-wrap items-center gap-3">
      <RouterLink :to="{ name: 'accounting-framework-conversion' }" :class="[btnOutline('primary'), 'whitespace-nowrap']" data-test="framework-conversion-required-link">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
        {{ t('settings.accounting_framework.conversion_required_link') }}
      </RouterLink>
    </div>
    <h3 class="text-xs font-semibold text-neutral-700 mt-5 mb-2">{{ t('settings.accounting_framework.history') }}</h3>
    <ul v-if="supplier.accounting_framework_history?.length" class="space-y-1 text-sm">
      <li v-for="item in supplier.accounting_framework_history" :key="item.id">
        {{ item.effective_from === '1900-01-01' ? t('settings.accounting_framework.baseline') : formatDate(item.effective_from) }}:
        {{ t(`settings.accounting_framework.${item.accounting_framework}`) }}
      </li>
    </ul>
    <p v-else class="text-xs text-neutral-500">{{ t('settings.accounting_framework.default_history') }}</p>
  </section>
</template>
