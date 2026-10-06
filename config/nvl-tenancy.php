<?php

declare(strict_types=1);

return [
    'enabled' => false,
    'strategy' => 'shared-database',
    'connection' => null,
    'profile' => 'application',
    'directory' => [
        'driver' => 'package',
        'adapter' => null,
    ],
    'resolvers' => [
        'http' => null,
        'public_site' => null,
    ],
    'access' => [
        'membership' => null,
        'platform' => null,
    ],
    'resources' => [],
    'sharing' => [
        'media' => 'none',
        'metafields' => 'none',
        'templates' => 'none',
    ],
    'migrations' => ['enabled' => false],
];
