import { onActivated, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { workReportBillingApi, type WorkReportBillingTemplate } from '@/api/workReportBilling'
import { useSupplierStore } from '@/stores/supplier'
import { apiErrorMessage } from '@/api/errors'

/** Firemní nabídka pro volitelné účtování; obnovení nikdy nemění vybraný snapshot. */
export function useWorkReportBillingTemplates(enabled: () => boolean = () => true) {
  const { t } = useI18n()
  const supplier = useSupplierStore()
  const templates = ref<WorkReportBillingTemplate[]>([])
  const error = ref('')
  let generation = 0
  async function load() {
    const request = ++generation
    templates.value = []
    error.value = ''
    if (!enabled()) return
    try {
      const result = await workReportBillingApi.list()
      if (request === generation) templates.value = result
    } catch (e) {
      if (request === generation) error.value = apiErrorMessage(e, t('common.error'))
    }
  }
  watch(() => [supplier.currentSupplierId, enabled()], load, { immediate: true })
  let activatedOnce = false
  onActivated(() => {
    if (activatedOnce) void load()
    activatedOnce = true
  })
  onBeforeUnmount(() => { generation++ })
  return { templates, error }
}
