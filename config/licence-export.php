<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Licence File Signing Key
    |--------------------------------------------------------------------------
    |
    | 64 hex characters (a 32-byte Ed25519 seed) used to sign air-gap
    | licence files. The seed lives in the environment only — never in
    | the repo. FlowEdu verifies with the public key printed by
    | `php artisan licence:verification-key`.
    |
    */

    'signing_key' => env('LICENCE_SIGNING_KEY'),

];
