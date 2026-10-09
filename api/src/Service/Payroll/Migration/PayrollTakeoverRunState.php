<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Stav jednoho běhu zápisu převzatých osob: souhrny pro závěrečná hlášení protokolu
 * a mezipaměť čtení. Zápisy ho plní průběžně (i když další krok téže osoby selže),
 * orchestrátor zdroje z něj po posledním vztahu skládá souhrnná upozornění.
 */
final class PayrollTakeoverRunState
{
    /** @var array<string,int> položka checklistu => počet odškrtnutých */
    public array $completed = [];
    /** @var list<string> osobní čísla s OIČ, které nesedí na kontrolní číslici */
    public array $invalidOic = [];
    /** @var list<string> osobní čísla osob podléhajících cizím právním předpisům */
    public array $foreignLegislation = [];
    /** @var list<string> osobní čísla osob s převzatou výjimkou z minima ZP bez důvodu */
    public array $healthMinimumUnverified = [];
    public int $accountsToVerify = 0;
    public int $accountsVerified = 0;
    /** Aktivní účty ověřené už dřívějším během (opakovaný převod je jinak hlásil jako nic). */
    public int $accountsAlreadyVerified = 0;
    /** @var list<int> osoby s aktivním neověřeným účtem, na který zdroj výplatu nedoložil */
    public array $accountsWithoutPayout = [];
    /** Neaktivní účty ze zdroje (mzda na ně nechodila); k rozhodnutí účetní. */
    public int $accountsInactive = 0;
    /** Karty, kterým převod opravil podíl hotovosti u výplaty na účet (součet přes 100 %). */
    public int $cashShareRepaired = 0;
    public int $hourlyWageRelations = 0;
    /** @var list<string> příjemci odvodů, pro které zdroj nedal účet */
    public array $institutionGaps = [];
    /** Účty institucí převzaté ze zdroje, které čekají na potvrzení účetní. */
    public int $institutionsToConfirm = 0;
    public int $leaveTransferred = 0;
    public int $leaveTakenHours = 0;
    /** Osoby se souběžnými vztahy, u kterých nejde určit, komu zůstatek dovolené patří. */
    public int $leaveShared = 0;
    /** @var array<string,int> pravidelné plnění => počet vztahů */
    public array $regularBenefits = [];
    /** @var array<string,int> druh nepřítomnosti => počet ponechaných na souhrnu importu */
    public array $absencesFromImport = [];
    /** @var array<string,int> osobní číslo => nepřítomnosti vyžadující data, které je zdroj nemá */
    public array $absencesWithoutDates = [];
    /** @var array<string,int> osobní číslo => nepřítomnosti vynechané pro překryv s jinou */
    public array $absenceOverlaps = [];
    /** @var array<string,int> osobní číslo => nepřítomnosti, které evidence odmítla zapsat */
    public array $absencesRejected = [];
    /** @var array<string,array<string,int>|null> vztah a měsíc => hodiny souhrnu importu */
    public array $importSummaries = [];
}
