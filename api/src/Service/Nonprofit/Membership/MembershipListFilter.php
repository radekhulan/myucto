<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

final class MembershipListFilter
{
    public static function parse(array $query, ?\DateTimeImmutable $now = null): array
    {
        $search = $query['search'] ?? '';
        $title = $query['title_key'] ?? '';
        // Config připíná PHP zónu na app.timezone; stejné účetní datum používá appIsoDate na webu.
        $today = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        // Chybějící rok znamená aktuální rok; prázdný řetězec z adresy i null z JSON těla znamenají „bez roku“.
        $year = array_key_exists('year', $query) ? ($query['year'] ?? '') : (int) $today->format('Y');
        $status = $query['payment_status'] ?? 'all';
        if (!is_string($search) || mb_strlen(trim($search)) > 160 || !is_string($title)
            || !in_array($status, ['all', 'paid', 'unpaid', 'unproven', 'none'], true)) {
            self::invalid();
        }
        $search = trim($search);
        $title = trim($title);
        if ($title !== '') {
            $title = MembershipChargeRequest::key($title, 'titulu');
        }
        if ($year === '') {
            $year = null;
        } elseif ((is_int($year) || is_string($year)) && preg_match('/^[0-9]{4}$/D', (string) $year) && (int) $year >= 1900 && (int) $year <= 9998) {
            $year = (int) $year;
        } else {
            self::invalid();
        }
        if ($year === null && $title === '') {
            self::invalid();
        }
        if ($year === null && $status !== 'all') {
            throw new MembershipException('nonprofit_membership_list_year_required', 'Pro filtr stavu úhrady vyberte konkrétní rok.', 422);
        }
        return ['search' => $search, 'title_key' => $title, 'year' => $year, 'payment_status' => $status];
    }

    private static function invalid(): never
    {
        throw new MembershipException('nonprofit_membership_list_filter_invalid', 'Zadejte platný rok nebo označení příspěvku, hledání nejvýše 160 znaků a platný stav úhrady.', 422);
    }
}
