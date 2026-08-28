<?php

use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\Currency;
use Nnjeim\World\Models\Language;
use Nnjeim\World\Models\State;
use Nnjeim\World\Models\Timezone;

return [

    

    'allowed_countries' => [],

    

    'disallowed_countries' => [],

    

    'accepted_locales' => [
        'ar',
        'az',
        'bn',
        'br',
        'de',
        'en',
        'es',
        'fa',
        'fr',
        'hr',
        'it',
        'ja',
        'kr',
        'nl',
        'pl',
        'pt',
        'ro',
        'ru',
        'tr',
        'zh',
    ],

    

    'modules' => [
        'states' => true,
        'cities' => true,
        'timezones' => true,
        'currencies' => true,
        'languages' => true,
    ],

    

    'routes' => true,

    

    'connection' => env('WORLD_DB_CONNECTION', env('DB_CONNECTION')),

    

    'migrations' => [
        'countries' => [
            'table_name' => 'countries',
            'optional_fields' => [
                'phone_code' => [
                    'required' => true,
                    'type' => 'string',
                    'length' => 5,
                ],
                'iso3' => [
                    'required' => true,
                    'type' => 'string',
                    'length' => 3,
                ],
                'native' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'region' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'subregion' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'latitude' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'longitude' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'emoji' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'emojiU' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ],
        'states' => [
            'table_name' => 'states',
            'optional_fields' => [
                'country_code' => [
                    'required' => true,
                    'type' => 'string',
                    'length' => 3,
                ],
                'state_code' => [
                    'required' => false,
                    'type' => 'string',
                    'length' => 5,
                ],
                'type' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'latitude' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'longitude' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ],
        'cities' => [
            'table_name' => 'cities',
            'optional_fields' => [
                'country_code' => [
                    'required' => true,
                    'type' => 'string',
                    'length' => 3,
                ],
                'state_code' => [
                    'required' => false,
                    'type' => 'string',
                    'length' => 5,
                ],
                'latitude' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'longitude' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ],
        'timezones' => [
            'table_name' => 'timezones',
        ],
        'currencies' => [
            'table_name' => 'currencies',
        ],
        'languages' => [
            'table_name' => 'languages',
        ],
    ],

    

    'models' => [
        'cities' => City::class,
        'countries' => Country::class,
        'currencies' => Currency::class,
        'languages' => Language::class,
        'states' => State::class,
        'timezones' => Timezone::class,
    ],

];
