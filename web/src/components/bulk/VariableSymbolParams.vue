<script setup lang="ts">
/**
 * Parametry hromadné opravy variabilního symbolu bankovních pohybů: stálá hodnota,
 * nebo vzor z číslic a značek, které se doplní z pohybu.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { VS_PATTERN_MAX, VS_TOKENS, isValidVariableSymbol, isValidVsPattern, type BulkSymbolForm } from './noteSymbolForm'

const model = defineModel<BulkSymbolForm>({ required: true })
defineProps<{ disabled?: boolean }>()

const { t } = useI18n()
const MODES = ['constant', 'pattern'] as const
const valueInvalid = computed(() => model.value.value.trim() !== '' && !isValidVariableSymbol(model.value.value))
const patternInvalid = computed(() => model.value.pattern.trim() !== '' && !isValidVsPattern(model.value.pattern))
const tokenKey = (token: string) => token.slice(1, -1)
</script>

<template>
  <div class="space-y-3" data-test="bulk-params-vs">
    <fieldset>
      <legend class="block text-xs font-medium text-neutral-500 mb-1">{{ t('bulk_actions.vs.mode_label') }}</legend>
      <div class="flex flex-wrap gap-x-5 gap-y-1">
        <label v-for="m in MODES" :key="m" class="inline-flex items-center gap-2 whitespace-nowrap cursor-pointer">
          <input v-model="model.mode" type="radio" :value="m" class="h-4 w-4" :disabled="disabled" :data-test="`bulk-vs-mode-${m}`" />
          {{ t('bulk_actions.vs.mode.' + m) }}
        </label>
      </div>
    </fieldset>

    <div v-if="model.mode === 'constant'">
      <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-vs-value">{{ t('bulk_actions.vs.value_label') }}</label>
      <input id="bulk-vs-value" v-model="model.value" type="text" inputmode="numeric" maxlength="10" autocomplete="off"
        :disabled="disabled" data-test="bulk-vs-value" :aria-invalid="valueInvalid ? 'true' : undefined"
        class="w-full sm:w-48 h-10 px-3 border rounded-md font-mono bg-surface" :class="valueInvalid ? 'border-danger-500' : 'border-neutral-300'" />
      <p v-if="valueInvalid" class="mt-1 text-xs text-danger-500" data-test="bulk-vs-error">{{ t('bulk_actions.vs.value_invalid') }}</p>
    </div>

    <div v-else>
      <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-vs-pattern">{{ t('bulk_actions.vs.pattern_label') }}</label>
      <input id="bulk-vs-pattern" v-model="model.pattern" type="text" :maxlength="VS_PATTERN_MAX" autocomplete="off"
        :placeholder="t('bulk_actions.vs.pattern_placeholder')" :disabled="disabled" data-test="bulk-vs-pattern"
        :aria-invalid="patternInvalid ? 'true' : undefined"
        class="w-full sm:w-72 h-10 px-3 border rounded-md font-mono bg-surface" :class="patternInvalid ? 'border-danger-500' : 'border-neutral-300'" />
      <p v-if="patternInvalid" class="mt-1 text-xs text-danger-500" data-test="bulk-vs-error">{{ t('bulk_actions.vs.pattern_invalid') }}</p>
      <div class="mt-2 text-xs text-neutral-600" data-test="bulk-vs-tokens">
        <span class="font-medium text-neutral-500">{{ t('bulk_actions.vs.tokens_title') }}:</span>
        <ul class="mt-1 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-0.5">
          <li v-for="token in VS_TOKENS" :key="token"><code class="font-mono text-neutral-900">{{ token }}</code> {{ t('bulk_actions.vs.token.' + tokenKey(token)) }}</li>
        </ul>
      </div>
    </div>

    <p class="text-xs text-neutral-500">{{ t('bulk_actions.vs.hint') }}</p>
  </div>
</template>
