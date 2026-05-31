<?php

use SocialPoster\Enums\Platform;

return [

    // Optional default platform used when none is specified on the builder.
    'default' => null,

    // Queue name for the publish jobs. Null uses the default queue.
    'queue' => env('SOCIAL_QUEUE'),

    // Reads media metadata for the deep validation checks.
    'inspector' => SocialPoster\Inspectors\FFProbeInspector::class,

    // Resolves media to a URL or bytes. Swap for one that publishes local files
    // (e.g. to S3) if you post local files to pull-only platforms like Facebook.
    'gateway' => SocialPoster\Gateways\LocalMediaGateway::class,

    // First-party drivers shipped with the package. Resolved from the container,
    // so they receive the inspector and gateway automatically.
    'drivers' => [
        Platform::Facebook->value => SocialPoster\Platforms\Facebook\FacebookDriver::class,
        Platform::Instagram->value => SocialPoster\Platforms\Instagram\InstagramDriver::class,
        Platform::LinkedIn->value => SocialPoster\Platforms\LinkedIn\LinkedInDriver::class,
        Platform::Threads->value => SocialPoster\Platforms\Threads\ThreadsDriver::class,
        Platform::X->value => SocialPoster\Platforms\X\XDriver::class,
        Platform::TikTok->value => SocialPoster\Platforms\TikTok\TikTokDriver::class,
    ],

    // Static, single-account credentials per platform. Omit and pass credentials
    // at call time via ->using(...) for the multi-account case.
    'platforms' => [
        // 'facebook' => [
        //     'credentials' => [
        //         'account_id' => env('FB_PAGE_ID'),
        //         'page_access_token' => env('FB_PAGE_TOKEN'),
        //     ],
        // ],
        // 'instagram' => [
        //     'credentials' => [
        //         'account_id' => env('IG_USER_ID'),
        //         'access_token' => env('IG_ACCESS_TOKEN'),
        //     ],
        // ],
    ],
];
