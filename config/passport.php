<?php

declare(strict_types=1);

// Rebuilds a PEM key that got mangled when pasted into a hosting panel
// (surrounding quotes, literal "\n", escaped backslashes, or line breaks turned into spaces).
$normalizePemKey = static function (mixed $key): ?string {
    if (! is_string($key) || trim($key) === '') {
        return null;
    }
    $key = str_replace(['\\\\n', '\\n', '\\r', "\r"], ["\n", "\n", '', ''], trim($key, " \t\n\r\0\x0B\"'"));
    if (preg_match('/-----BEGIN ([A-Z ]+)-----(.*?)-----END \\1-----/s', $key, $m) !== 1) {
        return $key;
    }
    $body = preg_replace('/\\s+/', '', $m[2]) ?? '';

    return "-----BEGIN {$m[1]}-----\n".chunk_split($body, 64, "\n")."-----END {$m[1]}-----\n";
};

return [

    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Passport will use when
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport uses encryption keys while generating secure access tokens for
    | your application. By default, the keys are stored as local files but
    | can be set via environment variables when that is more convenient.
    |
    */

    'private_key' => $normalizePemKey(env('PASSPORT_PRIVATE_KEY')),

    'public_key' => $normalizePemKey(env('PASSPORT_PUBLIC_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport's models will utilize your application's default
    | database connection. If you wish to use a different connection you
    | may specify the configured name of the database connection here.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),

];
