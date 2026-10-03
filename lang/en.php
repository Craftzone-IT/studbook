<?php

declare(strict_types=1);

// English UI strings (default and fallback). Keep keys sorted by area and
// add every new key to lang/hu.php in the same change.
return [
    'app.name' => 'Studbook',

    'nav.label' => 'Main navigation',
    'nav.home' => 'Home',
    'nav.settings' => 'Settings',
    'nav.logout' => 'Log out',
    'nav.skip_to_content' => 'Skip to content',

    'footer.rebrickable_prefix' => 'Part data provided by',
    'footer.rebrickable_suffix' => '.',
    'footer.licence' => 'Studbook is free software under the AGPL-3.0 licence.',
    'footer.source' => 'Source code',
    'footer.trademark' => 'LEGO® is a trademark of the LEGO Group, which does not sponsor, authorize or endorse this project.',

    'login.title' => 'Log in',
    'login.username' => 'Username',
    'login.password' => 'Password',
    'login.submit' => 'Log in',
    'login.failed' => 'Wrong username or password.',
    'login.throttled' => 'Too many failed attempts. Please wait a few minutes and try again.',

    'home.title' => 'Collections',
    'home.empty' => 'No collections yet. Collection management arrives in a later version.',

    'settings.title' => 'Settings',
    'settings.language' => 'Language',
    'settings.save' => 'Save',
    'settings.saved' => 'Settings saved.',
    'settings.invalid_language' => 'Unknown language.',
    'settings.format_example' => 'Example: {date} · {number}',

    'language.en' => 'English',
    'language.hu' => 'Magyar (Hungarian)',

    'error.bad_request' => 'Bad request',
    'error.forbidden' => 'Access denied',
    'error.not_found' => 'Page not found',
    'error.method_not_allowed' => 'Method not allowed',
    'error.csrf' => 'Your session has expired. Go back, reload the page and try again.',
    'error.server' => 'Something went wrong',
    'error.back_home' => 'Back to the home page',
];
