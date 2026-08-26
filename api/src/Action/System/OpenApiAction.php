<?php

declare(strict_types=1);

namespace MyInvoice\Action\System;

use MyInvoice\Bootstrap;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public servírování OpenAPI specifikace a doc stránek.
 *
 *   GET /api/openapi.yaml  → ručně psaný spec v api/openapi.yaml
 *   GET /api/docs          → Swagger UI 5 (Try it out + Authorize)
 *   GET /api/reference     → Redoc (pretty static)
 *   GET /api/scalar        → Scalar (moderní reference + Try it out)
 *
 * Design je sladěn s landing pageem myinvoice.web (stejné CSS, branding,
 * navigační hlavička). Liší se jen:
 *   - Try-it-out je tady ENABLED (živá instance má API)
 *   - manuál link míří na PHP renderer `/manual/?ch=20` (ne static .html)
 *   - chybí "reference-only" info banner (na instanci API reálně funguje)
 */
final class OpenApiAction
{
    public function reference(Request $request, Response $response): Response
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>MyÚčto.cz API — Reference (Redoc)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="REST API MyÚčto.cz — kompletní referenční dokumentace endpointů.">
  <link rel="icon" type="image/svg+xml" href="/styles/logo.svg">
  <style>
    :root {
      --primary: #6753AE;
      --primary-dark: #3B2D83;
      --primary-soft: #ede9fe;
      --primary-softer: #f5f3ff;
      --accent: #f59e0b;
      --bg: #f5f3fb;
      --panel: #ffffff;
      --border: #ecebe9;
      --text: #1f2937;
      --muted: #6b7280;
      --ink: #15131D;
    }
    * { box-sizing: border-box; }
    html { font-size: 16px; }
    html, body { overflow-x: hidden; }
    :root { --hh: 72px; }
    body {
      margin: 0; padding: var(--hh) 0 0 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", sans-serif;
      color: var(--text);
      background: var(--bg);
    }
    a { color: var(--primary); text-decoration: none; }
    a:hover { color: var(--primary-dark); }

