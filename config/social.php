<?php

use SocialPoster\Enums\Platform;

return [

    // Optional default platform used when none is specified on the builder.
    'default' => null,

    // Queue name for the publish jobs. Null uses the default queue.
    'queue' => env('SOCIAL_QUEUE'),

    // Seconds to wait after a post is live before adding its first comment,
    // giving the platform a moment to make the new post commentable.
    'comment_delay' => env('SOCIAL_COMMENT_DELAY', 10),

    // Duplicate protection for the queued path. Default remembers nothing; set to
    // DatabaseIdempotencyStore::class (and publish the migration) to enable it.
    'idempotency' => SocialPoster\Idempotency\NullIdempotencyStore::class,
    'idempotency_table' => 'social_idempotency',

    // Log every failed platform response (driver, status, code/subcode, body) so
    // unmapped errors are easy to find and add to a driver's classifyError().
    'error_logging' => env('SOCIAL_ERROR_LOGGING', true),
    'error_log_channel' => env('SOCIAL_ERROR_LOG_CHANNEL'),

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
        Platform::YouTube->value => SocialPoster\Platforms\YouTube\YouTubeDriver::class,
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
