<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Předškolní zařízení podle § 35bd odst. 6 ZDP (znění od 1. 1. 2027).
 *
 * Hodnoty jsou stabilní: jsou v databázi (`payroll_annual_settlement_preschool_claims`)
 * i v klíčích i18n.
 */
enum AnnualPreschoolFacilityKind: string
{
    /** Písm. a): mateřská škola podle školského zákona nebo obdobné zařízení v zahraničí. */
    case Kindergarten = 'kindergarten';

    /** Písm. b): dětská skupina podle zákona o dětské skupině nebo obdobné zařízení v zahraničí. */
    case ChildrenGroup = 'children_group';

    /**
     * Písm. c): zařízení péče o dítě předškolního věku provozované na základě
     * živnostenského oprávnění, je-li péče srovnatelná s dětskou skupinou nebo
     * mateřskou školou.
     */
    case LicensedCare = 'licensed_care';
}
