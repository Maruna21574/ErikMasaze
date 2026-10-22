<?php
// ============================================================
//  NASTAVENIA REZERVAČNÉHO SYSTÉMU AWITALIS
//  Toto je jediný súbor, ktorý treba upraviť pred spustením.
// ============================================================

return [

    // --- Databáza (údaje nájdete vo WebAdmine Websupportu → Databázy) ---
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=NAZOV_DATABAZY;charset=utf8mb4',
        'user' => 'POUZIVATEL_DATABAZY',
        'pass' => 'HESLO_DATABAZY',
    ],

    // --- Heslo do administrácie ---
    // Otvorte /admin/ v prehliadači, zadajte nové heslo a vygenerovaný riadok vložte sem.
    'admin_password_hash' => '',

    // --- E-maily ---
    'mail_from'  => 'rezervacie@awitalis.sk',     // adresa na vašej doméne (vytvorte ju vo WebAdmine)
    'mail_owner' => 'awitalismasaz@gmail.com',    // sem prídu upozornenia o nových rezerváciách
    'site_url'   => '',                           // nechajte prázdne (zistí sa samo), alebo napr. 'https://www.awitalis.sk'
    'phone'      => '0952 419 390',
    'address'    => 'Hurbanovská cesta 30, 946 57 Svätý Peter',

    // --- Otváracie hodiny a pravidlá ---
    'work_days'     => [1, 2, 3, 4, 5], // 1 = pondelok … 7 = nedeľa
    'open'          => 8 * 60,          // 8:00 (v minútach od polnoci)
    'close'         => 19 * 60,         // 19:00 – terapia musí skončiť najneskôr vtedy
    'step'          => 30,              // začiatky každých 30 minút
    'buffer'        => 15,              // prestávka po každej terapii (minúty)
    'lead_minutes'  => 120,             // rezervácia najskôr 2 hodiny vopred
    'horizon_days'  => 28,              // najviac 4 týždne dopredu
    'capacity'      => 1,               // koľko klientov naraz (2 = ak robia dvaja terapeuti)
    'cancel_hours'  => 12,              // klient môže zrušiť cez odkaz najneskôr 12 h vopred
    'retention_days'=> 365,             // po roku sa staré rezervácie automaticky zmažú (GDPR)
    'max_per_ip_hour' => 5,             // ochrana proti robotom

    // --- Terapie podľa cenníka ---
    // durations = dĺžky v minútach, prices = cena v € pre každú dĺžku (null = cena podľa dohody),
    // note = text namiesto dĺžky (napr. pri rázovej vlne), group = nadpis skupiny v rezervácii.
    'services' => [
        ['id' => 'celotelova',  'group' => 'Manuálne terapie', 'name' => 'Celotelová uvoľňujúca masáž',   'durations' => [50], 'prices' => [40]],
        ['id' => 'sportova',    'group' => 'Manuálne terapie', 'name' => 'Športová masáž',                'durations' => [50], 'prices' => [45]],
        ['id' => 'chrbat',      'group' => 'Manuálne terapie', 'name' => 'Masáž chrbta a šije',           'durations' => [30], 'prices' => [25]],
        ['id' => 'lymfo-telo',  'group' => 'Manuálne terapie', 'name' => 'Manuálna lymfodrenáž tela',     'durations' => [25], 'prices' => [45]],
        ['id' => 'lymfo-tvar',  'group' => 'Manuálne terapie', 'name' => 'Manuálna lymfodrenáž tváre',    'durations' => [25], 'prices' => [40]],
        ['id' => 'kranio',      'group' => 'Manuálne terapie', 'name' => 'Kraniosakrálna terapia',        'durations' => [50], 'prices' => [40]],
        ['id' => 'visceral',    'group' => 'Manuálne terapie', 'name' => 'Viscerálna terapia',            'durations' => [50], 'prices' => [40]],
        ['id' => 'reflexna',    'group' => 'Manuálne terapie', 'name' => 'Reflexná masáž chodidiel',      'durations' => [40], 'prices' => [30]],
        ['id' => 'lavove',      'group' => 'Manuálne terapie', 'name' => 'Masáž lávovými kameňmi',        'durations' => [60], 'prices' => [60]],
        ['id' => 'lomi',        'group' => 'Manuálne terapie', 'name' => 'Lomi Lomi masáž',               'durations' => [70], 'prices' => [80]],
        ['id' => 'madero',      'group' => 'Manuálne terapie', 'name' => 'Maderoterapia',                 'durations' => [50], 'prices' => [45]],
        ['id' => 'lymfopres',   'group' => 'Prístrojové terapie', 'name' => 'Prístrojová lymfodrenáž (lymfopres)', 'durations' => [30], 'prices' => [20]],
        ['id' => 'shockwave',   'group' => 'Prístrojové terapie', 'name' => 'Terapia rázovou vlnou (shockwave)',   'durations' => [30], 'prices' => [20], 'note' => '1 lokalita'],
        ['id' => 'komplexna',   'group' => 'Prístrojové terapie', 'name' => 'Komplexná terapia (rázová vlna + manuálne ošetrenie)', 'durations' => [60], 'prices' => [55], 'note' => '2 lokality'],
        ['id' => 'smt',         'group' => 'Ďalšie terapie', 'name' => 'Športová manuálna terapia', 'durations' => [45, 60], 'prices' => [null, null]],
        ['id' => 'breuss',      'group' => 'Ďalšie terapie', 'name' => 'Breuss masáž',              'durations' => [45],     'prices' => [null]],
        ['id' => 'moxa',        'group' => 'Ďalšie terapie', 'name' => 'Moxovanie',                 'durations' => [30],     'prices' => [null]],
        ['id' => 'rolfing',     'group' => 'Ďalšie terapie', 'name' => 'Rolfing®',                  'durations' => [60, 90], 'prices' => [null, null]],
    ],
];
