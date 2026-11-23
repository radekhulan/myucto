<script setup lang="ts">
import { ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type CertificateVaultCompanyUsage } from '@/api/settings'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ certificateId: number }>()
const { t } = useI18n()
const contentId = useId()
const expanded = ref(false)
const loading = ref(false)
const error = ref('')
const companies = ref<CertificateVaultCompanyUsage[]>([])

async function toggleUsage() {
  expanded.value = !expanded.value
  if (!expanded.value || loading.value) return

  loading.value = true
  error.value = ''
  try {
    companies.value = await settingsApi.getCertificateUsage(props.certificateId)
  } catch (e) {
    error.value = apiErrorMessage(e, t('settings.certificate_vault_company_usage.error'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mt-4 border-t border-neutral-200 pt-3">
    <button
      type="button"
      :class="btnOutline('primary')"
      :aria-expanded="expanded"
      :aria-controls="contentId"
      @click="toggleUsage"
    >
      <svg
        class="h-4 w-4 transition-transform"
        :class="{ 'rotate-90': expanded }"
        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"
      >
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevronRight" />
      </svg>
      {{ t('settings.certificate_vault_company_usage.title') }}
    </button>
    <div v-show="expanded" :id="contentId" class="mt-3" :aria-busy="loading">
      <p v-if="loading" role="status" class="text-xs text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="error" role="alert" class="text-xs text-danger-700">{{ error }}</p>
      <p v-else-if="companies.length === 0" class="text-xs text-neutral-500">{{ t('settings.certificate_vault_company_usage.empty') }}</p>
      <div v-else class="overflow-x-auto rounded-md border border-neutral-200">
        <table class="w-full text-left text-xs" :aria-label="t('settings.certificate_vault_company_usage.title')">
          <thead class="bg-neutral-100 text-neutral-700">
            <tr>
              <th scope="col" class="px-3 py-2 font-medium">{{ t('settings.certificate_vault_company_usage.company') }}</th>
              <th scope="col" class="px-3 py-2 font-medium">{{ t('settings.certificate_vault_company_usage.epo_title') }}</th>
              <th scope="col" class="px-3 py-2 font-medium">{{ t('settings.certificate_vault_company_usage.payroll') }}</th>
              <th scope="col" class="px-3 py-2 font-medium">{{ t('settings.certificate_vault_company_usage.databox') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-200">
            <tr v-for="company in companies" :key="company.supplier_id" class="align-top">
              <th scope="row" class="px-3 py-2 font-medium text-neutral-800">{{ company.name }}</th>
              <td class="px-3 py-2" :class="{
                'text-success-700': company.epo === 'eligible',
                'text-warning-700': company.epo === 'invalid',
                'text-neutral-500': company.epo === 'disabled',
              }">
                {{ t(`settings.certificate_vault_company_usage.epo.${company.epo}`) }}
              </td>
              <td class="px-3 py-2">
                <span v-if="company.payroll.length === 0" class="text-neutral-500">{{ t('settings.certificate_vault_company_usage.payroll_unused') }}</span>
                <ul v-else class="space-y-2">
                  <li v-for="profile in company.payroll" :key="profile.environment">
                    <div class="text-neutral-800">
                      {{ t('settings.certificate_vault_company_usage.payroll_selected', { environment: t(`settings.certificate_vault_company_usage.environment.${profile.environment}`) }) }}
                    </div>
                    <div :class="profile.registration === 'matches' ? 'text-success-700' : 'text-warning-700'">
                      {{ t(`settings.certificate_vault_company_usage.registration.${profile.registration}`) }}
                    </div>
                  </li>
                </ul>
              </td>
              <td class="px-3 py-2">
                <span v-if="company.databox.length === 0" class="text-neutral-500">{{ t('settings.certificate_vault_company_usage.databox_unused') }}</span>
                <ul v-else class="space-y-2">
                  <li v-for="(access, index) in company.databox" :key="index">
                    <code class="font-mono text-neutral-800">{{ access.box_id }}</code>
                    <div class="text-neutral-700">
                      {{ t('settings.certificate_vault_company_usage.databox_config', {
                        environment: t(`settings.certificate_vault_company_usage.environment.${access.environment}`),
                        source: t(`settings.certificate_vault_company_usage.source.${access.source}`),
                      }) }}
                    </div>
                    <div class="text-neutral-500">
                      {{ access.operations.map(operation => t(`settings.certificate_vault_company_usage.operation.${operation}`)).join(', ') }}
                    </div>
                  </li>
                </ul>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
