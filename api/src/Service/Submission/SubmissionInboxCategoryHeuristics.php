<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Rozpoznání systémové kategorie příchozí zprávy datové schránky.
 *
 * Čistá funkce nad fakty o zprávě, bez databáze. Rozhoduje v pořadí od
 * nejspolehlivějšího údaje:
 *
 *   1. vlastní odeslaná zpráva nebo doručenka (směr obálky, zařazení automatu),
 *   2. systémová schránka ISDS (`aaaaaaa`, typ odesílatele 0),
 *   3. druh adresáta z číselníku podání (finanční úřad, ČSSZ, pojišťovna),
 *   4. zařazení automatu (protokol ČSSZ, odpověď pojišťovny, finančního úřadu),
 *   5. typ schránky odesílatele (OVM_EXEKUT, insolvenční správce),
 *   6. jméno odesílatele (finanční a celní správa, sociální zabezpečení,
 *      zdravotní pojišťovny, soudy a exekutoři),
 *   7. věc zprávy (exekuce) — jen u úřadu nebo neznámého odesílatele,
 *   8. typ schránky: orgán veřejné moci → ostatní úřady, jinak obchodní partneři.
 *
 * `basis` říká, zda závěr stojí na odesílateli (`sender`) nebo na obsahu jedné
 * zprávy (`content`). Automatické pravidlo pro odesílatele se zakládá jen
 * u `sender`: kdyby se pravidlo opřelo o věc jedné zprávy, odneslo by do téže
 * kategorie i všechny další, nesouvisející zprávy stejného úřadu.
 */
final class SubmissionInboxCategoryHeuristics
{
    public const TAX_OFFICE = 'tax_office';
    public const SOCIAL_SECURITY = 'social_security';
    public const HEALTH_INSURANCE = 'health_insurance';
    public const COURTS_ENFORCEMENT = 'courts_enforcement';
    public const PUBLIC_AUTHORITY = 'public_authority';
    public const BUSINESS_PARTNERS = 'business_partners';
    public const ISDS_SYSTEM = 'isds_system';
    public const OWN_SUBMISSIONS = 'own_submissions';
    public const OTHER = 'other';

    /** Systémové kategorie v pořadí, v jakém je UI ukazuje. */
    public const SYSTEM_CODES = [
        self::TAX_OFFICE => 10,
        self::SOCIAL_SECURITY => 20,
        self::HEALTH_INSURANCE => 30,
        self::COURTS_ENFORCEMENT => 40,
        self::PUBLIC_AUTHORITY => 50,
        self::BUSINESS_PARTNERS => 60,
        self::ISDS_SYSTEM => 70,
        self::OWN_SUBMISSIONS => 80,
        self::OTHER => 90,
    ];

    /** Schránka, ze které ISDS posílá systémová oznámení. */
    public const ISDS_SYSTEM_BOX = 'aaaaaaa';

    private const RECIPIENT_KIND_CODES = [
        'tax_office' => self::TAX_OFFICE,
        'cssz' => self::SOCIAL_SECURITY,
        'health_insurer' => self::HEALTH_INSURANCE,
    ];

    private const CLASSIFICATION_CODES = [
        'cssz_protocol' => self::SOCIAL_SECURITY,
        'health_insurer_response' => self::HEALTH_INSURANCE,
        'tax_office_response' => self::TAX_OFFICE,
    ];

    /**
     * Jména odesílatelů bez diakritiky a malými písmeny. Krátké zkratky jen jako
     * celé slovo, jinak by „ozp" trefilo kdejaké jméno firmy.
     */
    private const SENDER_NAME_PATTERNS = [
        self::TAX_OFFICE => '/financni urad|financni reditelstvi|financni sprav|celni urad|generalni reditelstvi cel|\bgfr\b/u',
        self::SOCIAL_SECURITY => '/socialniho zabezpeceni|\b(cssz|ossz|pssz|mssz)\b/u',
        self::HEALTH_INSURANCE => '/zdravotni pojistovn|pojistovna .*zdravotni|zamestnanecka pojistovna|revirni bratrska pokladna|\b(vzp|vozp|cpzp|ozp|zpmv|rbp|zps)\b/u',
        self::COURTS_ENFORCEMENT => '/\bsoud\b|\bsoudu\b|okresni soud|krajsky soud|mestsky soud|obvodni soud|vrchni soud|nejvyssi soud|ustavni soud|exekutor|exekucni urad|insolvencni sprav/u',
    ];