    .site-header {
      padding: 0 24px;
      position: fixed; top: 0; left: 0; right: 0;
      z-index: 1000;
      background: rgba(245, 243, 251, 0.92);
      backdrop-filter: saturate(180%) blur(14px);
      -webkit-backdrop-filter: saturate(180%) blur(14px);
      border-bottom: 1px solid var(--border);
      box-shadow: 0 6px 22px rgba(59, 45, 131, 0.08), 0 1px 0 rgba(59, 45, 131, 0.04);
      min-height: var(--hh);
      box-sizing: border-box;
    }
    .site-header .row {
      min-height: var(--hh);
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      justify-content: space-between;
      padding: 10px 0;
    }
    .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: var(--text); }
    .brand:hover { text-decoration: none; }
    .brand img { width: 40px; height: 40px; border-radius: 9px; }
    .brand .name { font-size: 19px; font-weight: 700; letter-spacing: -0.02em; color: var(--primary-dark); line-height: 1.1; }
    .brand .tag { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .header-cta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .nav-link {
      display: inline-flex; align-items: center; gap: 6px;
      color: var(--primary-dark); text-decoration: none;
      font-size: 14px; font-weight: 500;
      padding: 8px 14px; border-radius: 10px;
      border: 1px solid transparent;
      transition: all .15s;
    }
    .nav-link:hover { background: #fff; border-color: var(--border); color: var(--primary-dark); text-decoration: none; }
    .nav-link.is-current { background: #fff; border-color: var(--border); }
    .btn-github {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--ink); color: #fff;
      font-size: 14px; font-weight: 600;
      padding: 9px 16px; border-radius: 10px;
      text-decoration: none;
      box-shadow: 0 4px 14px rgba(21, 19, 29, 0.18);
      transition: all .2s;
    }
    .btn-github:hover { background: #25232f; color: #fff; transform: translateY(-1px); }
    .btn-github svg { width: 16px; height: 16px; fill: currentColor; }

    /* Redoc sidebar: vynutíme fixed, aby zůstal nalevo i po hash navigaci */
    #redoc-container .menu-content {
      position: fixed !important;
      left: 0 !important;
      top: var(--hh) !important;
      width: 320px !important;
      height: calc(100vh - var(--hh)) !important;
      transform: none !important;
      box-shadow: 1px 0 0 var(--border);
      z-index: 50;
    }
    /* Hlavní obsah (api-content) má místo sidebaru padding-left, aby zabíral celou šíři */
    #redoc-container .api-content {
      width: 100% !important;
      max-width: none !important;
      padding-left: 320px;
      box-sizing: border-box;
    }
    /* Fixed tmavý sloupec vpravo, aby se pravý panel "scelil" mezi sekcemi */
    body::before {
      content: '';
      position: fixed;
      top: var(--hh); right: 0; bottom: 0;
      width: calc((100vw - 320px) * 0.42);
      background: #15131D;
      z-index: -1;
      pointer-events: none;
    }
    /* Search box v sidebaru sjednotit se zbytkem webu */
    #redoc-container [role="search"] input,
    #redoc-container input[type="text"] {
      border-radius: 10px !important;
    }
    @media (max-width: 900px) {
      #redoc-container .menu-content { display: none !important; }
      #redoc-container .api-content { padding-left: 0 !important; }
      body::before { display: none !important; }
    }

    @media (max-width: 640px) {
      :root { --hh: 124px; }
      .brand .tag { display: none; }
      .nav-link span.lbl { display: none; }
    }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="row">
      <a href="/" class="brand">
        <img src="/styles/logo.svg" alt="MyÚčto.cz">
        <div>
          <div class="name">MyÚčto.cz</div>
          <div class="tag">REST API · referenční dokumentace</div>
        </div>
      </a>
      <div class="header-cta">
        <a class="nav-link" href="/api/docs" title="Swagger UI">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
          <span class="lbl">Swagger UI</span>
        </a>
        <a class="nav-link is-current" href="/api/reference" title="Redoc reference">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
          <span class="lbl">Reference</span>
        </a>
        <a class="nav-link" href="/api/scalar" title="Scalar reference">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
          <span class="lbl">Scalar</span>
        </a>
        <a class="nav-link" href="/api/openapi.yaml" target="_blank" title="Stáhnout openapi.yaml">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          <span class="lbl">openapi.yaml</span>
        </a>
        <a class="nav-link" href="/manual/?ch=99_API" target="_blank" title="Manuál API">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          <span class="lbl">Manuál</span>
        </a>
        <a href="https://github.com/radekhulan/myucto" target="_blank" rel="noopener" class="btn-github">
          <svg viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.4 3-.405 1.02.005 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
          GitHub
        </a>
      </div>
    </div>
  </header>

  <div id="redoc-container"></div>
  <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
  <script>
    Redoc.init('/api/openapi.yaml', {
      scrollYOffset: '.site-header',
      hideDownloadButton: false,
      expandResponses: '200,201',
      expandSingleSchemaField: true,
      jsonSampleExpandLevel: 4,
      requiredPropsFirst: true,
      pathInMiddlePanel: true,
      nativeScrollbars: true,
      theme: {
        spacing: {
          unit: 6,
          sectionHorizontal: 48,
          sectionVertical: 32
        },
        breakpoints: {
          small: '50rem',
          medium: '78rem',
          large: '105rem'
        },
        colors: {
          primary: { main: '#6753AE' },
          success: { main: '#21A86A' },
          warning: { main: '#D49C2E' },
          error:   { main: '#D45B5B' },
          text:    { primary: '#15131D', secondary: '#3F3A52' },
          border:  { dark: '#3B2D83', light: '#ecebe9' },
          http: {
            get:    '#21A86A',
            post:   '#6753AE',
            put:    '#D49C2E',
            delete: '#D45B5B',
            patch:  '#7E6DD6'
          }
        },
        typography: {
          fontSize: '16px',
          lineHeight: '1.65em',
          fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", sans-serif',
          smoothing: 'antialiased',
          optimizeSpeed: false,
          headings: {
            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", sans-serif',
            fontWeight: '800',
            lineHeight: '1.25em'
          },
          code: {
            fontFamily: '"Geist Mono", ui-monospace, Consolas, monospace',
            fontSize: '14px',
            lineHeight: '1.55em',
            color:    '#3B2D83',
            backgroundColor: '#f5f3ff',
            wrap: true
          },
          links: { color: '#6753AE', visited: '#6753AE', hover: '#3B2D83' }
        },
        sidebar: {
          backgroundColor: '#f5f3fb',
          textColor: '#15131D',
          width: '320px'
        },
        rightPanel: {
          backgroundColor: '#15131D',
          textColor: '#F4F2F8',
          width: '42%'
        },
        codeBlock: {
          backgroundColor: '#0F0E1A'
        },
        logo: {
          gutter: '24px'
        }
      }
    }, document.getElementById('redoc-container'));
  </script>
