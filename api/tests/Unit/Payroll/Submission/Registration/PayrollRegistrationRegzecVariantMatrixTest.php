<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventVariantRule;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Matice zakázaných částí REGZEC25 podle variant (EDV 1.4.0.6, list Slovník,
 * kód „/" = nesmí být uvedeno, podání bude zamítnuto).
 *
 * Každá varianta dostane maximální vstup (všechny skupiny, které aplikace umí
 * zapsat). Část zakázaná pro variantu nesmí ve větě být; aby to nebyla prázdná
 * kontrola, musí se táž část objevit aspoň v jedné variantě, kde zakázaná není.
 * Data jsou syntetická.
 */
final class PayrollRegistrationRegzecVariantMatrixTest extends TestCase
{
    private const NS = 'http://schemas.cssz.cz/REGZEC/2025';

    /**
     * Řádek normy => [cesta pod employee, varianty se zákazem].
     *
     * @var array<string,array{string,list<string>}>
     */
    private const FORBIDDEN = [
        'client.adr-03' => ['client/adr', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.adr.cit-06' => ['client/adr/@cit', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.adr.cnt-06' => ['client/adr/@cnt', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.adr.num-06' => ['client/adr/@num', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.adr.onum-03' => ['client/adr/@onum', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.adr.pnu-06' => ['client/adr/@pnu', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.adr.ruianpoint-03' => ['client/adr/@ruianpoint', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.adr.str-03' => ['client/adr/@str', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.birth-03' => ['client/birth', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.birth.cit-04' => ['client/birth/@cit', ['A2-OST', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.birth.dat-04' => ['client/birth/@dat', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.birth.nam-04' => ['client/birth/@nam', ['A2-OST', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.birth.stat-04' => ['client/birth/@stat', ['A2-OST', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.bno-04' => ['client/@bno', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.cdr.cit-05' => ['client/cdr/@cit', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.cdr.cnt-05' => ['client/cdr/@cnt', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.cdr.num-05' => ['client/cdr/@num', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.cdr.onum-03' => ['client/cdr/@onum', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.cdr.pnu-05' => ['client/cdr/@pnu', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.cdr.ruianpoint-03' => ['client/cdr/@ruianpoint', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.cdr.str-03' => ['client/cdr/@str', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.name-03' => ['client/name', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.name.fir-04' => ['client/name/@fir', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.name.sur-04' => ['client/name/@sur', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.name.tit-03' => ['client/name/@tit', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.rdr-02' => ['client/rdr', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.rdr.cit-05' => ['client/rdr/@cit', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.rdr.cnt-05' => ['client/rdr/@cnt', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.rdr.num-05' => ['client/rdr/@num', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.rdr.onum-03' => ['client/rdr/@onum', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.rdr.pnu-05' => ['client/rdr/@pnu', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.rdr.str-03' => ['client/rdr/@str', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.stat-03' => ['client/stat', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.stat.cnt-04' => ['client/stat/@cnt', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'client.stat.mal-04' => ['client/stat/@mal', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'comp.nvs-03' => ['comp/@nvs', ['A1-OST', 'A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-OST', 'A4-10', 'A4-SPEC', 'A6-OST', 'A7-OST', 'A8-OST']],
        'fact-03' => ['fact', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'fact.healtrest-02' => ['fact/healtrest', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'fact.healtrest.fro-04' => ['fact/healtrest/@fro', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'fact.healtrest.to-04' => ['fact/healtrest/@to', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'fact.healtrest.type-03' => ['fact/healtrest/@type', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'fact.highedu-04' => ['fact/@highedu', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'fact.ztp-04' => ['fact/@ztp', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'forin-03' => ['forin', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.cit-04' => ['forin/@cit', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.cnt-05' => ['forin/@cnt', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.cur-05' => ['forin/@cur', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.id-04' => ['forin/@id', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.nam-04' => ['forin/@nam', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.num-04' => ['forin/@num', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.onum-03' => ['forin/@onum', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.pnu-04' => ['forin/@pnu', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.sec-03' => ['forin/@sec', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forin.str-03' => ['forin/@str', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A8-OST']],
        'forinreg-03' => ['forinreg', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'forinreg.juris-04' => ['forinreg/@juris', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'forinreg.state-04' => ['forinreg/@state', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'inso-02' => ['inso', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'inso.nam-04' => ['inso/@nam', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'insp-02' => ['insp', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'insp.nam-04' => ['insp/@nam', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.cit-04' => ['job/@cit', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.cont-04' => ['job/@cont', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.contractfro-04' => ['job/@contractfro', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.contractplace-04' => ['job/@contractplace', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.endbydeath-05' => ['job/@endbydeath', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.fro-04' => ['job/@fro', ['A2-OST', 'A2-10', 'A2-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.municode-04' => ['job/@municode', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.notstart-03' => ['job/@notstart', ['A1-OST', 'A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-OST', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST']],
        'job.place-04' => ['job/@place', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.position-03' => ['job/position', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.position.lead-04' => ['job/position/@lead', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.position.name-04' => ['job/position/@name', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.preplace-04' => ['job/@preplace', ['A1-10', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.prof-03' => ['job/prof', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.prof.clas-04' => ['job/prof/@clas', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.prof.edu-04' => ['job/prof/@edu', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.rel-03' => ['job/@rel', ['A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.relDetail-03' => ['job/@relDetail', ['A1-10', 'A2-10', 'A3-10', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.relat-04' => ['job/@relat', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.sme-04' => ['job/@sme', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.to-04' => ['job/@to', ['A3-10', 'A3-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'job.workmode-04' => ['job/@workmode', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'pens-03' => ['pens', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'pens.early-04' => ['pens/@early', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'pens.reducedAge-04' => ['pens/@reducedAge', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'pens.tak-04' => ['pens/@tak', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'pens.typ-04' => ['pens/@typ', ['A1-10', 'A1-SPEC', 'A2-OST', 'A2-10', 'A2-SPEC', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp-02' => ['unemplcomp', ['A1-10', 'A2-10', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.avgmonear-04' => ['unemplcomp/@avgmonear', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.belong-04' => ['unemplcomp/@belong', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.disposal-04' => ['unemplcomp/@disposal', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.earlyterm-04' => ['unemplcomp/@earlyterm', ['A1-10', 'A2-10', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.fullpay-04' => ['unemplcomp/@fullpay', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.goldenhandshake-04' => ['unemplcomp/@goldenhandshake', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.pensionperiod-02' => ['unemplcomp/pensionperiod', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.pensionperiod.fro-04' => ['unemplcomp/pensionperiod/@fro', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.pensionperiod.to-04' => ['unemplcomp/pensionperiod/@to', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.replacement-04' => ['unemplcomp/@replacement', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.rsn-03' => ['unemplcomp/@rsn', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.rsnterempl-04' => ['unemplcomp/@rsnterempl', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.rsnterrel-04' => ['unemplcomp/@rsnterrel', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.severancepay-04' => ['unemplcomp/@severancepay', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
        'unemplcomp.typeempl-04' => ['unemplcomp/@typeempl', ['A1-10', 'A1-SPEC', 'A2-10', 'A2-SPEC', 'A3-OST', 'A3-10', 'A3-SPEC', 'A4-10', 'A4-SPEC', 'A5-OST', 'A6-OST', 'A7-OST', 'A8-OST']],
    ];

    /** Části, které aplikace nezapisuje v žádné variantě (orgán nemocenského pojištění mimo ČSSZ). */
    private const NEVER_WRITTEN = ['inso', 'inso/@nam', 'insp', 'insp/@nam'];

    /** Varianta => [akce, druh činnosti, bližší určení]. */
    private const VARIANTS = [
        'A1-OST' => [1, '1', '1'],
        'A1-10' => [1, '10', null],
        'A1-SPEC' => [1, '11', '1'],
        'A2-OST' => [2, '1', '1'],
        'A2-10' => [2, '10', null],
        'A2-SPEC' => [2, '11', '1'],
        'A3-OST' => [3, '1', '1'],
        'A3-10' => [3, '10', null],
        'A3-SPEC' => [3, '11', '1'],
        'A4-OST' => [4, '1', '1'],
        'A4-10' => [4, '10', null],
        'A4-SPEC' => [4, '11', '1'],
        'A5-OST' => [5, '1', '1'],
        'A6-OST' => [6, '1', '1'],
        'A7-OST' => [7, '1', '1'],
        'A8-OST' => [8, '1', '1'],
    ];

    private const INTERACTIONS = [
        2 => 'termination', 3 => 'change', 4 => 'correction',
        5 => 'variable_symbol_transfer', 6 => 'czech_legislation_start',
        7 => 'czech_legislation_end', 8 => 'cancellation',
    ];

    /** @var array<string,string> */
    private static array $xml = [];

    /** @return iterable<string,array{string}> */
    public static function variants(): iterable
    {
        foreach (array_keys(self::VARIANTS) as $variant) {
            yield $variant => [$variant];
        }
    }

    #[DataProvider('variants')]
    public function testForbiddenPartsAreAbsentFromTheVariant(string $variant): void
    {
        $xpath = self::xpath(self::xmlFor($variant));
        $present = [];
        foreach (self::FORBIDDEN as $row => [$path, $variants]) {
            if (in_array($variant, $variants, true) && self::exists($xpath, $path)) {
                $present[] = "{$row} ({$path})";
            }
        }
        self::assertSame([], $present, "{$variant} nese zakázané části");
    }

    /** Bez svědka by nepřítomnost nic nedokazovala: část musí jinde vzniknout. */
    public function testEveryForbiddenPartIsWrittenWhereTheVariantAllowsIt(): void
    {
        $missing = [];
        foreach (self::FORBIDDEN as $row => [$path, $variants]) {
            if (in_array($path, self::NEVER_WRITTEN, true)) {
                continue;
            }
            $witness = false;
            foreach (array_keys(self::VARIANTS) as $variant) {
                if (!in_array($variant, $variants, true)
                    && self::exists(self::xpath(self::xmlFor($variant)), $path)
                ) {
                    $witness = true;
                    break;
                }
            }
            if (!$witness) {
                $missing[] = "{$row} ({$path})";
            }
        }
        self::assertSame([], $missing);
        foreach (array_keys(self::VARIANTS) as $variant) {
            foreach (self::NEVER_WRITTEN as $path) {
                self::assertFalse(self::exists(self::xpath(self::xmlFor($variant)), $path), "{$variant} {$path}");
            }
        }
    }

    /**
     * Událost složená mimo službu oznámení (přímo sestavený payload) nesmí
     * zakázanou část protlačit: serializér ji odmítne, místo aby ji zapsal.
     */
    #[DataProvider('eventVariantsWithForbiddenInput')]
    public function testDirectEventPayloadWithForbiddenPartFailsClosed(string $variant, string $expectedPart): void
    {
        [$action, $activity, $detail] = self::VARIANTS[$variant];
        $data = self::maximalEventData($action, $activity, $detail);
        self::assertContains(
            $expectedPart,
            PayrollRegistrationEventVariantRule::forbiddenParts($action, $activity, $detail, $data),
        );
        try {
            (new PayrollRegistrationXmlSerializer())->serialize(self::eventPayload($action, $data));
            self::fail("{$variant}: serializér zapsal zakázanou část {$expectedPart}.");
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame('registration_regzec_variant_part_forbidden', $exception->validationCode);
            self::assertStringContainsString($expectedPart, $exception->getMessage());
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function eventVariantsWithForbiddenInput(): iterable
    {
        yield 'A2-10 úmrtí' => ['A2-10', 'ended_by_death'];
        yield 'A2-10 podpora' => ['A2-10', 'unemployment'];
        yield 'A2-SPEC úmrtí' => ['A2-SPEC', 'ended_by_death'];
        yield 'A2-SPEC odstupné' => ['A2-SPEC', 'unemployment.severance_pay'];
        yield 'A3-10 kontaktní adresa' => ['A3-10', 'delta.contact_address'];
        yield 'A3-10 nositel' => ['A3-10', 'foreign_insurance'];
        yield 'A3-SPEC vzdělání ve faktech' => ['A3-SPEC', 'delta.facts.highest_education_code'];
        yield 'A3-SPEC nositel' => ['A3-SPEC', 'foreign_insurance'];
        yield 'A4-10 vznik zaměstnání' => ['A4-10', 'delta.contract_start_on'];
        yield 'A4-SPEC vznik zaměstnání' => ['A4-SPEC', 'delta.contract_start_on'];
        yield 'A4-SPEC režim' => ['A4-SPEC', 'delta.employment.work_mode_code'];
    }

    private static function xmlFor(string $variant): string
    {
        if (isset(self::$xml[$variant])) {
            return self::$xml[$variant];
        }
        [$action, $activity, $detail] = self::VARIANTS[$variant];
        if ($action === 1) {
            $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
                self::maximalA1Source($activity, $detail),
                self::identity(),
                self::a1Scope(),
                true,
            );
            self::assertSame(substr($variant, 3), $a1->variant);
            $payload = new PayrollRegistrationXmlPayload(
                identity: self::snapshot($a1),
                interaction: new PayrollRegistrationInteraction('REGZEC25', 'direct_full_registration', 1),
                sequenceNumber: 1,
                formGuid: '12345678-1234-1234-1234-123456789ABC',
                preparedOn: '2026-08-04',
                expectedStartOn: null,
                actualStartOn: '2026-08-05',
                employerVariableSymbol: '1100000007',
                employerName: 'Syntetický zaměstnavatel s.r.o.',
                csszWorkplaceCode: '110',
            );
        } else {
            $data = self::maximalEventData($action, $activity, $detail);
            foreach (PayrollRegistrationEventVariantRule::forbiddenParts($action, $activity, $detail, $data) as $part) {
                $data = self::without($data, explode('.', $part));
            }
            $payload = self::eventPayload($action, $data);
        }

        return self::$xml[$variant] = (new PayrollRegistrationXmlSerializer())->serialize($payload);
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function without(array $data, array $path): array
    {
        $key = array_shift($path);
        if ($path === []) {
            unset($data[$key]);
        } elseif (is_array($data[$key] ?? null)) {
            $data[$key] = self::without($data[$key], $path);
        }

        return $data;
    }

    private static function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('r', self::NS);

        return $xpath;
    }

    private static function exists(DOMXPath $xpath, string $path): bool
    {
        $steps = array_map(
            static fn (string $step): string => str_starts_with($step, '@') ? $step : 'r:' . $step,
            explode('/', $path),
        );
        $nodes = $xpath->query('/r:REGZEC/r:employees/r:employee/' . implode('/', $steps));

        return $nodes !== false && $nodes->length > 0;
    }

    /** @return array<string,mixed> */
    private static function maximalA1Source(string $activity, ?string $detail): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source($activity, $detail);
        $source['permanent_address'] = self::address('SK', '81101');
        $source['permanent_address']['ruian_point'] = '12345678';
        $source['czech_residence_address'] = self::address('CZ', '11000');
        $source['contact_address'] = self::address('CZ', '11000');
        $source['contact_address']['ruian_point'] = '87654321';
        $source['tax_residency'] = [
            'country_code' => 'SK',
            'identifier_type' => 'D',
            'identifier' => 'SK1000000005',
            'residence_address' => self::address('SK', '81101'),
        ];
        $source['pension'] = [
            'type_code' => '1',
            'received_from' => '2020-01-01',
            'early_retirement' => true,
            'reduced_retirement_age' => true,
        ];
        $source['facts']['disability_card'] = true;
        $source['facts']['health_restrictions'] = [
            ['type_code' => '1', 'from' => '2025-01-01', 'to' => '2027-01-01'],
        ];
        $source['foreign_legislation'] = ['applies' => true, 'country_code' => 'SK'];
        $source['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN123456',
            'foreign_issuer' => 'Syntetický úřad',
            'country_code' => 'SK',
        ];
        $source['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '1',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];
        $source['foreign_insurance'] = self::foreignInsurance('P');

        return $source;
    }

    /** @return array<string,string> */
    private static function address(string $country, string $postalCode): array
    {
        return [
            'street' => 'Testovací',
            'house_number' => '12',
            'orientation_number' => '3',
            'city' => 'Testov',
            'postal_code' => $postalCode,
            'country_code' => $country,
        ];
    }

    /** @return array<string,string> */
    private static function foreignInsurance(string $current): array
    {
        return [
            'current' => $current,
            'name' => 'Syntetická instituce',
            'street' => 'Testovacia',
            'house_number' => '7',
            'orientation_number' => '2',
            'postal_code' => '81101',
            'city' => 'Bratislava',
            'country_code' => 'SK',
            'identifier' => 'SYN-123',
            'sector' => '01',
        ];
    }

    /** @return array<string,mixed> */
    private static function maximalEventData(int $action, string $activity, ?string $detail): array
    {
        $data = [
            'activity_code' => $activity,
            'relationship_detail_code' => $detail,
            'end_on' => '2026-08-04',
            'ended_by_death' => false,
            'unemployment' => [
                'reason_not_provided' => '1',
                'employment_type' => '1',
                'entitlement' => 'A',
                'paid_in_full' => 'A',
                'average_net_earnings' => '30000',
                'service_termination_reason' => '1',
                'termination_reason' => '1',
                'replacement' => '1000',
                'golden_handshake' => '2000',
                'severance_pay' => '3000',
                'disposal' => '4000',
                'early_termination_reason' => '1',
                'pension_periods' => [['from' => '2020-01-01', 'to' => '2026-08-04']],
            ],
            'birth_number' => '9152031234',
            'delta' => [
                'title_prefix' => 'Ing.',
                'contract_start_on' => '2026-08-01',
                'identity' => [
                    'last_name' => 'Novotná',
                    'first_name' => 'Jana',
                    'previous_surnames' => 'Svobodová',
                    'birth_date' => '1991-02-03',
                    'sex' => 'female',
                    'citizenship_country_code' => 'SK',
                ],
                'permanent_address' => self::address('SK', '81101') + ['ruian_point' => '12345678'],
                'czech_residence_address' => self::address('CZ', '11000'),
                'contact_address' => self::address('CZ', '11000') + ['ruian_point' => '87654321'],
                'tax_residency' => [
                    'country_code' => 'SK',
                    'changed_on' => '2026-08-01',
                    'identifier_type' => 'D',
                    'identifier' => 'SK1000000005',
                    'residence_address' => self::address('SK', '81101'),
                ],
                'proof_identity' => [
                    'type_code' => 'P',
                    'number' => 'SYN123456',
                    'foreign_issuer' => 'Syntetický úřad',
                    'country_code' => 'SK',
                ],
                'employment' => [
                    'actual_start_on' => '2026-08-01',
                    'end_on' => '2026-12-31',
                    'contract_start_on' => '2026-08-01',
                    'employment_status_code' => '1111',
                    'work_mode_code' => '1',
                    'continuous_operation' => false,
                    'prevailing_workplace_code' => '1',
                    'expected_workplaces' => 'Testov',
                    'contract_workplace' => 'Testov',
                    'workplace_city' => 'Testov',
                    'workplace_municipality_code' => '554782',
                    'profession_code' => '24110',
                    'required_education_code' => 'T',
                    'position_name' => 'Účetní',
                    'leadership' => false,
                ],
                'pension' => [
                    'type_code' => '1',
                    'received_from' => '2020-01-01',
                    'early_retirement' => true,
                    'reduced_retirement_age' => false,
                ],
                'health_insurance_code' => '111',
                'facts' => [
                    'health_restrictions' => [
                        ['type_code' => '1', 'from' => '2025-01-01', 'to' => '2027-01-01'],
                    ],
                    'disability_card' => true,
                    'highest_education_code' => 'T',
                ],
                'highest_education_code' => 'T',
                'foreign_worker' => [
                    'free_access' => true,
                    'free_access_reason_code' => '1',
                ],
                'foreign_legislation' => ['applies' => true, 'country_code' => 'SK'],
                'relationship_detail_code' => $detail,
            ],
            'foreign_insurance' => self::foreignInsurance($action === 7 ? 'S' : 'P'),
            'new_variable_symbol' => '9876543210',
            'not_started' => true,
        ];
        if ($detail === null) {
            unset($data['delta']['relationship_detail_code']);
        }
        if (!in_array($action, [3, 4, 6, 7], true)) {
            // A2, A5 a A8 nositele nečtou; necháme ho v datech, ať je vidět, že ho nezapíšou.
            $data['foreign_insurance']['current'] = 'P';
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    private static function eventPayload(int $action, array $data): PayrollRegistrationXmlPayload
    {
        $interaction = self::INTERACTIONS[$action];

        return new PayrollRegistrationXmlPayload(
            identity: self::snapshot(null),
            interaction: new PayrollRegistrationInteraction('REGZEC25', $interaction, $action),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-08-04',
            expectedStartOn: null,
            actualStartOn: null,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            eventSnapshot: [
                'schema_reference' => 'payroll-registration-event-snapshot.v1',
                'supplier_id' => 11,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'interaction' => $interaction,
                'action_code' => $action,
                'effective_on' => '2026-08-04',
                'notification_trigger_on' => '2026-08-04',
                'person_external_identifier' => ['id' => 61, 'row_version' => 1, 'value' => '1000000001'],
                'employment_external_identifier' => ['id' => 71, 'row_version' => 1, 'value' => '200000000000000000002'],
                'employer' => [
                    'variable_symbol' => '1100000007',
                    'name' => 'Syntetický zaměstnavatel s.r.o.',
                    'workplace_code' => '110',
                ],
                'data' => $data,
                'source' => ['kind' => 'synthetic', 'reference' => 'synthetic:' . $interaction],
            ],
            productName: 'MyÚčto.cz',
            productVersion: '5.6.0',
        );
    }

    /** @return array<string,mixed> */
    private static function identity(): array
    {
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        $identity['citizenship_country_code'] = 'SK';
        $identity['previous_surnames'] = 'Svobodová';

        return $identity;
    }

    /** @return array<string,mixed> */
    private static function a1Scope(): array
    {
        return ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => '2026-08-05'];
    }

    private static function snapshot(
        ?\MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot $a1,
    ): PayrollRegistrationIdentitySnapshot {
        return new PayrollRegistrationIdentitySnapshot(
            scope: [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => null,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => 'REGZEC25',
                'effective_on' => '2026-08-05',
            ],
            identity: self::identity(),
            identifiers: [
                'birth_number' => '9152031234',
                'ecp' => null,
                'vcp' => null,
                'foreign_tax_identifier' => null,
            ],
            employmentExternalIdentifier: null,
            registrationEligibility: ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
            sourceVersions: $a1 === null ? [] : ['regzec_a1' => $a1->source],
            regzecA1: $a1,
        );
    }
}
