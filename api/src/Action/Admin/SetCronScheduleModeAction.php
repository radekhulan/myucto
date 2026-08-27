<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\Cron\DockerCrontabGenerator;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/admin/cron-jobs/schedule-mode
 *
 * Přepne způsob, jakým se plánované úlohy spouštějí:
 *
 *   individual — 20 samostatných položek v crontabu / Task Scheduleru (default),
 *   dispatcher — jediná položka `cron-dispatch` každou minutu.
 *
 * ⚠️ Samotné přepnutí NIC nepřeplánuje. Crontab se musí přegenerovat — v Dockeru
 * restartem kontejneru (entrypoint ho vygeneruje podle nového nastavení), u nativní
 * instalace ruční úpravou. Odpověď proto vždy nese `requires_replan` a konkrétní
 * pokyn; kdyby to UI zamlčelo, admin přepne režim a úlohy budou dál běžet postaru
 * (nebo, u přechodu na dispatcher, přestanou běžet úplně).
 *
 * Admin only.
 */
final class SetCronScheduleModeAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly ManagedModeGuard $managed,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        // ⚠️ U spravovaného provozu režim NEPATŘÍ zákazníkovi. Plánování drží
        // provozovatel: na jeho straně běží jediná položka `cron-dispatch`
        // a přepnutí na `individual` znamená, že dispatcher skončí bez práce
        // a NEBĚŽÍ NIC — žádné obnovy licence, žádné zálohy, žádná avíza.
        // Heartbeat přitom tiká dál, takže se na to přijde až z monitoringu
        // provozovatele; přesně tak se to 27. 8. 2026 stalo (třikrát za večer,
        // pokaždé po obnově databáze, která smazala řádek `cron_settings`).
        if ($this->managed->isManaged()) {
            return Json::error(
                $response,
                ManagedModeGuard::ERROR_CODE,
                'Plánování úloh u nás řídí provoz — přepínat režim nejde. '
                . 'Kdyby se úlohy nespouštěly, ozvěte se prosím podpoře.',
                ManagedModeGuard::HTTP_STATUS,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $mode = trim((string) ($body['mode'] ?? ''));

        if (!CronScheduleMode::isValid($mode)) {
            return Json::error(
                $response,
                'invalid_mode',
                'Neznámý režim plánování. Povolené: ' . implode(', ', CronScheduleMode::all()) . '.',
                422,
            );
        }

        $pdo = $this->db->pdo();
        $previous = CronScheduleMode::current($pdo);
        $userId = (int) ($user['id'] ?? 0);

        CronScheduleMode::set($pdo, $mode, $userId > 0 ? $userId : null);

        $this->logger->log('admin.cron.schedule_mode', $userId, null, null, [
            'from' => $previous,
            'to'   => $mode,
        ]);

        return Json::ok($response, [
            'mode'            => $mode,
            'previous_mode'   => $previous,
            'changed'         => $previous !== $mode,
            'requires_replan' => true,
            'next_step'       => $this->nextStep($mode),
        ]);
    }

    private function nextStep(string $mode): string
    {
        $isDocker = is_file('/.dockerenv') || is_file(DockerCrontabGenerator::WRAPPER);

        if ($isDocker) {
            return 'Restartuj kontejner — plán úloh se při startu vygeneruje podle nového režimu. '
                . 'Do restartu běží cron postaru.';
        }

        if ($mode === CronScheduleMode::DISPATCHER) {
            return sprintf(
                'Zruš naplánované jednotlivé úlohy (%d položek) a zaregistruj místo nich jedinou: '
                . 'cmd/%s.{cmd,sh} každou minutu. Nikdy obojí naráz — úlohy by běžely dvakrát.',
                count(CronCatalog::dispatchable()),
                CronCatalog::DISPATCHER_SCRIPT,
            );
        }

        return sprintf(
            'Zruš naplánovanou úlohu cmd/%s.{cmd,sh} a zaregistruj zpět jednotlivé úlohy '
            . 'podle návodu níže. Nikdy obojí naráz — úlohy by běžely dvakrát.',
            CronCatalog::DISPATCHER_SCRIPT,
        );
    }
}
