<script setup lang="ts">
import { computed, ref, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { ICONS } from '@/components/ui/buttonStyles'

export type EnvironmentValue = 'production' | 'test'

const props = withDefaults(defineProps<{
  disabled?: boolean
  size?: 'sm' | 'md'
  productionLabel?: string
  testLabel?: string
  ariaLabel?: string
}>(), {
  disabled: false,
  size: 'md',
  productionLabel: undefined,
  testLabel: undefined,
  ariaLabel: undefined,
})

const model = defineModel<EnvironmentValue>({ default: 'production' })

const { t } = useI18n()

// Zámek = ostrá data, výstražný trojúhelník = testovací prostředí. Obě cesty
// musí jít poznat i bez čtení popisku, protože záměna prostředí stála
// uživatele přihlašovací údaje do ostré datové schránky.
const options = computed(() => [
  {
    value: 'production' as const,
    label: props.productionLabel ?? t('common.environmentSwitch.production'),
    icon: ICONS.lock,
    title: t('common.environmentSwitch.productionHint'),
  },
  {
    value: 'test' as const,
    label: props.testLabel ?? t('common.environmentSwitch.test'),
    icon: ICONS.bell,
    title: t('common.environmentSwitch.testHint'),
  },
])

const buttons = ref<HTMLButtonElement[]>([])

function setButtonRef(el: unknown, index: number): void {
  if (el instanceof HTMLButtonElement) buttons.value[index] = el
  else buttons.value.splice(index, 1)
}

const sizeClass = computed(() => props.size === 'sm' ? 'h-7 gap-1 px-2 text-xs' : 'h-8 gap-1.5 px-3 text-sm')
const iconClass = computed(() => props.size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4')

const groupClass = computed(() => model.value === 'test'
  ? 'border-warning-500 bg-warning-50 ring-2 ring-warning-500/40'
  : 'border-neutral-300 bg-neutral-100')

function optionClass(value: EnvironmentValue): string {
  if (model.value !== value) {
    return props.disabled
      ? 'text-neutral-500'
      : 'text-neutral-600 hover:bg-neutral-200 hover:text-neutral-900'
  }
  return value === 'test'
    ? 'bg-warning-500 text-white shadow-sm'
    : 'bg-success-600 text-white shadow-sm'
}

function select(value: EnvironmentValue): void {
  if (props.disabled || model.value === value) return
  model.value = value
}

async function move(offset: number): Promise<void> {
  if (props.disabled) return
  const list = options.value
  const current = list.findIndex(option => option.value === model.value)
  const next = list[(current + offset + list.length) % list.length]
  if (next === undefined) return
  model.value = next.value
  await nextTick()
  buttons.value[list.indexOf(next)]?.focus()
}

async function onKeydown(event: KeyboardEvent): Promise<void> {
  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      event.preventDefault()
      await move(1)
      break
    case 'ArrowLeft':
    case 'ArrowUp':
      event.preventDefault()
      await move(-1)
      break
    case 'Home':
      event.preventDefault()
      await move(-options.value.findIndex(option => option.value === model.value))
      break
    case 'End':
      event.preventDefault()
      await move(options.value.length - 1 - options.value.findIndex(option => option.value === model.value))
      break
  }
}
</script>

<template>
  <div
    role="radiogroup"
    :aria-label="ariaLabel ?? t('common.environmentSwitch.legend')"
    :aria-disabled="disabled || undefined"
    class="inline-flex items-center gap-0.5 rounded-lg border p-0.5 transition-colors"
    :class="[groupClass, disabled ? 'opacity-60' : '']"
    :data-environment="model"
    @keydown="onKeydown"
  >
    <button
      v-for="(option, index) in options"
      :key="option.value"
      :ref="el => setButtonRef(el, index)"
      type="button"
      role="radio"
      :aria-checked="model === option.value"
      :tabindex="model === option.value ? 0 : -1"
      :disabled="disabled"
      :title="option.title"
      :data-test="`environment-switch-${option.value}`"
      class="inline-flex items-center rounded-md font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 disabled:cursor-not-allowed"
      :class="[sizeClass, optionClass(option.value), disabled ? '' : 'cursor-pointer']"
      @click="select(option.value)"
    >
      <svg
        class="shrink-0"
        :class="iconClass"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <path stroke-linecap="round" stroke-linejoin="round" :d="option.icon" />
      </svg>
      <span>{{ option.label }}</span>
    </button>
  </div>
</template>
