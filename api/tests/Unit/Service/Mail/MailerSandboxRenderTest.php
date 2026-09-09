<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Regression for issue #25 — Mailer::sandboxedTwig() must allow `extends`,
 * `block` a `use` tags. DB-uložené šablony dědí z `_layout.html.twig`
 * (viz `EmailTemplateAction::loadDefaults` který vrací celé tělo včetně
 * `{% extends %}{% block content %}`). Před fixem sandbox házel
 * `Tag "block" is not allowed in "_layout.html.twig" at line 63.` po každé
 * editaci šablony v adminu.
 *
 * Test nepotřebuje DB ani SMTP — invokuje privátní `sandboxedTwig()` přes
 * reflexi a renderuje minimální string template, která dědí ze skutečného
 * `_layout.html.twig` z `api/templates/email/`.
 */
final class MailerSandboxRenderTest extends TestCase
{
    private \Twig\Environment $sandbox;

    protected function setUp(): void
    {
        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Mailer::class, 'sandboxedTwig');
        $this->sandbox = $method->invoke($mailer);
    }

    public function testDbTemplateExtendsLayout(): void
    {
        // Tohle je přesně to, co user uloží přes admin UI po jakékoli úpravě:
        // celé tělo včetně extends/block dědící z _layout.html.twig.
        $body = "{% extends '_layout.html.twig' %}\n"
              . "{% block content %}\n"
              . "<p>Faktura {{ invoice.varsymbol }}</p>\n"
              . "{% endblock %}\n";

        $html = $this->sandbox->createTemplate($body)->render([
            'locale'   => 'cs',
            'subject'  => 'Test',
            'supplier' => null,
            'invoice'  => ['varsymbol' => '2605001'],
        ]);

        self::assertStringContainsString('Faktura 2605001', $html);
        self::assertStringContainsString('<!doctype html>', $html);
    }

    public function testSupplierWebsiteCannotExecuteScriptInPreview(): void
    {
        foreach (['javascript:alert(1)', "java\tscript:alert(1)", 'data:text/html,test', 'https://example.test'] as $url) {
            $html = $this->sandbox->createTemplate("{% extends '_layout.html.twig' %}{% block content %}Test{% endblock %}")->render([
                'locale' => 'cs', 'subject' => 'Test',
                'supplier' => ['company_name' => 'Synthetic', 'web' => $url, 'email_branding_enabled' => true],
            ]);
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $found = false;
            foreach ($dom->getElementsByTagName('a') as $anchor) {
                if ($anchor->getAttribute('href') === $url) $found = true;
            }
            self::assertSame(str_starts_with($url, 'https://'), $found, $url);
        }
    }

    public function testDbTextTemplateExtendsLayout(): void
    {
        $body = "{% extends '_layout.txt.twig' %}\n"
              . "{% block content %}Varsymbol: {{ invoice.varsymbol }}{% endblock %}\n";

        $text = $this->sandbox->createTemplate($body)->render([
            'locale'   => 'cs',
            'supplier' => null,
            'invoice'  => ['varsymbol' => '2605001'],
        ]);

        self::assertStringContainsString('Varsymbol: 2605001', $text);
    }

    public function testSandboxStillBlocksDangerousTags(): void
    {
        // Defense-in-depth — fix #25 neuvolnil sandbox víc, než je třeba.
        // `include` zůstává zakázaný (mohl by načíst arbitrary template, byť
        // omezený FilesystemLoader rootem na api/templates/email/).
        $this->expectException(\Twig\Sandbox\SecurityNotAllowedTagError::class);
        $this->sandbox->createTemplate("{% include '_layout.html.twig' %}")->render([]);
    }

    public function testValidateUserTemplateAcceptsDefaults(): void
    {
        // Issue #25 follow-up — `validateUserTemplate` musí pustit default šablonu,
        // kterou loadDefaults() vrátí, jinak by uživatel nemohl ani jen kliknout Uložit.
        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $defaultHtml = (string) file_get_contents(dirname(__DIR__, 4) . '/templates/email/invoice_send.cs.html.twig');
        $defaultText = (string) file_get_contents(dirname(__DIR__, 4) . '/templates/email/invoice_send.cs.txt.twig');
        self::assertNotSame('', $defaultHtml);
        self::assertNotSame('', $defaultText);
        self::assertNull($mailer->validateUserTemplate($defaultHtml, $defaultText));
    }

    public function testValidateUserTemplateRejectsForbiddenTag(): void
    {
        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $bad = "{% include '_layout.html.twig' %}";
        $result = $mailer->validateUserTemplate($bad, 'plain text ok');
        self::assertNotNull($result);
        self::assertSame('body_html', $result['field']);
        self::assertStringContainsString('include', $result['message']);
    }

    public function testValidateUserTemplateRejectsForbiddenFilter(): void
    {
        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        // `url_encode` není v allowed filters.
        $bad = "{{ foo|url_encode }}";
        $result = $mailer->validateUserTemplate('html ok', $bad);
        self::assertNotNull($result);
        self::assertSame('body_text', $result['field']);
        self::assertStringContainsString('url_encode', $result['message']);
    }

    public function testValidateUserTemplateReportsSyntaxError(): void
    {
        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $bad = "{% if foo %}unclosed";
        $result = $mailer->validateUserTemplate($bad, 'ok');
        self::assertNotNull($result);
        self::assertStringContainsString('syntax', strtolower($result['message']));
    }
}