</body>
</html>
HTML;
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function spec(Request $request, Response $response): Response
    {
        $path = Bootstrap::rootDir() . '/api/openapi.yaml';
        $body = @file_get_contents($path);
        if ($body === false) {
            $response->getBody()->write('# openapi.yaml not deployed');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'application/yaml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withHeader('Access-Control-Allow-Origin', '*'); // Postman/Insomnia import
    }

    public function docs(Request $request, Response $response): Response
    {
        // Swagger UI 5.x z unpkg CDN. Na živé instanci je Try-it-out ENABLED —
        // klient může vložit token přes Authorize a volat reálné endpointy.
        $html = <<<'HTML'
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>MyÚčto.cz API — Dokumentace (Swagger UI)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="REST API MyÚčto.cz — interaktivní dokumentace endpointů pro automatizaci a integrace.">
  <link rel="icon" type="image/svg+xml" href="/styles/logo.svg">
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
  <style>
    :root {
      --primary: #6753AE;
      --primary-dark: #3B2D83;
      --primary-soft: #ede9fe;
      --primary-softer: #f5f3ff;
      --accent: #f59e0b;
      --accent-soft: #fef3c7;
      --accent-text: #78350f;
      --bg: #f5f3fb;
      --panel: #ffffff;
      --border: #ecebe9;
      --text: #1f2937;
      --muted: #6b7280;
      --ink: #15131D;
      --shadow-sm: 0 2px 8px rgba(59, 45, 131, 0.06);
      --shadow: 0 6px 22px rgba(59, 45, 131, 0.08);
    }
    * { box-sizing: border-box; }
    html, body { overflow-x: hidden; }
    body {
      margin: 0; padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", sans-serif;
      color: var(--text);
      background: var(--bg);
      background-image: radial-gradient(ellipse 1400px 700px at 50% -10%, #ede9fe 0%, transparent 65%);
      background-attachment: fixed;
    }
    a { color: var(--primary); text-decoration: none; }
    a:hover { color: var(--primary-dark); }

    .site-header {
      padding: 14px 0;
      position: sticky; top: 0; z-index: 100;
      background: rgba(245, 243, 251, 0.92);
      backdrop-filter: saturate(180%) blur(14px);
      -webkit-backdrop-filter: saturate(180%) blur(14px);
      border-bottom: 1px solid var(--border);
      box-shadow: 0 6px 22px rgba(59, 45, 131, 0.08), 0 1px 0 rgba(59, 45, 131, 0.04);
    }
    .site-header .row {
      max-width: 1280px; margin: 0 auto; padding: 0 24px;
      display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
      justify-content: space-between;
    }
    .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: var(--text); }
    .brand:hover { text-decoration: none; }
    .brand img { width: 40px; height: 40px; border-radius: 9px; }
    .brand .name { font-size: 19px; font-weight: 700; letter-spacing: -0.02em; color: var(--primary-dark); line-height: 1.1; }
    .brand .tag { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .badge-pill {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--primary-soft); color: var(--primary-dark);
      font-size: 11px; font-weight: 700; padding: 5px 11px; border-radius: 999px;
      letter-spacing: 0.06em; text-transform: uppercase;
    }
    .header-cta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .nav-link {
      display: inline-flex; align-items: center; gap: 6px;
      color: var(--primary-dark); text-decoration: none;
      font-size: 14px; font-weight: 500;
      padding: 8px 14px; border-radius: 10px;
      border: 1px solid transparent;
      transition: all .15s;
    }
    .nav-link:hover { background: #fff; border-color: var(--border); color: var(--primary-dark); text-decoration: none; }
    .nav-link.is-current { background: #fff; border-color: var(--border); }
    .btn-github {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--ink); color: #fff;
      font-size: 14px; font-weight: 600;
      padding: 9px 16px; border-radius: 10px;
      text-decoration: none;
      box-shadow: 0 4px 14px rgba(21, 19, 29, 0.18);
      transition: all .2s;
    }
    .btn-github:hover { background: #25232f; color: #fff; transform: translateY(-1px); }
    .btn-github svg { width: 16px; height: 16px; fill: currentColor; }

    .page-intro {
      max-width: 1280px; margin: 0 auto; padding: 28px 24px 8px;
    }
    .page-intro h1 {
      font-size: clamp(26px, 3.4vw, 34px); font-weight: 800;
      letter-spacing: -0.02em; color: var(--ink);
      margin: 8px 0 10px;
    }
    .page-intro h1 .grad {
      background: linear-gradient(120deg, var(--primary) 0%, var(--primary-dark) 100%);
      -webkit-background-clip: text; background-clip: text;
      -webkit-text-fill-color: transparent; color: transparent;
    }
    .page-intro p { margin: 0 0 18px; color: #4b5563; font-size: 16px; max-width: 780px; line-height: 1.6; }

    /* Swagger UI host */
    #swagger-ui {
      max-width: 1280px; margin: 16px auto 60px; padding: 0 16px;
    }
    .swagger-ui .topbar { display: none; }
    .swagger-ui, .swagger-ui .info .title, .swagger-ui .opblock-tag, .swagger-ui table thead tr td, .swagger-ui table thead tr th, .swagger-ui .opblock .opblock-section-header h4, .swagger-ui .parameter__name, .swagger-ui .response-col_status {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", sans-serif;
    }
    .swagger-ui .info .title { color: var(--ink); }
    .swagger-ui .info .title small.version-stamp { background: var(--primary); }
    .swagger-ui .info a { color: var(--primary); }
    .swagger-ui .info a:hover { color: var(--primary-dark); }
    .swagger-ui .scheme-container {
      background: #fff;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border);
      border-radius: 14px;
      margin: 0 0 24px;
    }
    .swagger-ui .opblock-tag {
      color: var(--ink);
      border-bottom: 1px solid var(--border);
      font-weight: 700;
    }
    .swagger-ui .opblock-tag small { color: var(--muted); font-weight: 400; }
    .swagger-ui .opblock {
      border-radius: 12px;
      box-shadow: var(--shadow-sm);
      margin: 0 0 14px;
    }
    .swagger-ui .opblock .opblock-summary { border-radius: 12px; }
    .swagger-ui .opblock.opblock-get    { background: rgba(33,168,106,0.05); border-color: rgba(33,168,106,0.30); }
    .swagger-ui .opblock.opblock-post   { background: rgba(59,45,131,0.05); border-color: rgba(59,45,131,0.30); }
    .swagger-ui .opblock.opblock-put    { background: rgba(212,156,46,0.05); border-color: rgba(212,156,46,0.30); }
    .swagger-ui .opblock.opblock-delete { background: rgba(212,91,91,0.05); border-color: rgba(212,91,91,0.30); }
    .swagger-ui .opblock.opblock-patch  { background: rgba(126,109,214,0.05); border-color: rgba(126,109,214,0.30); }
    .swagger-ui .opblock.opblock-get    .opblock-summary-method { background: #21A86A; }
    .swagger-ui .opblock.opblock-post   .opblock-summary-method { background: var(--primary); }
    .swagger-ui .opblock.opblock-put    .opblock-summary-method { background: #D49C2E; }
    .swagger-ui .opblock.opblock-delete .opblock-summary-method { background: #D45B5B; }
    .swagger-ui .opblock.opblock-patch  .opblock-summary-method { background: #7E6DD6; }
    .swagger-ui .opblock-summary-path,
    .swagger-ui .opblock-summary-path__deprecated {
      color: var(--ink); font-family: "Geist Mono", ui-monospace, Consolas, monospace;
    }
    .swagger-ui .btn {
      border-radius: 10px; font-weight: 600;
    }
    .swagger-ui .btn.authorize,
    .swagger-ui .btn.execute {
      background: var(--primary); color: #fff; border-color: var(--primary);
      box-shadow: 0 4px 14px rgba(103, 83, 174, 0.22);
    }
    .swagger-ui .btn.authorize:hover,
    .swagger-ui .btn.execute:hover {
      background: var(--primary-dark); border-color: var(--primary-dark);
    }
    .swagger-ui .btn.authorize svg { fill: #fff; }
    .swagger-ui .btn-clear { color: var(--muted); }
    .swagger-ui select, .swagger-ui input[type="text"], .swagger-ui input[type="password"], .swagger-ui input[type="email"], .swagger-ui textarea {
      border-radius: 8px; border: 1px solid var(--border);
    }
    .swagger-ui .filter .operation-filter-input {
      border-radius: 10px; border: 1px solid var(--border); padding: 10px 14px;
    }
    .swagger-ui section.models {
      border-radius: 12px; border: 1px solid var(--border); background: #fff;
    }
    .swagger-ui section.models h4 { color: var(--ink); }
    .swagger-ui .model-title { color: var(--ink); }
    .swagger-ui .response-col_status { color: var(--ink); font-weight: 700; }
    .swagger-ui .markdown code, .swagger-ui .renderedMarkdown code {
      background: var(--primary-softer); color: var(--primary-dark);
      border-radius: 4px; padding: 1px 6px;
    }

    @media (max-width: 640px) {
      .brand .tag { display: none; }
      .nav-link span.lbl { display: none; }
      .page-intro { padding-top: 20px; }
    }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="row">
      <a href="/" class="brand">
        <img src="/styles/logo.svg" alt="MyÚčto.cz">
        <div>
          <div class="name">MyÚčto.cz</div>
          <div class="tag">REST API · interaktivní specifikace</div>
        </div>
      </a>
      <div class="header-cta">
        <a class="nav-link is-current" href="/api/docs" title="Swagger UI">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 2 13 9 20 9"/><path d="M20 9v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h7z"/></svg>
          <span class="lbl">Swagger UI</span>
        </a>
        <a class="nav-link" href="/api/reference" title="Redoc reference">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
          <span class="lbl">Reference</span>
        </a>
        <a class="nav-link" href="/api/scalar" title="Scalar reference">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
          <span class="lbl">Scalar</span>
        </a>
        <a class="nav-link" href="/api/openapi.yaml" target="_blank" title="Stáhnout openapi.yaml">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          <span class="lbl">openapi.yaml</span>
        </a>
        <a class="nav-link" href="/manual/?ch=99_API" target="_blank" title="Manuál API">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          <span class="lbl">Manuál</span>
        </a>
        <a href="https://github.com/radekhulan/myucto" target="_blank" rel="noopener" class="btn-github">
          <svg viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.4 3-.405 1.02.005 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
          GitHub
        </a>
      </div>
    </div>
  </header>

  <section class="page-intro">
    <span class="badge-pill">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
      REST API · v1
    </span>
    <h1>Interaktivní <span class="grad">dokumentace endpointů</span></h1>
    <p>Procházej kompletní specifikaci API této instance MyÚčto. Pro živé volání klikni na <strong>Authorize</strong>, vlož svůj <code>mi_pat_…</code> token a použij tlačítko „Try it out" u libovolného endpointu.</p>
  </section>

  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
  <script>
    window.ui = SwaggerUIBundle({
      url: '/api/openapi.yaml',
      dom_id: '#swagger-ui',
      deepLinking: true,
      docExpansion: 'list',
      defaultModelsExpandDepth: 1,
      defaultModelExpandDepth: 2,
      displayRequestDuration: true,
      filter: true,
      tryItOutEnabled: true,         // live instance — token + Try it out funguje
      persistAuthorization: true,
      requestSnippetsEnabled: true,
      syntaxHighlight: { activate: true, theme: 'agate' },
      presets: [
        SwaggerUIBundle.presets.apis,
        SwaggerUIStandalonePreset.slice(1)
      ],
      plugins: [SwaggerUIBundle.plugins.DownloadUrl],
      layout: 'BaseLayout'
    });
  </script>
</body>
</html>
HTML;
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function scalar(Request $request, Response $response): Response
    {
        // Scalar API Reference z jsDelivr CDN. Moderní reference + vestavěný
        // API klient (Try it out) — token přes "Authentication" panel.
        $html = <<<'HTML'
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>MyÚčto.cz API — Reference (Scalar)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="REST API MyÚčto.cz — moderní referenční dokumentace endpointů (Scalar) s vestavěným API klientem.">
  <link rel="icon" type="image/svg+xml" href="/styles/logo.svg">
  <style>
    :root {
      --primary: #6753AE;
      --primary-dark: #3B2D83;
      --primary-soft: #ede9fe;
      --primary-softer: #f5f3ff;
      --accent: #f59e0b;
      --bg: #f5f3fb;
      --panel: #ffffff;
      --border: #ecebe9;
      --text: #1f2937;
      --muted: #6b7280;
      --ink: #15131D;
    }
    * { box-sizing: border-box; }
    /* ⚠️ NEdávat overflow-x:hidden na html/body — CSS spec pak promotuje
       overflow-y na auto, body se stane scroll-kontejnerem a sticky hlavička
       se přilepí k němu místo k viewportu (= uteče při scrollu). */
    body {
      margin: 0; padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", sans-serif;
      color: var(--text);
      background: var(--bg);
    }
    a { color: var(--primary); text-decoration: none; }
    a:hover { color: var(--primary-dark); }

    /* Sticky hlavička drží nahoře; Scalarův sticky sidebar je odsazen pod ni
       přes --scalar-custom-header-height / --refs-header-height (viz JS níže),
       takže při scrollu okna sidebar neuteče. */
    .site-header {
      padding: 14px 0;
      position: sticky; top: 0; z-index: 1000;
      background: rgba(245, 243, 251, 0.92);
      backdrop-filter: saturate(180%) blur(14px);
      -webkit-backdrop-filter: saturate(180%) blur(14px);
      border-bottom: 1px solid var(--border);
      box-shadow: 0 6px 22px rgba(59, 45, 131, 0.08), 0 1px 0 rgba(59, 45, 131, 0.04);
    }
    .site-header .row {
      max-width: 1280px; margin: 0 auto; padding: 0 24px;
      display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
      justify-content: space-between;
    }
    .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: var(--text); }
    .brand:hover { text-decoration: none; }
    .brand img { width: 40px; height: 40px; border-radius: 9px; }
    .brand .name { font-size: 19px; font-weight: 700; letter-spacing: -0.02em; color: var(--primary-dark); line-height: 1.1; }
    .brand .tag { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .header-cta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .nav-link {
      display: inline-flex; align-items: center; gap: 6px;
      color: var(--primary-dark); text-decoration: none;
      font-size: 14px; font-weight: 500;
      padding: 8px 14px; border-radius: 10px;
      border: 1px solid transparent;
      transition: all .15s;
    }
    .nav-link:hover { background: #fff; border-color: var(--border); color: var(--primary-dark); text-decoration: none; }
    .nav-link.is-current { background: #fff; border-color: var(--border); }
    .btn-github {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--ink); color: #fff;
      font-size: 14px; font-weight: 600;
      padding: 9px 16px; border-radius: 10px;
      text-decoration: none;
      box-shadow: 0 4px 14px rgba(21, 19, 29, 0.18);
      transition: all .2s;
    }
    .btn-github:hover { background: #25232f; color: #fff; transform: translateY(-1px); }
    .btn-github svg { width: 16px; height: 16px; fill: currentColor; }

    /* Scalar si řídí výšku/scroll sám (scrolluje okno). */
    #scalar-app { min-height: calc(100vh - 69px); }

    @media (max-width: 640px) {
      .brand .tag { display: none; }
      .nav-link span.lbl { display: none; }
    }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="row">
      <a href="/" class="brand">
        <img src="/styles/logo.svg" alt="MyÚčto.cz">
        <div>
          <div class="name">MyÚčto.cz</div>
          <div class="tag">REST API · moderní reference</div>
        </div>
      </a>
      <div class="header-cta">
        <a class="nav-link" href="/api/docs" title="Swagger UI">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
          <span class="lbl">Swagger UI</span>
        </a>
        <a class="nav-link" href="/api/reference" title="Redoc reference">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
          <span class="lbl">Reference</span>
        </a>
        <a class="nav-link is-current" href="/api/scalar" title="Scalar reference">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
          <span class="lbl">Scalar</span>
        </a>
        <a class="nav-link" href="/api/openapi.yaml" target="_blank" title="Stáhnout openapi.yaml">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          <span class="lbl">openapi.yaml</span>
        </a>
        <a class="nav-link" href="/manual/?ch=99_API" target="_blank" title="Manuál API">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          <span class="lbl">Manuál</span>
        </a>
        <a href="https://github.com/radekhulan/myucto" target="_blank" rel="noopener" class="btn-github">
          <svg viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.4 3-.405 1.02.005 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
          GitHub
        </a>
      </div>
    </div>
  </header>

  <div id="scalar-app"></div>
  <script>
    var scalarConfig = {
      url: '/api/openapi.yaml',
      theme: 'default',
      darkMode: false,
      hideDownloadButton: false,
      withDefaultFonts: false,
      metaData: { title: 'MyÚčto.cz API — Scalar' },
      customCss: `
        .scalar-app, :root {
          --scalar-color-accent: #6753AE;
          --scalar-color-1: #15131D;
          --scalar-color-2: #3F3A52;
          --scalar-color-3: #6b7280;
          --scalar-background-1: #ffffff;
          --scalar-background-2: #f7f5fc;
          --scalar-background-3: #ede9fe;
          --scalar-background-accent: #ede9fe;
          --scalar-border-color: #ecebe9;
          --scalar-radius: 10px;
          --scalar-font: -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", sans-serif;
          --scalar-font-code: "Geist Mono", ui-monospace, Consolas, monospace;
          --scalar-color-green:  #21A86A;
          --scalar-color-blue:   #6753AE;
          --scalar-color-orange: #D49C2E;
          --scalar-color-yellow: #D49C2E;
          --scalar-color-red:    #D45B5B;
          --scalar-color-purple: #7E6DD6;
        }
        .scalar-app .sidebar {
          --scalar-sidebar-background-1: #f5f3fb;
          --scalar-sidebar-color-1: #15131D;
          --scalar-sidebar-color-2: #3F3A52;
          --scalar-sidebar-border-color: #ecebe9;
          --scalar-sidebar-item-hover-background: #ede9fe;
          --scalar-sidebar-item-active-background: #ede9fe;
          --scalar-sidebar-color-active: #3B2D83;
          --scalar-sidebar-search-background: #ffffff;
          --scalar-sidebar-search-border-color: #ecebe9;
        }
      `
    };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
  <script>
    Scalar.createApiReference('#scalar-app', scalarConfig);

    // Sdělíme Scalaru reálnou výšku naší sticky hlavičky → jeho sticky sidebar
    // i pravý panel se odsadí pod ni a při scrollu okna neutečou nahoru.
    (function () {
      var header = document.querySelector('.site-header');
      function syncHeaderOffset() {
        var h = header ? header.offsetHeight : 0;
        var root = document.documentElement;
        root.style.setProperty('--scalar-custom-header-height', h + 'px');
        root.style.setProperty('--refs-header-height', h + 'px');
      }
      syncHeaderOffset();
      window.addEventListener('resize', syncHeaderOffset);
      // Scalar mountuje asynchronně — doladíme po vykreslení.
      setTimeout(syncHeaderOffset, 300);
      setTimeout(syncHeaderOffset, 1200);
    })();
  </script>
</body>
</html>
HTML;
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
