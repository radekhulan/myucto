<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\SalesQuote;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Mail\EmailTemplateCatalog;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\SalesQuote\QuoteMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class QuoteMailTemplateTest extends TestCase
{
    public function testQuoteMessageRemainsEditableAndEscapedInBothLanguages(): void
    {
        $config = new Config([]);
        $db = new Connection($config);
        $mailer = new Mailer($config, new NullLogger(), $db, new EmailTemplateRepository($db));
        $catalog = new EmailTemplateCatalog($config);

        foreach (EmailTemplateCatalog::LOCALES as $locale) {
            $code = QuoteMailer::TEMPLATE;
            $template = $catalog->defaults($code, $locale);
            self::assertTrue($catalog->isSupplierTemplate($code, $locale));
            self::assertNotNull($template);
            self::assertNull($mailer->validateSupplierTemplate($code, $locale, $template));

            $message = "<b>Rozsah & cena</b>\nDalší řádek";
            $rendered = $mailer->previewUserTemplate($code, $locale, $template, ['message' => $message]);
            self::assertStringContainsString('NAB-2026-001', $rendered['subject']);
            self::assertStringContainsString('&lt;b&gt;Rozsah &amp; cena&lt;/b&gt;', $rendered['body_html']);
            self::assertStringNotContainsString('<b>Rozsah & cena</b>', $rendered['body_html']);
            self::assertStringContainsString($message, $rendered['body_text']);

            $template['body_html'] = '<p hidden>{{ message }}</p><p>Cenová nabídka</p>';
            self::assertSame('body_html', $mailer->validateSupplierTemplate($code, $locale, $template)['field']);
        }
    }
}