    private const ENFORCEMENT_SUBJECT = '/exekuc|exekutor|insolvenc|srazk\w* ze mzdy/u';

    /**
     * @param array{
     *   classification?:?string, sender_box_id?:?string, sender_name?:?string,
     *   subject?:?string, sender_type?:?int, envelope_direction?:?string,
     *   sender_is_public_authority?:?bool, recipient_kind?:?string, own_box?:bool
     * } $facts
     * @return array{code:string,basis:'sender'|'content'}
     */
    public static function decide(array $facts): array
    {
        $classification = (string) ($facts['classification'] ?? '');
        $box = strtolower(trim((string) ($facts['sender_box_id'] ?? '')));
        $senderType = $facts['sender_type'] ?? null;
        $name = self::fold((string) ($facts['sender_name'] ?? ''));
        $subject = self::fold((string) ($facts['subject'] ?? ''));

        if (self::isOwn($facts)) {
            return ['code' => self::OWN_SUBMISSIONS, 'basis' => ($facts['own_box'] ?? false) === true ? 'sender' : 'content'];
        }
        if ($box === self::ISDS_SYSTEM_BOX || $senderType === 0) {
            return ['code' => self::ISDS_SYSTEM, 'basis' => 'sender'];
        }
        $kind = (string) ($facts['recipient_kind'] ?? '');
        if (isset(self::RECIPIENT_KIND_CODES[$kind])) {
            return ['code' => self::RECIPIENT_KIND_CODES[$kind], 'basis' => 'sender'];
        }
        if (isset(self::CLASSIFICATION_CODES[$classification])) {
            return ['code' => self::CLASSIFICATION_CODES[$classification], 'basis' => 'sender'];
        }
        // OVM_EXEKUT (12) a insolvenční správce (33) podle typu schránky ISDS.
        if ($senderType === 12 || $senderType === 33) {
            return ['code' => self::COURTS_ENFORCEMENT, 'basis' => 'sender'];
        }
        if ($name !== '') {
            foreach (self::SENDER_NAME_PATTERNS as $code => $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    return ['code' => $code, 'basis' => 'sender'];
                }
            }
        }
        $isAuthority = self::isPublicAuthority($facts);
        if ($subject !== '' && ($isAuthority || $senderType === null)
            && preg_match(self::ENFORCEMENT_SUBJECT, $subject) === 1
        ) {
            return ['code' => self::COURTS_ENFORCEMENT, 'basis' => 'content'];
        }
        if ($isAuthority) {
            return ['code' => self::PUBLIC_AUTHORITY, 'basis' => 'sender'];
        }
        if (is_int($senderType) && $senderType >= 20 && $senderType < 50) {
            return ['code' => self::BUSINESS_PARTNERS, 'basis' => 'sender'];
        }

        return ['code' => self::OTHER, 'basis' => 'content'];
    }

    /** @param array<string,mixed> $facts */
    public static function isOwn(array $facts): bool
    {
        return ($facts['classification'] ?? null) === InboxMessageClassifier::DELIVERY_RECEIPT
            || ($facts['envelope_direction'] ?? null) === 'sent'
            || ($facts['own_box'] ?? false) === true;
    }

    /** @param array<string,mixed> $facts */
    private static function isPublicAuthority(array $facts): bool
    {
        $senderType = $facts['sender_type'] ?? null;
        if (is_int($senderType) && $senderType >= 10 && $senderType < 20) {
            return true;
        }
        return ($facts['sender_is_public_authority'] ?? null) === true;
    }

    /** Malá písmena bez české a slovenské diakritiky — pro porovnání textů. */
    public static function fold(string $value): string
    {
        return mb_strtolower(strtr($value, [
            'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ř' => 'r',
            'ŕ' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y',
            'ž' => 'z', 'Á' => 'A', 'Ä' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E',
            'Í' => 'I', 'Ĺ' => 'L', 'Ľ' => 'L', 'Ň' => 'N', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ř' => 'R', 'Ŕ' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ü' => 'U',
            'Ý' => 'Y', 'Ž' => 'Z',
        ]), 'UTF-8');
    }
}
