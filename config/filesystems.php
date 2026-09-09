<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Per-tenant private areas (see App\Services\Tenancy\TenantStorage)
        |----------------------------------------------------------------------
        |
        | One disk per storage area, all rooted under `storage/app/private`
        | (outside the web root, never symlinked into `public/`) and all
        | `private` with no `url`, so nothing here can be served directly.
        | Paths inside each disk are namespaced `tenants/{tenantId}/...`.
        |
        | Areas are separate disks so exports/media can later be pointed at
        | object storage per data-region while WhatsApp auth state stays on
        | restrictive node-local storage the Bridge process reads directly.
        | `throw => true` turns a failed write into an exception instead of a
        | silently lost tenant file.
        |
        */

        'wa_auth' => [
            'driver' => 'local',
            'root' => storage_path(env('WA_AUTH_ROOT', 'app/private/wa-auth')),
            'visibility' => 'private',
            'permissions' => [
                'file' => ['public' => 0600, 'private' => 0600],
                'dir' => ['public' => 0700, 'private' => 0700],
            ],
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'wa_exports' => [
            'driver' => 'local',
            'root' => storage_path(env('WA_EXPORTS_ROOT', 'app/private/wa-exports')),
            'visibility' => 'private',
            'permissions' => [
                'file' => ['public' => 0640, 'private' => 0640],
                'dir' => ['public' => 0750, 'private' => 0750],
            ],
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'wa_media' => [
            'driver' => 'local',
            'root' => storage_path(env('WA_MEDIA_ROOT', 'app/private/wa-media')),
            'visibility' => 'private',
            'permissions' => [
                'file' => ['public' => 0640, 'private' => 0640],
                'dir' => ['public' => 0750, 'private' => 0750],
            ],
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
