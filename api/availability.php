<?php
// Vráti nastavenia, terapie a obsadené časy (bez osobných údajov) pre rezervačnú stránku.
require __DIR__ . '/lib.php';

// Kým nie je v config.php vyplnená databáza, rezervačná stránka beží ako ukážka.
if (!db_configured()) json_out(['ok' => false, 'demo' => true]);

try {
    $from = (new DateTime('today'))->format('Y-m-d');
    $to = (new DateTime('today'))->modify('+' . cfg('horizon_days') . ' days')->format('Y-m-d');

    $q = db()->prepare("SELECT day, start_min, end_min FROM bookings
                        WHERE status = 'confirmed' AND day BETWEEN ? AND ? ORDER BY day, start_min");
    $q->execute([$from, $to]);
    $busy = array_map(fn($r) => ['date' => $r['day'], 'start' => (int)$r['start_min'], 'end' => (int)$r['end_min']], $q->fetchAll());

    $q = db()->prepare('SELECT day, start_min, end_min FROM blocks WHERE day BETWEEN ? AND ?');
    $q->execute([$from, $to]);
    $blocks = array_map(fn($r) => ['date' => $r['day'], 'start' => (int)$r['start_min'], 'end' => (int)$r['end_min']], $q->fetchAll());

    $wd = array_map(fn($n) => $n % 7, cfg('work_days'));   // JS: 0 = nedeľa

    json_out([
        'ok' => true,
        'now' => date('c'),
        'settings' => [
            'workDays' => $wd,
            'open' => cfg('open'), 'close' => cfg('close'), 'step' => cfg('step'),
            'buffer' => cfg('buffer'), 'leadMinutes' => cfg('lead_minutes'),
            'horizonDays' => cfg('horizon_days'), 'capacity' => cfg('capacity'),
        ],
        'services' => cfg('services'),
        'busy' => $busy,
        'blocks' => $blocks,
    ]);
} catch (Throwable $e) {
    error_log('availability: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Rezervácie sú dočasne nedostupné. Zavolajte nám prosím na ' . cfg('phone') . '.'], 500);
}
