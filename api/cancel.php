<?php
// Zrušenie termínu klientom cez odkaz z potvrdzovacieho e-mailu.
require __DIR__ . '/lib.php';

if (!db_configured()) {
    page_start('Zrušenie termínu');
    echo '<h1>Online rezervácie ešte nie sú spustené</h1><p class="muted">Zavolajte nám prosím na ' . h(cfg('phone')) . '.</p>';
    page_end(); exit;
}

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$b = null;
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    $q = db()->prepare('SELECT * FROM bookings WHERE cancel_token = ?');
    $q->execute([$token]);
    $b = $q->fetch() ?: null;
}

page_start('Zrušenie termínu');
echo '<div class="top"><a class="brand" href="' . h(site_url()) . '/" style="color:inherit;text-decoration:none">AWITALIS</a></div>';

if (!$b) {
    echo '<h1>Rezervácia sa nenašla</h1><p class="muted">Odkaz je neplatný. Zavolajte nám prosím na ' . h(cfg('phone')) . '.</p>';
    page_end(); exit;
}

$when = day_label($b['day']) . ', ' . hm((int)$b['start_min']) . ' – ' . hm((int)$b['end_min']);
$begin = (new DateTime($b['day']))->setTime(intdiv((int)$b['start_min'], 60), (int)$b['start_min'] % 60);
$tooLate = $begin->getTimestamp() - time() < cfg('cancel_hours') * 3600;

if ($b['status'] === 'cancelled') {
    echo '<h1>Termín je zrušený</h1><p class="muted">' . h($b['service_name']) . ', ' . h($when) . '</p>';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tooLate) {
    db()->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$b['id']]);
    send_mail(cfg('mail_owner'), "Zrušená rezervácia: {$b['name']}, " . day_label($b['day']) . ' ' . hm((int)$b['start_min']),
        "Klient zrušil termín cez odkaz v e-maile.\n\nTerapia: {$b['service_name']}\nTermín: $when\nMeno: {$b['name']}\nTelefón: {$b['phone']}\n");
    echo '<h1>Termín sme zrušili</h1><p class="muted">' . h($b['service_name']) . ', ' . h($when) . '</p>'
       . '<p>Ďakujeme, že ste nám dali vedieť. <a href="' . h(site_url()) . '/rezervacia.html">Rezervovať iný termín</a></p>';
} elseif ($tooLate) {
    echo '<h1>Termín sa už nedá zrušiť online</h1><p class="muted">' . h($b['service_name']) . ', ' . h($when) . '</p>'
       . '<p>Do termínu zostáva menej ako ' . (int)cfg('cancel_hours') . ' hodín. Zavolajte nám prosím na <strong>' . h(cfg('phone')) . '</strong>.</p>';
} else {
    echo '<h1>Zrušiť termín?</h1><div class="card"><strong>' . h($b['service_name']) . '</strong><br>' . h($when) . '<br><span class="muted">' . h($b['name']) . '</span></div>'
       . '<form method="post" style="margin-top:18px"><input type="hidden" name="t" value="' . h($token) . '">'
       . '<button class="btn danger" type="submit">Áno, zrušiť termín</button> '
       . '<a class="btn ghost" href="' . h(site_url()) . '/">Nie, ponechať</a></form>';
}
page_end();
