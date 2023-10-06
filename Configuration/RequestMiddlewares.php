<?php

return [
    'frontend' => [
        'image-on-demand-service' => [
            'target' => \Wineworlds\ImageOnDemandService\Middleware\ImageOnDemandMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
    ],
];
