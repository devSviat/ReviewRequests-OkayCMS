<?php

use Okay\Core\Design;
use Okay\Core\OkayContainer\Reference\ParameterReference as PR;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestPreferences;
use Okay\Modules\Sviat\ReviewRequests\Plugins\ReviewRequestsPreferencesPlugin;

return [
    ReviewRequestsPreferencesPlugin::class => [
        'class' => ReviewRequestsPreferencesPlugin::class,
        'arguments' => [
            new SR(Design::class),
            new SR(ReviewRequestPreferences::class),
            new PR('root_dir'),
        ],
    ],
];
