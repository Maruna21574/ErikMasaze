<?php
// Spoločné funkcie rezervačného systému. Netreba upravovať.

declare(strict_types=1);
date_default_timezone_set('Europe/Bratislava');
mb_internal_encoding('UTF-8');

function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $local = __DIR__ . '/config.local.php';          // voliteľné lokálne nastavenia na testovanie
        $c = require (is_file($local) ? $local : __DIR__ . '/config.php');
    }
    return $key === null ? $c : $c[$key];
}

/** Adresa webu bez lomky na konci. Ak nie je nastavená, zistí sa z aktuálnej požiadavky. */
function site_url(): string
{
    $u = rtrim((string)(cfg('site_url') ?? ''), '/');
    if ($u !== '') return $u;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // skripty sú v /api/ alebo /admin/, koreň webu je o priečinok vyššie
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/x/y'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $base;
}

/** Je vyplnená databáza v config.php? */
function db_configured(): bool
{
    return strpos((string)cfg('db')['dsn'], 'NAZOV_DATABAZY') === false;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $d = cfg('db');
    $pdo = new PDO($d['dsn'], $d['user'] ?? null, $d['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    ensure_schema($pdo);
    return $pdo;
}

function is_sqlite(): bool
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

// Tabuľky sa vytvoria automaticky pri prvom spustení.
function ensure_schema(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
        id $id,
        service_id VARCHAR(32) NOT NULL,
        service_name VARCHAR(100) NOT NULL,
        duration SMALLINT NOT NULL,
        day CHAR(10) NOT NULL,
        start_min SMALLINT NOT NULL,
        end_min SMALLINT NOT NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        email VARCHAR(150) NOT NULL DEFAULT '',
        note TEXT,
        status VARCHAR(12) NOT NULL DEFAULT 'confirmed',
        cancel_token CHAR(32) NOT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at CHAR(19) NOT NULL
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocks (
        id $id,
        day CHAR(10) NOT NULL,
        start_min SMALLINT NOT NULL,
        end_min SMALLINT NOT NULL,
        reason VARCHAR(150) NOT NULL DEFAULT ''
    )$tail");
    try { $pdo->exec('CREATE INDEX idx_bookings_day ON bookings (day, status)'); } catch (Throwable $e) {}
    try { $pdo->exec('CREATE INDEX idx_blocks_day ON blocks (day)'); } catch (Throwable $e) {}
}

// ---------- zámok proti dvojitej rezervácii ----------
function lock_begin(): void
{
    if (is_sqlite()) { db()->exec('BEGIN IMMEDIATE'); return; }
    $ok = db()->query("SELECT GET_LOCK('awitalis_booking', 10)")->fetchColumn();
    if ((int)$ok !== 1) throw new RuntimeException('lock');
    db()->beginTransaction();
}
function lock_commit(): void
{
    if (is_sqlite()) { db()->exec('COMMIT'); return; }
    db()->commit();
    db()->query("SELECT RELEASE_LOCK('awitalis_booking')");
}
function lock_rollback(): void
{
    if (is_sqlite()) { try { db()->exec('ROLLBACK'); } catch (Throwable $e) {} return; }
    if (db()->inTransaction()) db()->rollBack();
    db()->query("SELECT RELEASE_LOCK('awitalis_booking')");
}

// ---------- pravidlá ----------
function find_service(string $id): ?array
{
    foreach (cfg('services') as $s) if ($s['id'] === $id) return $s;
    return null;
}

/** Cena terapie pre danú dĺžku, napr. "40 €", alebo "" ak nie je v cenníku. */
function price_for(array $service, int $dur): string
{
    $i = array_search($dur, $service['durations'], true);
    $p = ($i === false) ? null : ($service['prices'][$i] ?? null);
    return $p === null ? '' : $p . ' €';
}

function valid_day(string $day): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return false;
    $d = DateTime::createFromFormat('!Y-m-d', $day);
    return $d && $d->format('Y-m-d') === $day;
}

/** Vráti chybovú hlášku alebo null, ak termín spĺňa otváracie hodiny a pravidlá. */
function check_rules(string $day, int $start, int $dur): ?string
{
    if (!valid_day($day)) return 'Neplatný dátum.';
    $d = new DateTime($day);
    if (!in_array((int)$d->format('N'), cfg('work_days'), true)) return 'V tento deň je zatvorené.';
    $today = new DateTime('today');
    $last = (clone $today)->modify('+' . cfg('horizon_days') . ' days');
    if ($d < $today || $d > $last) return 'Na tento deň sa zatiaľ nedá objednať.';
    if ($start < cfg('open') || $start + $dur > cfg('close')) return 'Čas je mimo otváracích hodín.';
    if (($start - cfg('open')) % cfg('step') !== 0) return 'Neplatný čas.';
    $begin = (clone $d)->setTime(intdiv($start, 60), $start % 60);
    if ($begin->getTimestamp() - time() < cfg('lead_minutes') * 60) return 'Tento čas je už príliš blízko, vyberte neskorší.';
    return null;
}

/** Je čas voľný? Volať vo vnútri zámku. */
function slot_free(string $day, int $start, int $end): bool
{
    $buf = cfg('buffer');
    // blokovaný čas (dovolenka, sviatok) – nikdy
    // čísla sa musia posielať ako čísla, inak by ich databáza porovnávala ako text
    $q = db()->prepare('SELECT COUNT(*) FROM blocks WHERE day = :day AND start_min < :e AND end_min > :s');
    $q->bindValue(':day', $day);
    $q->bindValue(':e', $end, PDO::PARAM_INT);
    $q->bindValue(':s', $start, PDO::PARAM_INT);
    $q->execute();
    if ((int)$q->fetchColumn() > 0) return false;
    // ostatné rezervácie vrátane prestávky po nich
    $q = db()->prepare("SELECT COUNT(*) FROM bookings WHERE day = :day AND status = 'confirmed'
                        AND start_min < :e AND end_min + :buf > :s");
    $q->bindValue(':day', $day);
    $q->bindValue(':e', $end + $buf, PDO::PARAM_INT);
    $q->bindValue(':buf', $buf, PDO::PARAM_INT);
    $q->bindValue(':s', $start, PDO::PARAM_INT);
    $q->execute();
    return (int)$q->fetchColumn() < cfg('capacity');
}

// ---------- výstup a pomôcky ----------
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hm(int $m): string { return intdiv($m, 60) . ':' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT); }
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45); }

