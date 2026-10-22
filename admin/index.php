<?php
// Administrácia rezervácií pre majiteľa.
require __DIR__ . '/../api/lib.php';

if (!db_configured()) {
    page_start('Administrácia');
    echo '<p class="brand">AWITALIS</p><h1>Administrácia ešte nie je spustená</h1>'
       . '<div class="card"><p>Najprv vyplňte údaje k databáze v súbore <code>api/config.php</code> (návod je v NAVOD.txt, kroky 2 a 4).</p>'
       . '<p class="muted">Kým databáza nie je nastavená, rezervačná stránka na webe beží ako ukážka a nič neukladá.</p></div>';
    page_end(); exit;
}

session_name('awitalis_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Frame-Options: DENY');

$hash = (string)cfg('admin_password_hash');
$msg = ''; $err = '';

// ---------- 1. heslo ešte nie je nastavené ----------
if ($hash === '') {
    page_start('Nastavenie hesla');
    echo '<h1>Nastavenie hesla do administrácie</h1>';
    $pw = (string)($_POST['new_password'] ?? '');
    if ($pw !== '' && mb_strlen($pw) >= 10) {
        $line = "'admin_password_hash' => '" . password_hash($pw, PASSWORD_DEFAULT) . "',";
        echo '<p>Skopírujte tento riadok do súboru <code>api/config.php</code> namiesto riadku s <code>admin_password_hash</code> a súbor nahrajte znova na server:</p>'
           . '<div class="card"><code style="word-break:break-all">' . h($line) . '</code></div>'
           . '<p class="muted">Potom obnovte túto stránku a prihláste sa.</p>';
    } else {
        if ($pw !== '') echo '<div class="msg err">Heslo musí mať aspoň 10 znakov.</div>';
        echo '<form method="post" class="card row"><label>Nové heslo (aspoň 10 znakov)<input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>'
           . '<button class="btn" type="submit">Vytvoriť</button></form>';
    }
    page_end(); exit;
}

