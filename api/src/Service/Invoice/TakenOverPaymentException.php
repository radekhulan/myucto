<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/** Platbu převzatou z jiného systému bez protějšku v MyÚčtu nelze smazat ({@see TakenOverPayment::isProtected()}). */
final class TakenOverPaymentException extends \RuntimeException {}
