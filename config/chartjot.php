<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Self-Hosted Mode
    |--------------------------------------------------------------------------
    |
    | One trader running their own journal (issue #102). There's no login:
    | every browser request is signed in as the journal's owner, who is
    | created on the first request. Registration, admin approval, friends
    | and trade sharing are switched off. The AddOn API still needs its
    | journal token.
    |
    | Without a password (below), anyone who can reach the site sees the
    | journal, so set one unless the site only runs on your own PC.
    |
    */

    'self_hosted' => (bool) env('CHARTJOT_SELF_HOSTED', false),

    /*
    |--------------------------------------------------------------------------
    | Self-Hosted Password
    |--------------------------------------------------------------------------
    |
    | Optional. When set, each browser has to enter it once before it can
    | see the journal (it's remembered for a year). Changing it locks every
    | browser again. Leave it empty for no password, e.g. when the journal
    | only runs on your own PC.
    |
    */

    'password' => env('CHARTJOT_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Self-Hosted Owner
    |--------------------------------------------------------------------------
    |
    | The account created for the owner in self-hosted mode. The name shows
    | in the header ("{name}'s Trade Journal") and can be changed later on
    | the Profile page. The email is never used to send anything.
    |
    */

    'owner' => [
        'name'  => env('CHARTJOT_OWNER_NAME', 'Trader'),
        'email' => env('CHARTJOT_OWNER_EMAIL', 'owner@chartjot.local'),
    ],

];