// ---------- 2. prihlásenie / odhlásenie ----------
if (isset($_POST['login'])) {
    if (password_verify((string)($_POST['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: ./'); exit;
    }
    sleep(2);   // spomalí hádanie hesla
    $err = 'Nesprávne heslo.';
}
if (empty($_SESSION['admin'])) {
    page_start('Prihlásenie');
    echo '<div style="max-width:380px;margin:10vh auto 0"><p class="brand">AWITALIS</p><h1>Administrácia</h1>';
    if ($err) echo '<div class="msg err">' . h($err) . '</div>';
    echo '<form method="post" class="card" style="display:grid;gap:12px"><input type="hidden" name="login" value="1">'
       . '<input type="password" name="password" placeholder="Heslo" required autofocus autocomplete="current-password">'
       . '<button class="btn" type="submit">Prihlásiť sa</button></form></div>';
    page_end(); exit;
}

// ---------- 3. akcie (len s platným CSRF tokenom) ----------
$csrf = $_SESSION['csrf'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Neplatná požiadavka.'); }
    $a = (string)($_POST['action'] ?? '');
    $toMin = fn($s) => preg_match('/^(\d{1,2}):(\d{2})$/', (string)$s, $m) ? (int)$m[1] * 60 + (int)$m[2] : null;

    if ($a === 'logout') { session_destroy(); header('Location: ./'); exit; }

    if ($a === 'cancel') {
        db()->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([(int)$_POST['id']]);
        $msg = 'Rezervácia je zrušená. Ak má klient e-mail, dajte mu prosím vedieť.';
    }
    if ($a === 'restore') {
        $q = db()->prepare('SELECT * FROM bookings WHERE id = ?'); $q->execute([(int)$_POST['id']]); $b = $q->fetch();
        lock_begin();
        if ($b && slot_free($b['day'], (int)$b['start_min'], (int)$b['end_min'])) {
            db()->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?")->execute([$b['id']]);
            lock_commit(); $msg = 'Rezervácia je obnovená.';
        } else { lock_rollback(); $err = 'Rezerváciu nejde obnoviť, čas je medzitým obsadený.'; }
    }
    if ($a === 'add_booking') {
        $s = find_service((string)$_POST['service']);
        $dur = (int)($_POST['duration'] ?? 0); $day = (string)$_POST['day']; $start = $toMin($_POST['start'] ?? '');
        $name = trim((string)$_POST['name']); $phone = preg_replace('/\s+/', '', (string)$_POST['phone']);
        if (!$s || !valid_day($day) || $start === null || $dur < 15 || $name === '') $err = 'Vyplňte terapiu, dĺžku, deň, čas a meno.';
        else {
            lock_begin();
            if (!slot_free($day, $start, $start + $dur)) { lock_rollback(); $err = 'V tomto čase už je iná rezervácia alebo blokovaný čas.'; }
            else {
                db()->prepare('INSERT INTO bookings (service_id, service_name, duration, day, start_min, end_min, name, phone, email, note, status, cancel_token, ip, created_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'\', ?, \'confirmed\', ?, \'admin\', ?)')
                    ->execute([$s['id'], $s['name'], $dur, $day, $start, $start + $dur, $name, $phone, 'Zapísané v administrácii', bin2hex(random_bytes(16)), date('Y-m-d H:i:s')]);
                lock_commit(); $msg = 'Rezervácia je zapísaná.';
            }
        }
    }
    if ($a === 'add_block') {
        $from = (string)$_POST['from']; $to = (string)($_POST['to'] ?: $_POST['from']);
        $s = $toMin($_POST['start'] ?? '') ?? cfg('open'); $e = $toMin($_POST['end'] ?? '') ?? cfg('close');
        if (!valid_day($from) || !valid_day($to) || $to < $from || $e <= $s) $err = 'Skontrolujte dátumy a časy.';
        else {
            $d = new DateTime($from); $n = 0;
            while ($d->format('Y-m-d') <= $to && $n < 366) {
                db()->prepare('INSERT INTO blocks (day, start_min, end_min, reason) VALUES (?, ?, ?, ?)')
                    ->execute([$d->format('Y-m-d'), $s, $e, mb_substr(trim((string)$_POST['reason']), 0, 150)]);
                $d->modify('+1 day'); $n++;
            }
            $msg = "Zablokované dni: $n. Existujúce rezervácie v tom čase zostali – skontrolujte ich v zozname.";
        }
    }
    if ($a === 'del_block') {
        db()->prepare('DELETE FROM blocks WHERE id = ?')->execute([(int)$_POST['id']]);
        $msg = 'Blokovanie je zrušené.';
    }
}

// GDPR: staré rezervácie sa po čase zmažú
db()->prepare('DELETE FROM bookings WHERE day < ?')->execute([(new DateTime('today'))->modify('-' . cfg('retention_days') . ' days')->format('Y-m-d')]);

// ---------- 4. výpis ----------
$today = date('Y-m-d');
$tomorrow = (new DateTime('tomorrow'))->format('Y-m-d');
$weekEnd = (new DateTime('today'))->modify('+6 days')->format('Y-m-d');
$showPast = isset($_GET['minule']);
$fromDay = $showPast ? (new DateTime('today'))->modify('-60 days')->format('Y-m-d') : $today;

$q = db()->prepare('SELECT * FROM bookings WHERE day >= ? ORDER BY day, start_min');
$q->execute([$fromDay]);
$byDay = [];
foreach ($q->fetchAll() as $b) $byDay[$b['day']][] = $b;
$q = db()->prepare('SELECT * FROM blocks WHERE day >= ? ORDER BY day, start_min');
$q->execute([$today]);
$blocks = $q->fetchAll();
$blocksByDay = [];
foreach ($blocks as $bl) $blocksByDay[$bl['day']][] = $bl;

// prehľad
$cnt = function (string $from, string $to): int {
    $q = db()->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed' AND day BETWEEN ? AND ?");
    $q->execute([$from, $to]);
    return (int)$q->fetchColumn();
};
$nowMin = (int)date('G') * 60 + (int)date('i');
$q = db()->prepare("SELECT * FROM bookings WHERE status = 'confirmed' AND (day > ? OR (day = ? AND end_min > ?)) ORDER BY day, start_min LIMIT 1");
$q->bindValue(1, $today); $q->bindValue(2, $today); $q->bindValue(3, $nowMin, PDO::PARAM_INT);
$q->execute();
$next = $q->fetch();

$f = fn($a) => '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . $a . '">';
$ucfirst = fn(string $s) => mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
$shortDay = function (string $day) use ($today, $tomorrow): string {
    if ($day === $today) return 'dnes';
    if ($day === $tomorrow) return 'zajtra';
    $dni = ['po', 'ut', 'st', 'št', 'pi', 'so', 'ne']; $d = new DateTime($day);
    return $dni[(int)$d->format('N') - 1] . ' ' . (int)$d->format('j') . '. ' . (int)$d->format('n') . '.';
};

page_start('Rezervácie');
?>
<style>
  .wrap{max-width:860px}
  .top{position:sticky;top:0;z-index:5;background:var(--bg);padding-top:14px;margin-top:-18px}
  .top small{display:block;font-size:12px;color:var(--muted);letter-spacing:.04em}
  .hello{margin:22px 0 4px;font-size:15px;color:var(--muted)}
  .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px 16px;min-width:0}
  .stat b{display:block;font-family:Marcellus,Georgia,serif;font-weight:400;font-size:30px;line-height:1.1}
  .stat span{font-size:13px;color:var(--muted)}
  .stat.next{background:var(--forest);color:#EEF0EA;border-color:var(--forest)}
  .stat.next b{font-size:22px;color:#C9A46B}
  .stat.next span{color:rgba(238,240,234,.75)}
  .tabs{display:flex;gap:6px;margin:22px 0 4px;overflow-x:auto;padding-bottom:4px}
  .tabs a{flex:none;text-decoration:none;color:var(--fg);border:1px solid var(--line);border-radius:999px;padding:8px 16px;font-size:14px;font-weight:600;background:var(--card)}
  .tabs a:hover{border-color:var(--gold)}
  section{scroll-margin-top:80px}
  .day{display:flex;align-items:baseline;gap:10px;margin:30px 0 10px}
  .day h2{margin:0;font-size:22px}
  .pill{font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;border-radius:999px;padding:3px 10px;background:var(--forest);color:#EEF0EA}
  .pill.soft{background:#E2E6DC;color:var(--fg)}
  .pill.gold{background:#F2E3B8;color:#5A4A22}
  .pill.red{background:#F3DDD7;color:var(--danger)}
  .count{font-size:14px;color:var(--muted);margin-left:auto}
  .bk{display:grid;grid-template-columns:76px minmax(0,1fr) auto;gap:4px 16px;align-items:start;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px 16px;margin-top:8px}
  .bk .t b{display:block;font-family:Marcellus,Georgia,serif;font-weight:400;font-size:24px;line-height:1.1}
  .bk .t span{font-size:13px;color:var(--muted)}
  .bk .who{display:grid;gap:2px;min-width:0}
  .bk .svc{font-weight:600}
  .bk .meta{display:flex;flex-wrap:wrap;gap:6px 12px;font-size:14px;color:var(--muted)}
  .bk .meta a{color:var(--fg);text-decoration:none;border-bottom:1px solid var(--line)}
  .bk .note{font-size:14px;color:var(--muted);background:var(--bg);border-radius:10px;padding:6px 10px;margin-top:4px;overflow-wrap:anywhere}
  .bk .act{display:flex;flex-direction:column;gap:6px;align-items:flex-end}
  .bk.off{opacity:.55}
  .bk.off .svc,.bk.off .t b{text-decoration:line-through}
  .blk{display:flex;gap:10px;align-items:center;justify-content:space-between;border:1px dashed #C9A46B;border-radius:16px;padding:12px 16px;margin-top:8px;background:repeating-linear-gradient(135deg,transparent 0 10px,rgba(201,164,107,.08) 10px 20px)}
  .empty{color:var(--muted);padding:14px 0}
  .call{display:inline-block;background:var(--forest);color:#EEF0EA !important;border:0 !important;border-radius:999px;padding:4px 12px;font-weight:600;font-size:13px}
  .grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  .grid2 label{display:grid;gap:4px;font-size:14px;font-weight:600}
  .grid2 input,.grid2 select{width:100%}
  .grid2 .full{grid-column:1/-1}
  .formcard{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin-top:10px}
  .formcard .btn{margin-top:14px}
  .hint{font-size:14px;color:var(--muted);margin-top:4px}
  @media (max-width:600px){
    .stats{grid-template-columns:1fr 1fr}
    .stat.next{grid-column:1/-1}
    .bk{grid-template-columns:64px minmax(0,1fr)}
    .bk .act{grid-column:1/-1;flex-direction:row;justify-content:flex-end}
    .grid2{grid-template-columns:1fr}
  }
</style>
<?php
echo '<div class="top"><span><span class="brand">AWITALIS</span><small>Administrácia rezervácií</small></span>'
   . '<form method="post">' . $f('logout') . '<button class="btn ghost sm">Odhlásiť</button></form></div>';
echo '<p class="hello">Dobrý deň, dnes je ' . h(day_label($today)) . '.</p>';
if ($msg) echo '<div class="msg">' . h($msg) . '</div>';
if ($err) echo '<div class="msg err">' . h($err) . '</div>';

// prehľad
echo '<div class="stats">'
   . '<div class="stat"><b>' . $cnt($today, $today) . '</b><span>termínov dnes</span></div>'
   . '<div class="stat"><b>' . $cnt($today, $weekEnd) . '</b><span>za 7 dní</span></div>'
   . '<div class="stat next">' . ($next
        ? '<b>' . h($shortDay($next['day'])) . ' ' . hm((int)$next['start_min']) . '</b><span>Najbližší: ' . h($next['name']) . ', ' . h($next['service_name']) . '</span>'
        : '<b>–</b><span>Žiadny ďalší termín</span>') . '</div>'
   . '</div>';

echo '<nav class="tabs" aria-label="Sekcie"><a href="#rezervacie">Rezervácie</a><a href="#pridat">+ Telefonická objednávka</a><a href="#volno">Voľno a dovolenka</a>'
   . '<a href="?' . ($showPast ? '' : 'minule') . '#rezervacie">' . ($showPast ? 'Skryť staršie' : 'Staršie termíny') . '</a></nav>';

// rezervácie po dňoch
echo '<section id="rezervacie">';
$days = array_unique(array_merge(array_keys($byDay), array_keys($blocksByDay)));
sort($days);
if (!$days) echo '<p class="empty">Zatiaľ žiadne rezervácie. Nové sa tu objavia hneď, ako si ich niekto urobí.</p>';
foreach ($days as $day) {
    $list = $byDay[$day] ?? [];
    $active = count(array_filter($list, fn($b) => $b['status'] === 'confirmed'));
    $badge = $day === $today ? '<span class="pill">dnes</span>' : ($day === $tomorrow ? '<span class="pill soft">zajtra</span>' : '');
    echo '<div class="day"><h2>' . h($ucfirst(day_label($day))) . '</h2>' . $badge
       . '<span class="count">' . $active . ' ' . ($active === 1 ? 'termín' : ($active >= 2 && $active <= 4 ? 'termíny' : 'termínov')) . '</span></div>';
    foreach ($blocksByDay[$day] ?? [] as $bl) {
        $whole = (int)$bl['start_min'] <= cfg('open') && (int)$bl['end_min'] >= cfg('close');
        echo '<div class="blk"><span><strong>' . ($whole ? 'Zatvorené celý deň' : 'Zablokované ' . hm((int)$bl['start_min']) . ' – ' . hm((int)$bl['end_min'])) . '</strong>'
           . ($bl['reason'] !== '' ? ' <span class="muted">· ' . h($bl['reason']) . '</span>' : '') . '</span>'
           . '<form method="post">' . $f('del_block') . '<input type="hidden" name="id" value="' . (int)$bl['id'] . '"><button class="btn sm ghost">Odblokovať</button></form></div>';
    }
    foreach ($list as $b) {
        $off = $b['status'] === 'cancelled';
        $phoneOrder = $b['ip'] === 'admin';
        echo '<div class="bk' . ($off ? ' off' : '') . '">'
           . '<div class="t"><b>' . hm((int)$b['start_min']) . '</b><span>do ' . hm((int)$b['end_min']) . '</span></div>'
           . '<div class="who"><span class="svc">' . h($b['service_name']) . ' <span class="muted" style="font-weight:400">· ' . (int)$b['duration'] . ' min</span></span>'
           . '<span><strong>' . h($b['name']) . '</strong></span>'
           . '<span class="meta">' . ($b['phone'] ? '<a class="call" href="tel:' . h($b['phone']) . '">Zavolať ' . h($b['phone']) . '</a>' : '')
           . ($b['email'] ? '<a href="mailto:' . h($b['email']) . '">' . h($b['email']) . '</a>' : '') . '</span>'
           . ($b['note'] && !$phoneOrder ? '<span class="note">' . nl2br(h($b['note'])) . '</span>' : '')
           . '</div><div class="act">'
           . ($off ? '<span class="pill red">zrušená</span>' : ($phoneOrder ? '<span class="pill gold">telefonicky</span>' : '<span class="pill soft">online</span>'))
           . '<form method="post" onsubmit="return confirm(\'' . ($off ? 'Obnoviť túto rezerváciu?' : 'Naozaj zrušiť túto rezerváciu? Klientovi dajte vedieť.') . '\')">'
           . $f($off ? 'restore' : 'cancel') . '<input type="hidden" name="id" value="' . (int)$b['id'] . '">'
           . '<button class="btn sm ' . ($off ? 'ghost' : 'danger') . '">' . ($off ? 'Obnoviť' : 'Zrušiť') . '</button></form></div></div>';
    }
}
echo '</section>';

// zápis telefonickej objednávky
$opts = '';
foreach (cfg('services') as $s) $opts .= '<option value="' . h($s['id']) . '" data-d="' . h(implode(',', $s['durations'])) . '">' . h($s['name']) . '</option>';
echo '<section id="pridat"><h2>Telefonická objednávka</h2><p class="hint">Zapíšte termín dohodnutý po telefóne, aby sa naň nemohol objednať nikto online.</p>'
   . '<form method="post" class="formcard">' . $f('add_booking') . '<div class="grid2">'
   . '<label class="full">Terapia<select name="service" id="svc" required>' . $opts . '</select></label>'
   . '<label>Deň<input type="date" name="day" min="' . $today . '" value="' . $today . '" required></label>'
   . '<label>Začiatok<input type="time" name="start" step="900" min="' . hm(cfg('open')) . '" required></label>'
   . '<label>Dĺžka (min)<select name="duration" id="dur"></select></label>'
   . '<label>Meno klienta<input name="name" required autocomplete="off"></label>'
   . '<label class="full">Telefón<input name="phone" type="tel" autocomplete="off"></label>'
   . '</div><button class="btn">Zapísať termín</button></form></section>';

// voľno
echo '<section id="volno"><h2>Voľno a dovolenka</h2><p class="hint">Zablokované dni sa zákazníkom ukážu ako zatvorené. Bez vyplnených časov sa zablokuje celý deň.</p>'
   . '<form method="post" class="formcard">' . $f('add_block') . '<div class="grid2">'
   . '<label>Od dňa<input type="date" name="from" min="' . $today . '" required></label><label>Do dňa (nepovinné)<input type="date" name="to" min="' . $today . '"></label>'
   . '<label>Od (nepovinné)<input type="time" name="start" step="900"></label><label>Do (nepovinné)<input type="time" name="end" step="900"></label>'
   . '<label class="full">Dôvod (vidíte len vy)<input name="reason" placeholder="napr. dovolenka, lekár, školenie"></label>'
   . '</div><button class="btn">Zablokovať</button></form></section>';
?>
<script>
  // dĺžky v ponuke podľa zvolenej terapie
  const svc = document.getElementById('svc'), dur = document.getElementById('dur');
  const fill = () => { dur.innerHTML = svc.selectedOptions[0].dataset.d.split(',').map(m => `<option value="${m}">${m} min</option>`).join(''); };
  svc.addEventListener('change', fill); fill();
</script>
<?php
page_end();
