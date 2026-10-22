<?php
// Uloží novú rezerváciu. Všetko sa kontroluje znova tu na serveri – na prehliadač sa nespoliehame.
require __DIR__ . '/lib.php';

if (!db_configured()) json_out(['ok' => false, 'error' => 'Online rezervácie ešte nie sú spustené. Zavolajte nám prosím na ' . cfg('phone') . '.'], 503);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) json_out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 400);

// --- ochrana proti robotom ---
if (!empty($in['website'])) json_out(['ok' => true]);                       // skryté pole vyplní len robot
if (isset($in['openedAt']) && (time() * 1000 - (int)$in['openedAt']) < 4000)  // formulár vyplnený za menej ako 4 s
    json_out(['ok' => false, 'error' => 'Skúste to prosím ešte raz.'], 400);

// --- vstupy ---
$service = find_service((string)($in['service'] ?? ''));
$dur   = (int)($in['duration'] ?? 0);
$day   = (string)($in['date'] ?? '');
$start = (int)($in['start'] ?? -1);
$name  = trim(mb_substr((string)($in['name'] ?? ''), 0, 100));
$phone = preg_replace('/\s+/', '', (string)($in['phone'] ?? ''));
$email = trim(mb_substr((string)($in['email'] ?? ''), 0, 150));
$note  = trim(mb_substr((string)($in['note'] ?? ''), 0, 1000));

$errors = [];
if (!$service || !in_array($dur, $service['durations'], true)) $errors['service'] = 'Vyberte terapiu a dĺžku.';
if (mb_strlen($name) < 3) $errors['name'] = 'Napíšte meno a priezvisko.';
if (!preg_match('/^(\+421|0)9\d{8}$/', $phone)) $errors['phone'] = 'Zadajte slovenské mobilné číslo, napr. 0905 123 456.';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'E-mail nemá správny tvar.';
if (empty($in['gdpr'])) $errors['gdpr'] = 'Potvrďte prosím, že ste sa oboznámili s informáciami o spracúvaní údajov.';
if (!$errors && ($msg = check_rules($day, $start, $dur))) $errors['time'] = $msg;
if ($errors) json_out(['ok' => false, 'code' => 'invalid', 'errors' => $errors, 'error' => reset($errors)], 422);

$end = $start + $dur;

try {
    // --- limit rezervácií z jednej IP adresy ---
    $q = db()->prepare('SELECT COUNT(*) FROM bookings WHERE ip = ? AND created_at > ?');
    $q->execute([client_ip(), date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$q->fetchColumn() >= cfg('max_per_ip_hour'))
        json_out(['ok' => false, 'error' => 'Príliš veľa rezervácií naraz. Zavolajte nám prosím na ' . cfg('phone') . '.'], 429);

    // --- zámok: kontrola a uloženie prebehnú naraz, nikto iný sa medzi ne nedostane ---
    lock_begin();
    if (!slot_free($day, $start, $end)) {
        lock_rollback();
        json_out(['ok' => false, 'code' => 'taken', 'error' => 'Tento čas si medzitým rezervoval niekto iný. Vyberte prosím iný.'], 409);
    }
    $token = bin2hex(random_bytes(16));
    $q = db()->prepare('INSERT INTO bookings (service_id, service_name, duration, day, start_min, end_min, name, phone, email, note, status, cancel_token, ip, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'confirmed\', ?, ?, ?)');
    $q->execute([$service['id'], $service['name'], $dur, $day, $start, $end, $name, $phone, $email, $note, $token, client_ip(), date('Y-m-d H:i:s')]);
    lock_commit();
} catch (Throwable $e) {
    lock_rollback();
    error_log('book: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Rezerváciu sa nepodarilo uložiť. Zavolajte nám prosím na ' . cfg('phone') . '.'], 500);
}

// --- e-maily (chyba pri odoslaní nezruší rezerváciu) ---
$when = day_label($day) . ', ' . hm($start) . ' – ' . hm($end);
$cancelUrl = site_url() . '/api/cancel.php?t=' . $token;
$price = price_for($service, $dur);
$priceLine = $price !== '' ? "Orientačná cena: $price\n" : '';

send_mail(cfg('mail_owner'), "Nová rezervácia: $name, " . day_label($day) . ' ' . hm($start),
    "Nová online rezervácia\n\n"
  . "Terapia: {$service['name']} ({$dur} min)\nTermín: $when\n" . $priceLine . "\n"
  . "Meno: $name\nTelefón: $phone\nE-mail: " . ($email ?: '–') . "\nPoznámka: " . ($note ?: '–') . "\n\n"
  . "Všetky rezervácie: " . site_url() . "/admin/\n", $email);

if ($email !== '') {
    send_mail($email, 'Potvrdenie rezervácie – Awitalis',
        "Dobrý deň, $name,\n\nďakujeme za rezerváciu v masážnom štúdiu Awitalis.\n\n"
      . "Terapia: {$service['name']} ({$dur} min)\nTermín: $when\n" . $priceLine . "Adresa: " . cfg('address') . "\n\n"
      . "Ak nemôžete prísť, zrušte prosím termín najneskôr " . cfg('cancel_hours') . " hodín vopred:\n$cancelUrl\n\n"
      . "Neskôr nám prosím zavolajte na " . cfg('phone') . ".\n\nTešíme sa na vás.\nPali a Jarka, Awitalis\n");
}

json_out(['ok' => true, 'when' => $when]);
