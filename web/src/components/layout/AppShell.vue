<script setup lang="ts">
import { useI18n } from 'vue-i18n'
defineProps<{ title?: string }>()
const { t } = useI18n()
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">
    <!-- Stejná tmavá lišta jako v aplikaci: přihlášení a veřejné stránky jsou
         první, co uživatel vidí, a nemají vypadat jako jiný produkt. -->
    <header class="nav-inverted border-b border-neutral-200 bg-surface shadow-md">
      <div class="max-w-6xl mx-auto px-6 py-4 flex items-center gap-3">
        <img src="/styles/logo.svg" alt="MyÚčto" class="w-9 h-9" />
        <div>
          <!-- `font-sans` je vědomý opt-out z globálního display řezu pro H1:
               tenhle nadpis je značka, ne titulek stránky, a musí sedět s logem
               v topbaru aplikace. -->
          <h1 class="font-sans text-lg font-semibold leading-tight">
            My<span class="text-primary-600">Účto</span><span class="brand-tld">.cz</span>
          </h1>
          <p class="text-xs text-neutral-500 leading-tight">{{ title ?? t('app.subtitle') }}</p>
        </div>
      </div>
    </header>
    <main class="flex-1 flex items-center justify-center px-4 py-8">
      <slot />
    </main>
  </div>
</template>
