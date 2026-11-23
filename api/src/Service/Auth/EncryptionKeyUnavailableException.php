<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

/**
 * Instalace nemá klíč, kterým byla tajná hodnota zašifrovaná (nebo nemá klíč žádný).
 *
 * Není to chyba programu ani poškozená data, ale stav nastavení instance: typicky
 * kopie databáze přenesená bez původního `app.secret_encryption_key`, nebo klíč
 * vyměněný bez uvedení předchozího v `app.secret_encryption_previous_keys`.
 * Odpověď API z toho dělá 503 se srozumitelnou hláškou pro uživatele
 * ({@see \MyInvoice\Http\ApiErrorHandler}); text výjimky zůstává technický a jde jen do logu.
 *
 * Dědí z `\RuntimeException`, takže stávající `catch (\RuntimeException)` se chovají jako dřív.
 */
final class EncryptionKeyUnavailableException extends \RuntimeException
{
}
