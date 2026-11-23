<script setup lang="ts">
/**
 * Volba sazby plnění pro daňový doklad k platbě zálohy převzaté bez sazeb DPH (Money S3).
 * Server ji vyžádá chybou `proforma_without_vat_rates`, když sazbu neurčují ani daňové
 * doklady zálohy; nabízí se kladné tuzemské sazby platné k datu platby.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import DialogActions from '@/components/ui/DialogActions.vue'
import { codebooksApi, type VatRate } from '@/api/codebooks'

const props = defineProps<{ paidOn: string; message?: string; busy?: boolean }>()
const emit = defineEmits<{ pick: [vatRateId: number]; close: [] }>()

const { t, locale } = useI18n()
const rates = ref<VatRate[]>([])
const selected = ref<number | null>(null)

onMounted(async () => {
  const all = await codebooksApi.vatRates('CZ', props.paidOn).catch(() => [] as VatRate[])
  rates.value = all.filter(r => Number(r.rate_percent) > 0 && !r.is_reverse_charge)
  selected.value = (rates.value.find(r => r.is_default) ?? rates.value[0])?.id ?? null
})

const actions = computed(() => [
  { key: 'cancel', label: t('common.cancel'), icon: 'x' as const, run: () => emit('close') },
  {
    key: 'confirm', label: t('invoice.payments.choose_rate_confirm'), icon: 'check' as const, tier: 'primary' as const, variant: 'primary' as const,
    disabled: selected.value === null || !!props.busy, run: () => { if (selected.value !== null) emit('pick', selected.value) },
  },
])
</script>

<template>
  <Modal :title="t('invoice.payments.choose_rate_title')" width-class="max-w-md" @close="emit('close')">
    <div class="space-y-3" data-test="payment-tax-rate-dialog">
      <p class="text-sm text-neutral-600">{{ message || t('invoice.payments.choose_rate_hint') }}</p>
      <label class="block text-sm">
        <span class="text-neutral-500">{{ t('invoice.payments.choose_rate_label') }}</span>
        <select v-model="selected" class="mt-1 block w-full rounded border border-neutral-300 px-2 py-1.5" data-test="payment-tax-rate-select">
          <option v-for="r in rates" :key="r.id" :value="r.id">{{ r.rate_percent }} % – {{ locale === 'en' ? r.label_en : r.label_cs }}</option>
        </select>
      </label>
    </div>
    <DialogActions :actions="actions" />
  </Modal>
</template>
