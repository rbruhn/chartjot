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
    | Anyone who can reach the site sees the journal, so only use this where
    | the site isn't reachable from the internet (your own PC or network).
    |
    */

    'self_hosted' => (bool) env('CHARTJOT_SELF_HOSTED', false),

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
