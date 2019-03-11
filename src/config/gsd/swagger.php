<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Generate Docs for URI that start with this key
    |--------------------------------------------------------------------------
    |
    */

    'api/v1' => [

        /*
        |--------------------------------------------------------------------------
        | API routes prefix & version
        |--------------------------------------------------------------------------
        |
        */

        'api_version' => 'v1',

        /*
        |--------------------------------------------------------------------------
        | API Servers
        |--------------------------------------------------------------------------
        |
        */

        'servers'  => [
            [
                'url' => 'http://appname.localhost',
                'description' => 'Local'
            ],

            [
                'url' => 'www.domain.url',
                'description' => 'Production'
            ]

        ],

        /*
        |--------------------------------------------------------------------------
        | API security/middleware
        |--------------------------------------------------------------------------
        |
        */

        'security' => [
            'api.keys' => [
                'type'=> 'apiKey',
                'name' => 'X-Auth-Token',
                'in' => 'header'
            ]
        ],

        /*
        |--------------------------------------------------------------------------
        | API tags.
        |
        | @link https://swagger.io/docs/specification/grouping-operations-with-tags/
        |--------------------------------------------------------------------------
        |
        */

        'tags' => [
        ],

        /*
        |--------------------------------------------------------------------------
        | API Metadata
        |--------------------------------------------------------------------------
        |
        */

        'title' => 'Google Adwords Pentaho API',

        'contact' => [
            'email' => 'contact@domain.com'
        ],

        'output' => 'openapi.json'
    ]
];
