<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnFilled, btnOutline, ICONS, type ActionIcon } from '@/components/ui/buttonStyles'

/**
 * Postup založení agendy SVJ. Krok je hotový podle dat (ne podle kliknutí), první nehotový krok
 * má plnou primární akci. Akce je buď proklik (`to`), nebo událost `action` s klíčem kroku.
 */
export interface SetupStep { key: string; title: string; help: string; done: boolean; optional?: boolean; action?: string; icon?: ActionIcon; to?: string }
const props = defineProps<{ title: string; steps: SetupStep[] }>()
defineEmits<{ action: [key: string] }>()
const { t } = useI18n()
const current = computed(() => props.steps.find(step => !step.done && !step.optional)?.key ?? null)
const finished = computed(() => props.steps.filter(step => !step.optional).every(step => step.done))
</script>
<template>
  <section class="rounded-lg border border-primary-200 bg-surface p-4 space-y-3" data-setup-guide>
    <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 class="text-lg font-semibold">{{ title }}</h2><span v-if="finished" class="text-sm text-success-600">{{ t('propertySetup.allDone') }}</span></div>
    <ol class="space-y-2">
      <li v-for="(step, index) in steps" :key="step.key" :data-step="step.key" class="flex flex-wrap items-start gap-3 rounded-lg border p-3" :class="step.key === current ? 'border-primary-300 bg-primary-50/40' : 'border-neutral-200'">
        <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold" :class="step.done ? 'bg-success-600 text-white' : step.key === current ? 'bg-primary-600 text-white' : 'bg-neutral-200 text-neutral-600'">
          <svg v-if="step.done" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path :d="ICONS.check" /></svg><template v-else>{{ index + 1 }}</template>
        </span>
        <div class="min-w-48 flex-1"><p class="font-medium">{{ step.title }}<span v-if="step.optional" class="ml-2 text-xs font-normal text-neutral-500">{{ t('propertySetup.optional') }}</span></p><p class="text-sm text-neutral-500">{{ step.help }}</p></div>
        <template v-if="step.action">
          <RouterLink v-if="step.to" :to="step.to" :class="[step.key === current ? btnFilled('primary') : btnOutline(step.done ? 'neutral' : 'primary'), 'whitespace-nowrap']"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS[step.icon ?? 'link']" /></svg>{{ step.action }}</RouterLink>
          <button v-else type="button" :class="[step.key === current ? btnFilled('primary') : btnOutline(step.done ? 'neutral' : 'primary'), 'whitespace-nowrap']" @click="$emit('action', step.key)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS[step.icon ?? 'plus']" /></svg>{{ step.action }}</button>
        </template>
      </li>
    </ol>
  </section>
</template>
