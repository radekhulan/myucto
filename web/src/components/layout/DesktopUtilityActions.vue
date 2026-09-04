<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import WorkspaceLayoutToggle from '@/components/workspace/WorkspaceLayoutToggle.vue'
import WorkspaceNavLink from '@/components/workspace/WorkspaceNavLink.vue'

interface QuickAction {
  to: string
  label: string
  icon: string
}

const props = defineProps<{
  quickActions: QuickAction[]
  manualHref: string
  placement: 'above' | 'below'
}>()

const { t } = useI18n()
const open = ref(false)

watch(() => props.manualHref, () => { open.value = false })
</script>

<template>
  <div class="items-center gap-1 text-sm">
    <WorkspaceLayoutToggle />

    <div v-if="quickActions.length > 0" class="relative">
      <button
        type="button"
        class="cursor-pointer inline-flex w-8 h-8 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-primary-700"
        :class="{ 'bg-neutral-100 text-primary-700': open }"
        :aria-expanded="open"
        :aria-label="t('nav.quick_new')"
        :title="t('nav.quick_new')"
        @click="open = !open"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
        </svg>
      </button>
      <transition
        enter-active-class="transition duration-100 ease-out"
        :enter-from-class="placement === 'above' ? 'opacity-0 translate-y-1' : 'opacity-0 -translate-y-1'"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-75 ease-in"
        leave-from-class="opacity-100 translate-y-0"
        :leave-to-class="placement === 'above' ? 'opacity-0 translate-y-1' : 'opacity-0 -translate-y-1'"
      >
        <div
          v-if="open"
          class="absolute right-0 w-56 bg-surface border border-neutral-200 shadow-xl py-1 z-40"
          :class="placement === 'above' ? 'bottom-full mb-1 rounded-lg' : 'top-full mt-1 rounded-lg'"
        >
          <WorkspaceNavLink
            v-for="action in quickActions"
            :key="action.to"
            :to="action.to"
            class="flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700"
            @click="open = false"
          >
            <svg class="w-4 h-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="action.icon" />
            </svg>
            <span>{{ action.label }}</span>
          </WorkspaceNavLink>
        </div>
      </transition>
      <div v-if="open" class="fixed inset-0 z-10" aria-hidden="true" @click="open = false"></div>
    </div>

    <a
      :href="manualHref"
      target="_blank"
      rel="noopener"
      class="inline-flex w-8 h-8 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-primary-700"
      :title="t('nav.help')"
      :aria-label="t('nav.help')"
    >
      <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827V14m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </a>
  </div>
</template>
