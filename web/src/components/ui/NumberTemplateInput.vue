<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { normalizeInputBrackets, numberTemplateState, type NumberTemplateOptions } from '@/utils/numberTemplate'

/**
 * Pole pro tvar čísla dokladu (`{YY}{CCCC}`): při psaní převádí závorky () a [] na {},
 * hned hlásí neplatný tvar (červeně pod polem) a u platného ukáže náhled vzorového čísla.
 * Pravidla jsou ve sdíleném `utils/numberTemplate.ts` (zrcadlo PHP NumberTemplateRules).
 */
const props = withDefaults(defineProps<{
  modelValue: string | null | undefined
  options?: NumberTemplateOptions
  placeholder?: string
  maxlength?: number
  /** Třídy výšky a rámečku vstupu; chybový stav přidá červený rámeček. */
  inputClass?: string
  /** Text pod prázdným polem (např. že se použije výchozí šablona). */
  emptyHint?: string
  disabled?: boolean
}>(), {
  options: () => ({}),
  placeholder: '',
  maxlength: 60,
  inputClass: 'h-9 px-3 rounded-md text-sm',
  emptyHint: '',
  disabled: false,
})
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const { t } = useI18n()

const state = computed(() => numberTemplateState(t, props.modelValue, props.options))

function onInput(e: Event) {
  emit('update:modelValue', normalizeInputBrackets(e))
}
</script>

<template>
  <div>
    <input
      :value="modelValue ?? ''" type="text" :maxlength="maxlength" :placeholder="placeholder" :disabled="disabled"
      autocomplete="off" spellcheck="false" :aria-invalid="state.invalid"
      class="w-full border font-mono outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
      :class="[inputClass, state.invalid ? 'border-danger-500 bg-danger-50' : 'border-neutral-300']"
      @input="onInput" />
    <p v-if="state.error" class="text-xs text-danger-500 mt-1" role="alert">{{ state.error }}</p>
    <template v-else-if="state.preview">
      <p class="text-xs text-success-600 mt-1">
        {{ t('number_template.preview') }}: <code class="font-mono font-semibold">{{ state.preview }}</code>
      </p>
      <p v-if="state.warning" class="text-xs text-warning-600 mt-1">{{ state.warning }}</p>
    </template>
    <p v-else-if="emptyHint" class="text-xs text-neutral-400 mt-1">{{ emptyHint }}</p>
  </div>
</template>
