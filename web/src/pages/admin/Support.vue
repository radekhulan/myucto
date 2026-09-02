<script setup lang="ts">
/**
 * Systém → Podpora — rozcestník.
 *
 * Odpovídá na tři otázky, které si uživatel klade dřív, než někam napíše:
 * co je zdarma, co se platí, a co k hlášení přiložit. Cesta k podkladům vede
 * na Systém → Diagnostiku; balíček se k incidentu přikládá běžnou cestou jako
 * kterákoli jiná příloha, aplikace nic sama neodesílá.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { licenseApi } from '@/api/license'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t, tm, rt } = useI18n()
const auth = useAuthStore()

const SUPPORT_PORTAL_URL = 'https://myucto.cz/support'
const GITHUB_ISSUES_URL = 'https://github.com/radekhulan/myucto/issues'

const busy = ref(false)
const isAdmin = computed(() => auth.isSuperadmin)

/**
 * Stejný postup jako v patičce aplikace: okno se otevře synchronně v handleru,
 * jinak ho blokátor vyskakovacích oken zahodí, a cíl se doplní až po odpovědi
 * licenčního serveru. Bez licence nebo při chybě se jde na veřejný odkaz.
 */
async function openPortal() {
  if (busy.value) return
  const tab = window.open('', '_blank')
  if (tab) tab.opener = null

  let url = SUPPORT_PORTAL_URL
  if (isAdmin.value) {
    busy.value = true
    try {
      url = (await licenseApi.supportLink()).url || SUPPORT_PORTAL_URL
    } catch {
      url = SUPPORT_PORTAL_URL
    } finally {
      busy.value = false
    }
  }

  if (tab) tab.location.replace(url)
  else window.open(url, '_blank', 'noopener')
}

const freeItems = computed(() => tm('support_page.free_items') as unknown[])
const paidItems = computed(() => tm('support_page.paid_items') as unknown[])
const attachItems = computed(() => tm('support_page.attach_items') as unknown[])
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('support_page.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('support_page.subtitle') }}</p>
    </header>

    <div class="space-y-6">
      <!-- Zdarma -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('support_page.free_title') }}</h2>
        <ul class="mt-2 space-y-1">
          <li v-for="(item, i) in freeItems" :key="i" class="text-sm text-neutral-700 flex gap-1.5">
            <span aria-hidden="true">•</span><span>{{ rt(item as any) }}</span>
          </li>
        </ul>
        <div class="mt-4 flex flex-wrap gap-2">
          <a href="/manual" target="_blank" rel="noopener" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
            </svg>
            {{ t('support_page.open_manual') }}
          </a>
          <a :href="GITHUB_ISSUES_URL" target="_blank" rel="noopener" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" />
            </svg>
            {{ t('support_page.open_issues') }}
          </a>
        </div>
      </section>

      <!-- Placené -->
      <section class="rounded-lg border border-primary-300 bg-primary-50/40 p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('support_page.paid_title') }}</h2>
        <p class="text-sm text-neutral-600 mt-0.5">{{ t('support_page.paid_hint') }}</p>
        <ul class="mt-2 space-y-1">
          <li v-for="(item, i) in paidItems" :key="i" class="text-sm text-neutral-700 flex gap-1.5">
            <span aria-hidden="true">•</span><span>{{ rt(item as any) }}</span>
          </li>
        </ul>
        <div class="mt-4">
          <button type="button" :disabled="busy" :class="btnFilled('primary')" @click="openPortal">
            <svg
              class="w-4 h-4"
              :class="{ 'animate-spin': busy }"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path stroke-linecap="round" stroke-linejoin="round" :d="busy ? ICONS.cycle : ICONS.help" />
            </svg>
            {{ busy ? t('support_page.opening') : t('support_page.open_portal') }}
          </button>
          <p class="mt-2 text-xs text-neutral-600">{{ t('support_page.portal_hint') }}</p>
        </div>
      </section>

      <!-- Podklady -->
      <section class="rounded-lg border border-neutral-200 bg-surface p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('support_page.attach_title') }}</h2>
        <p class="text-sm text-neutral-600 mt-1">{{ t('support_page.attach_intro') }}</p>
        <ul class="mt-2 space-y-1">
          <li v-for="(item, i) in attachItems" :key="i" class="text-sm text-neutral-700 flex gap-1.5">
            <span aria-hidden="true">•</span><span>{{ rt(item as any) }}</span>
          </li>
        </ul>
        <p class="mt-3 text-sm text-neutral-700">{{ t('support_page.attach_privacy') }}</p>
        <div class="mt-4">
          <RouterLink v-if="isAdmin" to="/admin/diagnostics" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.clipboardCheck" />
            </svg>
            {{ t('support_page.open_diagnostics') }}
          </RouterLink>
        </div>
      </section>
    </div>
  </div>
</template>
