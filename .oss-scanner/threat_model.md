# Threat model: MyÚčto.cz

## What this project does and where untrusted input enters

MyÚčto.cz is a self-hosted (and SaaS-hosted) Czech invoicing, accounting, tax and payroll
system: issued and received invoices, double-entry bookkeeping, VAT returns and control
statements, payroll and its state filings, bank connectors, a client portal, a REST API and an
MCP server. Backend is PHP 8.5 + Slim 4 (`api/`), frontend Vue 3 + TypeScript (`web/`), the
MCP bridge is Node (`MCP/`), database MariaDB 11.8, Redis for rate limiting.

It holds financial and personal data (invoices, bank statements, payroll, national ID numbers,
tax filings) of several companies per installation. The core security promises are:

1. **Authentication**: nobody gets in without valid credentials (password + optional TOTP,
   passkeys, e-mail OTP), a valid API bearer token, or a valid MCP OAuth grant.
2. **Tenant isolation**: one installation hosts many companies (`supplier`). A user may only
   read or write companies listed in `user_suppliers`; an API token bound to a company may only
   touch that company. On SaaS hosting the Host header is also a tenant boundary.
3. **Role permissions**: `superadmin`, `accountant`, `readonly`, `client` (portal) and custom
   roles; bearer tokens are additionally scoped `read` / `read_write`.
4. **Integrity of accounting and tax data**: locked periods, issued documents and filed returns
   must not change through a path that skips the permission and lock checks.

Untrusted input enters through:

- **HTTP API** (`api/src/Routes.php`, actions in `api/src/Action/`). Middleware chain is built
  in `api/src/Bootstrap.php`: TenantDomain → Auth → RequireMfa → SupplierScope → Permission →
  ApiScope → RateLimit → Csrf (`api/src/Middleware/`).
- **Unauthenticated endpoints**: `/api/auth/*` (login, setup wizard until the first user
  exists, forgot/reset password, WebAuthn, domain login), `/oauth/*` and `/.well-known/*`
  (MCP OAuth), `/mcp`, `/api/managed/license` (Ed25519-signed), and token-in-URL links under
  `/api/public/*` (invoice, approval, purchase approval, work report, payroll document,
  domain verification, Shoptet feed, inbound integration webhooks with HMAC).
- **Uploaded files and imports** parsed server-side: ISDOC/ISDOCX, PDF (own object reader and
  decryptor in `api/src/Service/Import/`), images and HEIC (GD/Imagick), ZIP, ISDS `.zfo`,
  tar.gz updates, bank statements (GPC, bank PDF statements, bank API JSON, e-mail payment
  notices), POHODA XML, Money S3 / Premier DBF+CAB / ABRA / Stereo migration imports
  (`api/src/Service/Migration/`), Shoptet CSV/XML, catalog imports, payroll XLSX and XML,
  EPO tax XML.
- **Mailboxes** (IMAP) read for invoices and bank notices, and **ISDS data boxes**.
- **LLM extraction** of uploaded documents (`api/src/Service/Import/*Ai*`,
  `api/src/Service/Ai/`): document content is attacker controlled and reaches prompts.
- **Outbound requests** to user-configurable hosts (Ollama and Azure OpenAI endpoints,
  IMAP/SMTP hosts, catalog media URLs). `api/src/Service/Http/OutboundUrlGuard.php` is the
  SSRF guard.

## Components that matter most / least

- **Most**: authentication and session handling (`api/src/Service/Auth/`, `AuthMiddleware`),
  authorization (`api/src/Security/`, `SupplierScopeMiddleware`, `Http/SupplierGuard.php`,
  `Http/TenantReferenceGuard.php`), MCP OAuth (`api/src/Service/Mcp/`, `MCP/src/`), public
  token endpoints, file parsers and storage/download paths (`RuntimePaths`,
  `Service/Document/`), secret encryption (`Service/Auth/SecretEncryption.php`, bank credential
  vaults, payroll document key ring), SSRF guards.
- **Less**: report and analytics read paths that are already behind authentication and tenant
  scope; the Vue frontend (XSS still in scope, logic bugs that the API rejects are not).
- **Out of scope**: `tools/`, `cmd/`, `docker/` (deployment configuration), `db/migrations/`
  (schema only), `manual/`, `styles/`, `api/tests/`, `api/xsd/`, CLI-only maintenance scripts in
  `api/bin/` that refuse to run outside the CLI, and vulnerabilities living entirely in
  third-party dependencies unless MyÚčto's own code makes them reachable.

