import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { availableParallelism } from 'node:os'

const nodeTests = [
  'src/utils/__tests__/{accountingSetupDependencies,bankConnectionAccount,bankConnectionError,chartAccountOptions,clientPlatform,date,dateInput,epoAttemptState,epoHandoffCache,healthInsurers,navigationLayout,periodDefaultYear,returnPath,safeUrl,slugifyCode,varsymbol}.spec.ts',
  'src/pages/payroll/__tests__/{employmentLifecycleUi,enforcementEvidenceScope,payrollAgendaLinks,payrollComponentsUi,payrollEmployerAccounts,payrollTime,payrollTimeGrid,statutoryEvidenceForm}.spec.ts',
  'src/api/__tests__/{hostingActions,instanceAlert,instanceHealth,instancePreview,payrollYearClosed,storageQuota,storageQuotaSticky}.spec.ts',
  'src/components/layout/__tests__/{dataBoxNav,hostingNavGating,payrollNavOrder,permissionNav,quickCreateEmployee}.spec.ts',
  'src/components/settings/__tests__/bankRuleTemplatePermissions.spec.ts',
  'src/pages/admin/__tests__/{DataBoxAccessAndTheme,rolesFixedPresets}.spec.ts',
  'src/pages/hosting/__tests__/HostingEnvironmentGuard.spec.ts',
  'src/config/__tests__/manualChapters.spec.ts',
  'src/workspace/__tests__/panelSizing.spec.ts',
  'src/composables/__tests__/usePayrollLabels.spec.ts',
  'src/components/bank/__tests__/bankTranslations.spec.ts',
]

// Časové pásmo se pinuje ještě před startem workerů: `formatUtcDateTime`
// převádí UTC sloupce do pásma stroje, takže bez tohohle by tytéž testy prošly
// na vývojářském Windows (Praha) a spadly v CI (UTC).
process.env.TZ = 'Europe/Prague'

// Samostatná konfigurace pro testy — nedědí server proxy / tailwind z vite.config.ts,
// jen vue plugin (pro .vue SFC) + alias `@` → src (shodně s vite.config a tsconfig).
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@shared': fileURLToPath(new URL('./shared', import.meta.url)),
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    globals: true,
    env: { TZ: 'Europe/Prague' },
    isolate: true,
    fileParallelism: true,
    maxWorkers: Math.min(20, Math.floor(availableParallelism() * 1.25)),
    projects: [
      { extends: true, test: { name: 'node', environment: 'node', include: nodeTests } },
      {
        extends: true,
        test: {
          name: 'dom',
          environment: 'jsdom',
          include: ['src/**/*.{test,spec}.ts'],
          exclude: nodeTests,
        },
      },
    ],
  },
})
