<?php

namespace SocialPoster\Tests\Unit\TikTokOptionsTest;

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Platforms\TikTok\TikTokFailReason;
use SocialPoster\Platforms\TikTok\TikTokOptions;
use SocialPoster\Platforms\TikTok\TikTokPrivacy;
use SocialPoster\Platforms\TikTok\TikTokTarget;
use SocialPoster\Platforms\TikTok\TikTokTransfer;

it('keeps the 1.0 constructor positions and defaults', function () {
    $options = new TikTokOptions(TikTokTarget::DirectPost, TikTokPrivacy::SelfOnly, true, false, null, 1200, 2, true, ['x' => 1]);

    expect($options->target)->toBe(TikTokTarget::DirectPost)
        ->and($options->privacyLevel)->toBe(TikTokPrivacy::SelfOnly)
        ->and($options->disableComment)->toBeTrue()
        ->and($options->disableDuet)->toBeFalse()
        ->and($options->disableStitch)->toBeNull()
        ->and($options->coverTimestampMs)->toBe(1200)
        ->and($options->photoCoverIndex)->toBe(2)
        ->and($options->autoAddMusic)->toBeTrue()
        ->and($options->extra())->toBe(['x' => 1])
        ->and($options->transfer)->toBe(TikTokTransfer::Auto)
        ->and($options->brandContent)->toBeNull()
        ->and($options->brandOrganic)->toBeNull()
        ->and($options->isAigc)->toBeNull()
        ->and($options->privacyFallbacks)->toBe([])
        ->and($options->platform())->toBe(Platform::TikTok)
        ->and((new TikTokOptions)->target)->toBe(TikTokTarget::Drafts);
});

it('validates the cover choices, the fallbacks and branded content', function () {
    expect((new TikTokOptions)->validate()->isEmpty())->toBeTrue();

    $errors = (new TikTokOptions(
        privacyLevel: TikTokPrivacy::SelfOnly,
        coverTimestampMs: -5,
        photoCoverIndex: -1,
        brandContent: true,
        privacyFallbacks: [TikTokPrivacy::SelfOnly, 'PUBLIC'],
    ))->validate()->toArray();

    expect($errors)->toHaveKeys(['photo_cover_index', 'cover_timestamp_ms', 'brand_content', 'privacy_fallbacks'])
        ->and($errors['privacy_fallbacks'])->toHaveCount(2);

    expect((new TikTokOptions(privacyLevel: TikTokPrivacy::PublicToEveryone, coverTimestampMs: 0, brandContent: true, privacyFallbacks: [TikTokPrivacy::FollowerOfCreator]))->validate()->isEmpty())->toBeTrue()
        ->and((new TikTokOptions(privacyLevel: TikTokPrivacy::SelfOnly, brandOrganic: true))->validate()->isEmpty())->toBeTrue();
});

it('restores options serialised by 1.0, which have none of the 1.1 properties', function () {
    // serialize(new TikTokOptions(DirectPost, PublicToEveryone, true, null, null, 500, 1, null, ['is_aigc' => true]))
    // produced by the 1.0 class (git show v1.0:src/Platforms/TikTok/TikTokOptions.php).
    $legacy = 'O:43:"SocialPoster\Platforms\TikTok\TikTokOptions":9:{'
        .'s:6:"target";E:53:"SocialPoster\Platforms\TikTok\TikTokTarget:DirectPost";'
        .'s:12:"privacyLevel";E:60:"SocialPoster\Platforms\TikTok\TikTokPrivacy:PublicToEveryone";'
        .'s:14:"disableComment";b:1;s:11:"disableDuet";N;s:13:"disableStitch";N;'
        .'s:16:"coverTimestampMs";i:500;s:15:"photoCoverIndex";i:1;s:12:"autoAddMusic";N;'
        .'s:5:"extra";a:1:{s:7:"is_aigc";b:1;}}';

    $options = unserialize($legacy);

    expect($options)->toBeInstanceOf(TikTokOptions::class)
        ->and($options->target)->toBe(TikTokTarget::DirectPost)
        ->and($options->privacyLevel)->toBe(TikTokPrivacy::PublicToEveryone)
        ->and($options->disableComment)->toBeTrue()
        ->and($options->coverTimestampMs)->toBe(500)
        ->and($options->photoCoverIndex)->toBe(1)
        ->and($options->extra())->toBe(['is_aigc' => true])
        ->and($options->transfer)->toBe(TikTokTransfer::Auto)
        ->and($options->brandContent)->toBeNull()
        ->and($options->privacyFallbacks)->toBe([])
        ->and($options->validate()->isEmpty())->toBeTrue();
});

it('round-trips through serialize', function () {
    $options = new TikTokOptions(
        target: TikTokTarget::DirectPost,
        privacyLevel: TikTokPrivacy::MutualFollowFriends,
        transfer: TikTokTransfer::FileUpload,
        brandContent: true,
        brandOrganic: false,
        isAigc: true,
        privacyFallbacks: [TikTokPrivacy::FollowerOfCreator],
    );

    expect(unserialize(serialize($options)))->toEqual($options);
});

it('maps every documented fail_reason', function () {
    expect(TikTokFailReason::reason('frame_rate_check_failed'))->toBe(FailureReason::MediaRejected)
        ->and(TikTokFailReason::reason(' INTERNAL '))->toBe(FailureReason::ServerError)
        ->and(TikTokFailReason::reason('publish_cancelled'))->toBe(FailureReason::Unknown)
        ->and(TikTokFailReason::reason('brand_new'))->toBe(FailureReason::MediaRejected)
        ->and(TikTokFailReason::restartable('photo_pull_failed'))->toBeTrue()
        ->and(TikTokFailReason::restartable('auth_removed'))->toBeFalse()
        ->and(TikTokFailReason::message(''))->toBe('TikTok failed to process the post.')
        ->and(TikTokFailReason::context('auth_removed'))->toBe(['terminal' => true])
        ->and(TikTokFailReason::context('internal'))->toBe([])
        ->and(TikTokFailReason::dailyCapContext(0))->toBe(['retry_after_hint' => 86400, 'retry_at' => '1970-01-02T00:00:00Z']);

    $e = TikTokFailReason::exception('spam_risk_too_many_posts', ['publish_id' => 'p1']);

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->platform)->toBe(Platform::TikTok)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context)->toMatchArray(['publish_id' => 'p1', 'fail_reason' => 'spam_risk_too_many_posts', 'retry_after_hint' => 86400]);
});