## How to exercise it

The image contains a ready test instance with the same configuration as CI
(`.github/workflows/ci.yml`):

```sh
.oss-scanner/start-services.sh            # MariaDB, Redis and the app on http://localhost:8080
.oss-scanner/start-services.sh --no-app   # only MariaDB and Redis (for PHPUnit)
```

- Login: `POST /api/auth/login` with `fixture@example.invalid` / `Fixture-password-42`
  (superadmin, created by `api/bin/ci-seed.php`). Two tenants exist, "Fixture Alfa" and
  "Fixture Beta", which is what cross-tenant tests need. Select a tenant with the
  `X-Supplier-Id` header. State-changing requests with a session need
  `Origin: http://localhost:8080` and the `X-CSRF-Token` header.
- The session cookie is `__Host-` prefixed and `Secure`, so over plain HTTP a client must send it
  manually, or create an API token (`POST /api/auth/tokens`, needs the session and the password)
  and use `Authorization: Bearer`. The Host header must be `localhost:8080`, other hosts get 421.
- The app runs on PHP's built-in server via `api/bin/dev-server-router.php`. That router only
  forwards `/api/*` to the app, does not send security headers and does not block sensitive
  paths; in production IIS (`web.config`), Apache (`.htaccess`) or nginx (`docker/nginx.conf`)
  do that. Do not report missing headers or exposed paths that exist only because of the dev
  router. `/mcp` and `/oauth/*` are best tested at the action level or through PHPUnit.
- Tests: `cd api && vendor/bin/phpunit --testsuite Unit|Architecture|Invariants`; the
  Integration suite needs the services and `MYINVOICE_TEST_URL=http://localhost:8080`.
  Security-focused tests live in `api/tests/Integration/` (`UnauthenticatedAccessTest`,
  `BearerAuthTest`, `RbacHttpRoleMatrixTest`, `McpOAuthGrantTest`, `Security/*IdorTest`,
  `Tenant/*`) and `api/tests/Architecture/` (route permission coverage, tenant predicates).
  Frontend: `cd web && pnpm test`. MCP: `cd MCP && npm test`.
- The public API contract is in `api/openapi.yaml`.

## How you rate severity

- **Critical**: unauthenticated remote code execution; unauthenticated access to any company's
  data; authentication bypass; reading or writing another tenant's data as an ordinary
  authenticated user (cross-tenant IDOR); recovering stored secrets (bank credentials, API
  keys, encryption keys) without admin access.
- **High**: privilege escalation between roles (`readonly` or `client` writing data, a
  non-admin managing users, roles or tokens); a bearer token exceeding its scope or company
  binding; SQL injection or path traversal reachable by an authenticated user; stored XSS that
  runs in another user's session; SSRF reaching internal networks or cloud metadata; RCE or
  arbitrary file write through an uploaded file or import; guessable or reusable public tokens.
- **Medium**: changing accounting or tax data in a locked period or on a filed return through a
  path that skips the lock; information disclosure across users of the same company beyond their
  role; reflected XSS; CSRF on a state-changing endpoint; brute-force or rate-limit bypass;
  denial of service of the whole instance from one request or one uploaded file.
- **Low**: issues that need superadmin of the same installation, user enumeration, verbose
  errors, missing hardening without a demonstrated impact.
- Prompt injection through document content is in scope when it makes the system do something
  the user did not ask for (write data, leak data of other documents or tenants); a wrong
  extracted value that the user reviews before saving is not a vulnerability.

## Anything to leave alone

- Do not report the synthetic keys and passwords in `.oss-scanner/` or the CI workflow, or
  the fixture account; they exist only for test instances.
- A superadmin of an installation is trusted with that installation, including server-side
  configuration such as the Ollama URL; report only what crosses that trust boundary (for
  example SSRF from managed SaaS hosting where `MYINVOICE_OLLAMA_ALLOWED_HOSTS` is required).
- Wrong tax or accounting results are bugs, not vulnerabilities, unless an attacker can cause
  them across a permission or tenant boundary.
- Reports should include a reproducer against the test instance in this image (curl sequence,
  PHPUnit test or a small script) and, where possible, a patch with a regression test in
  `api/tests/` that fails without the fix. Code comments in the repository are in Czech.
