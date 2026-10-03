<?php

declare(strict_types=1);

// Hungarian UI strings. Every key in lang/en.php must exist here too
// (checked by bin/check-translations and the test suite).
return [
    'app.name' => 'Studbook',

    'nav.label' => 'Főmenü',
    'nav.home' => 'Kezdőlap',
    'nav.settings' => 'Beállítások',
    'nav.logout' => 'Kijelentkezés',
    'nav.skip_to_content' => 'Ugrás a tartalomra',

    'footer.rebrickable_prefix' => 'Az alkatrészadatok forrása:',
    'footer.rebrickable_suffix' => '.',
    'footer.licence' => 'A Studbook szabad szoftver, AGPL-3.0 licenc alatt.',
    'footer.source' => 'Forráskód',
    'footer.trademark' => 'A LEGO® a LEGO Group védjegye, amely nem támogatja, nem engedélyezi és nem hagyja jóvá ezt a projektet.',

    'login.title' => 'Bejelentkezés',
    'login.username' => 'Felhasználónév',
    'login.password' => 'Jelszó',
    'login.submit' => 'Bejelentkezés',
    'login.failed' => 'Hibás felhasználónév vagy jelszó.',
    'login.throttled' => 'Túl sok sikertelen próbálkozás. Várj néhány percet, majd próbáld újra.',

    'home.title' => 'Gyűjtemények',
    'home.empty' => 'Még nincs gyűjtemény. A gyűjtemények kezelése egy későbbi verzióban érkezik.',

    'settings.title' => 'Beállítások',
    'settings.language' => 'Nyelv',
    'settings.save' => 'Mentés',
    'settings.saved' => 'A beállítások elmentve.',
    'settings.invalid_language' => 'Ismeretlen nyelv.',
    'settings.format_example' => 'Példa: {date} · {number}',

    'language.en' => 'English (angol)',
    'language.hu' => 'Magyar',

    'error.bad_request' => 'Hibás kérés',
    'error.forbidden' => 'Hozzáférés megtagadva',
    'error.not_found' => 'Az oldal nem található',
    'error.method_not_allowed' => 'Nem engedélyezett kérés',
    'error.csrf' => 'A munkamenet lejárt. Lépj vissza, töltsd újra az oldalt, és próbáld újra.',
    'error.server' => 'Hiba történt',
    'error.back_home' => 'Vissza a kezdőlapra',
];