function day_label(string $day): string
{
    $dni = ['pondelok', 'utorok', 'streda', 'štvrtok', 'piatok', 'sobota', 'nedeľa'];
    $mes = ['januára', 'februára', 'marca', 'apríla', 'mája', 'júna', 'júla', 'augusta', 'septembra', 'októbra', 'novembra', 'decembra'];
    $d = new DateTime($day);
    return $dni[(int)$d->format('N') - 1] . ' ' . (int)$d->format('j') . '. ' . $mes[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}

function send_mail(string $to, string $subject, string $body, string $replyTo = ''): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = cfg('mail_from');
    $headers = [
        'From: Awitalis <' . $from . '>',
        'Reply-To: ' . ($replyTo !== '' ? $replyTo : cfg('mail_owner')),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers), '-f' . $from);
}

/** Jednoduchá HTML stránka v štýle webu (zrušenie termínu, administrácia). */
function page_start(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>' . h($title) . ' – Awitalis</title>'
       . '<link href="' . h(site_url()) . '/fonts/fonts.css" rel="stylesheet">'
       . '<style>
        :root{--bg:#EEF0EA;--card:#F6F7F3;--fg:#172A21;--muted:#55635B;--forest:#183327;--gold:#A8834C;--line:rgba(23,42,33,.14);--danger:#A2412F}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:400 16px/1.6 Figtree,system-ui,sans-serif}
        .wrap{max-width:1000px;margin:0 auto;padding:32px max(16px,env(safe-area-inset-left,0px)) 64px}
        h1,h2{font-family:Marcellus,Georgia,serif;font-weight:400;margin:0 0 12px}h1{font-size:34px}h2{font-size:23px;margin-top:28px}
        .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px 22px;margin-top:14px}
        .muted{color:var(--muted)}.gold{color:var(--gold)}
        .btn{display:inline-block;border:0;border-radius:999px;padding:11px 20px;font:600 15px Figtree,sans-serif;background:var(--forest);color:#EEF0EA;cursor:pointer;text-decoration:none}
        .btn.ghost{background:none;border:1px solid var(--fg);color:var(--fg)}.btn.danger{background:var(--danger)}.btn.sm{padding:6px 14px;font-size:13px}
        input,textarea,select{font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--fg)}
        table{width:100%;border-collapse:collapse;font-size:15px}td,th{text-align:left;padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:top}
        th{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:600}
        .tbl{overflow-x:auto}.row{display:flex;flex-wrap:wrap;gap:10px;align-items:end}.row label{display:grid;gap:4px;font-size:14px;font-weight:600}
        .msg{padding:12px 16px;border-radius:12px;background:#E2E6DC;margin-top:14px}.msg.err{background:#F3DDD7;color:var(--danger)}
        .cancelled td{opacity:.45;text-decoration:line-through}
        .top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:16px;margin-bottom:8px}
        .brand{font-family:Marcellus,serif;letter-spacing:.16em;font-size:20px}
       </style></head><body><div class="wrap">';
}
function page_end(): void { echo '</div></body></html>'; }
