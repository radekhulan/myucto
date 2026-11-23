<script setup lang="ts">
/**
 * „Příští číslo" — navázání na rozjetou číselnou řadu při přechodu z jiného software
 * (issue #74). Volá PUT /settings/supplier/invoice-counter, tedy JINÝ endpoint než
 * uložení formuláře: proto samostatné tlačítko a vlastní hláška, ať je z UI poznat,
 * že to není součást „Uložit".
 *
 * Jedna komponenta pro všechny tři osy číslování (dodavatel / klient s vlastní
 * šablonou / kategorie tržby s vlastní šablonou) — tři kopie téhož ovládacího prvku
 * by se rozešly a uživatel by na každé stránce viděl jinou nápovědu k témuž.
 *
 * Nabízí se JEN tam, kde je vlastní šablona s čítačem. Zděděná šablona znamená
 * společnou řadu s dodavatelem; nastavovat ji odsud by vyrobilo počítadlo, ze kterého
 * nikdo nečte (backend takový požadavek odmítá — VarsymbolGenerator::setCounter()).
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type InvoiceCounterType } from '@/api/settings'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { hasCounterPlaceholder } from '@/utils/varsymbol'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

const props = withDefaults(defineProps<{
  type: InvoiceCounterType
  /** Efektivní šablona TÉTO osy; prázdná = osa vlastní řadu nemá. */
  template: string | null | undefined
  /**
   * Perioda resetu TÉTO osy. `null` = volající ji nezná (zděděná z dodavatele) —
   * pak se na ni netvrdí nic, protože varovat naslepo je horší než nevarovat.
   */
  period?: 'year' | 'month' | 'none' | null
  clientId?: number
  revenueCategoryId?: number
  disabled?: boolean
}>(), {
  period: null,
  clientId: 0,
  revenueCategoryId: 0,
  disabled: false,
})

const { t } = useI18n()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const nextNumber = ref('')
const saving = ref(false)
/** Aktuální příští číslo řady ze serveru: po načtení i po uložení. */
const result = ref<{ next_number: number; preview: string } | null>(null)

const template = computed(() => (props.template ?? '').trim())
const supported = computed(() => template.value !== '' && hasCounterPlaceholder(template.value))

/**
 * Bez načtení po otevření stránky by pole zůstalo prázdné a uložené číslo by
 * nebylo vidět, takže to vypadalo, že se nastavení neuložilo.
 */
async function load() {
  if (!supported.value) return
  try {
    const r = await settingsApi.getInvoiceCounter(props.type, props.clientId, props.revenueCategoryId)
    result.value = r.next_number === null ? null : { next_number: r.next_number, preview: r.preview }
    if (result.value && nextNumber.value === '') nextNumber.value = String(result.value.next_number)
  } catch {
    result.value = null
  }
}

watch(() => [props.type, props.clientId, props.revenueCategoryId, supported.value], () => {
  nextNumber.value = ''
  load()
}, { immediate: true })

const valid = computed(() => {
  const n = Number(nextNumber.value)
  return Number.isInteger(n) && n >= 1 && n <= 999999999
})

/**
 * Měsíční reset nad šablonou bez {MM}: počítadlo spadne 1. dne měsíce zpátky na
 * začátek řady a vyrobí čísla, která už existují. U navázané řady je to tichá past,
 * takže se na ni upozorňuje u pole, ne až kolizním hlášením po vystavení.
 */
const periodWarning = computed(() =>
  supported.value && props.period === 'month' && !template.value.includes('{MM}'))

async function apply() {
  if (blockDemoMutation()) return
  if (!valid.value) {
    toast.error(t('settings.numbering_next_number_invalid'))
    return
  }
  saving.value = true
  try {
    // Náhled bere ze SERVERU — ten zná uloženou šablonu i období, do kterého se
    // počítadlo zapsalo. Dopočet v prohlížeči by lhal nad rozepsanou, neuloženou šablonou.
    await settingsApi.setInvoiceCounter(
      props.type,
      Number(nextNumber.value),
      undefined,
      props.clientId,
      props.revenueCategoryId,
    )
    toast.success(t('settings.numbering_next_number_saved'))
    // Po uložení znovu ze serveru: kdyby nastavené číslo kolidovalo s vystaveným
    // dokladem, vystavení ho přeskočí a pole má ukázat to, co doklad opravdu dostane.
    nextNumber.value = ''
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="supported" class="mt-3 pt-3 border-t border-neutral-200">
    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.numbering_next_number') }}</label>
    <div class="flex flex-wrap items-center gap-2">
      <input v-model="nextNumber" type="number" min="1" step="1" inputmode="numeric" :disabled="disabled"
        :placeholder="t('settings.numbering_next_number_placeholder')"
        class="h-9 w-32 px-3 border border-neutral-300 rounded-md text-sm font-mono text-right disabled:bg-neutral-50" />
      <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap"
        :disabled="disabled || saving || !valid" @click="apply">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ saving ? t('common.saving') : t('settings.numbering_next_number_apply') }}
      </button>
    </div>
    <p class="text-xs text-neutral-500 mt-1">{{ t('settings.numbering_next_number_hint') }}</p>
    <p v-if="periodWarning" class="text-xs text-warning-700 mt-1">{{ t('settings.numbering_next_number_period_warning') }}</p>
    <p v-if="result" class="text-xs text-success-600 mt-1">
      {{ t('settings.numbering_next_number_result') }}: <code class="font-mono font-semibold">{{ result.preview }}</code>
    </p>
  </div>
</template>
