<?php

use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\ValidatesAgainstCapabilities;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\Platform;
use SocialPoster\Support\Text\GraphemeCounter;
use SocialPoster\Support\Text\XWeightedCounter;
use SocialPoster\Transformers\ImageConvertingTransformer;
use SocialPoster\Transformers\NullMediaTransformer;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;

/** A minimal host for the validation trait, with public wrappers. */
function spRulesValidator(?MediaInspector $inspector = null): object
{
    return new class($inspector)
    {
        use ValidatesAgainstCapabilities;

        public function __construct(private ?MediaInspector $fakeInspector) {}

        public function platform(): Platform
        {
            return Platform::TikTok;
        }

        protected function inspector(): ?MediaInspector
        {
            return $this->fakeInspector;
        }

        public function caption(?string $caption, CaptionRules $rules): MessageBag
        {
            $errors = new MessageBag;
            $this->validateCaption($caption, new Capabilities($rules, new MediaRules), $errors);

            return $errors;
        }

        public function item(Media $media, ?ImageRules $image = null, ?VideoRules $video = null): MessageBag
        {
            $errors = new MessageBag;
            $this->validateMediaItem(0, $media, new Capabilities(new CaptionRules, new MediaRules(image: $image, video: $video)), $errors);

            return $errors;
        }

        public function inspect(Media $media): ?MediaMetadata
        {
            return $this->inspectMedia($media);
        }

        public function cacheSize(): int
        {
            return $this->inspectionCache === null ? 0 : count($this->inspectionCache);
        }
    };
}

/** An inspector returning fixed metadata and counting calls. */
function spRulesInspector(?MediaMetadata $meta, ?Throwable $throw = null): MediaInspector
{
    return new class($meta, $throw) implements MediaInspector
    {
        public int $calls = 0;

        public function __construct(private ?MediaMetadata $meta, private ?Throwable $throw) {}

        public function supports(Media $media): bool
        {
            return $this->meta !== null || $this->throw !== null;
        }

        public function inspect(Media $media): MediaMetadata
        {
            $this->calls++;

            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->meta ?? new MediaMetadata;
        }
    };
}

it('keeps the v1.0 caption message for the default counter', function () {
    $errors = spRulesValidator()->caption(str_repeat('a', 11), new CaptionRules(max: 10));

    expect($errors->first('caption'))->toBe('Caption exceeds the 10 character limit (currently 11).');
});

it('names the counting rule when a platform counter rejects a caption', function () {
    $errors = spRulesValidator()->caption(str_repeat('日', 141), new CaptionRules(max: 280, counter: new XWeightedCounter));

    expect($errors->first('caption'))
        ->toContain('Caption exceeds the 280 character limit (currently 282;')
        ->toContain('X counts each link as 23');
});

it('accepts a long link on X that mb_strlen would reject', function () {
    $caption = str_repeat('a', 250).' https://example.com/'.str_repeat('x', 100);

    expect(spRulesValidator()->caption($caption, new CaptionRules(max: 280, counter: new XWeightedCounter))->isEmpty())->toBeTrue()
        ->and(spRulesValidator()->caption($caption, new CaptionRules(max: 280))->isEmpty())->toBeFalse();
});

it('enforces a byte cap and the minimum with the counter', function () {
    $validator = spRulesValidator();

    expect($validator->caption(str_repeat('👨‍👩‍👧‍👦', 130), new CaptionRules(max: 300, counter: new GraphemeCounter, maxBytes: 3000))->first('caption'))
        ->toContain('exceeds the 3000-byte limit')
        ->and($validator->caption('short', new CaptionRules(min: 10, max: 100))->first('caption'))
        ->toBe('Caption must be at least 10 characters.')
        ->and($validator->caption("   \n", new CaptionRules(min: 10, max: 100))->isEmpty())->toBeTrue();
});

it('checks video frame rates with half a frame of tolerance', function (float $fps, bool $valid) {
    $media = Media::video('https://example.com/v.mp4', meta: ['frameRate' => $fps]);
    $errors = spRulesValidator()->item($media, video: new VideoRules(minFrameRate: 24, maxFrameRate: 60));

    expect($errors->isEmpty())->toBe($valid);
})->with([
    [15.0, false],
    [23.976, true],
    [30.0, true],
    [59.94, true],
    [120.0, false],
]);

it('checks image pixels, dimensions and minimum size', function () {
    $validator = spRulesValidator(spRulesInspector(new MediaMetadata(sizeBytes: 10, width: 7000, height: 6000)));
    $errors = $validator->item(Media::image('/tmp/a.jpg'), image: new ImageRules(
        maxPixels: 36152319,
        maxWidth: 4096,
        maxHeight: 4096,
        minSizeBytes: 100,
    ));

    expect($errors->get('media.0'))->toHaveCount(4)
        ->and(implode(' ', $errors->get('media.0')))
        ->toContain('42000000 pixels')
        ->toContain('width is above the 4096px maximum')
        ->toContain('height is above the 4096px maximum')
        ->toContain('100-byte minimum');

    expect(spRulesValidator(spRulesInspector(new MediaMetadata(width: 300, height: 300)))
        ->item(Media::image('/tmp/a.jpg'), image: new ImageRules(minWidth: 320, minHeight: 320))->get('media.0'))
        ->toHaveCount(2);
});

it('checks video minimum size and keeps the v1.0 dimension rules', function () {
    $validator = spRulesValidator(spRulesInspector(new MediaMetadata(sizeBytes: 0, width: 200, height: 200)));
    $errors = $validator->item(Media::video('/tmp/v.mp4'), video: new VideoRules(minWidth: 360, minSizeBytes: 1));

    expect(implode(' ', $errors->get('media.0')))
        ->toContain('Video width is below the 360px minimum.')
        ->toContain('1-byte minimum');
});

it('falls back to Media::$meta when the inspector cannot reach the file', function () {
    $media = Media::video('https://cdn.example.com/v.mp4', meta: ['width' => 1920, 'height' => 1080, 'duration' => 700]);
    $errors = spRulesValidator()->item($media, video: new VideoRules(maxDuration: 600, aspectRatioRange: [0.5, 0.6]));

    expect($errors->get('media.0'))->toHaveCount(2);
});

it('prefers measured metadata and fills its gaps from Media::$meta', function () {
    $inspector = spRulesInspector(new MediaMetadata(durationSeconds: 10.0));
    $media = Media::video('/tmp/v.mp4', meta: ['duration' => 700, 'width' => 1080, 'height' => 1920]);

    $meta = spRulesValidator($inspector)->inspect($media);

    expect($meta?->durationSeconds)->toBe(10.0)
        ->and($meta?->width)->toBe(1080)
        ->and($meta?->aspectRatio())->toBe(1080 / 1920);
});

it('never throws when the inspector does, and uses Media::$meta instead', function () {
    $validator = spRulesValidator(spRulesInspector(null, new RuntimeException('ffprobe exploded')));
    $media = Media::video('/tmp/v.mp4', meta: ['duration' => 700]);

    expect($validator->item($media, video: new VideoRules(maxDuration: 600))->first('media.0'))->toContain('too long');
});

it('returns null when nothing is known', function () {
    expect(spRulesValidator()->inspect(Media::image('https://example.com/a.jpg')))->toBeNull();
});

it('inspects each Media object once and does not keep it alive (WeakMap)', function () {
    $inspector = spRulesInspector(new MediaMetadata(width: 10, height: 10));
    $validator = spRulesValidator($inspector);
    $media = Media::image('/tmp/a.jpg');

    $validator->inspect($media);
    $validator->inspect($media);

    expect($inspector->calls)->toBe(1);

    // Same source, new object: inspected again (no id reuse / stale metadata).
    $validator->inspect(Media::image('/tmp/a.jpg'));
    expect($inspector->calls)->toBe(2);

    unset($media);
    gc_collect_cycles();

    expect($validator->cacheSize())->toBe(0);
});

it('rejects a convertible extension without a converting transformer, with a hint', function () {
    app()->instance(MediaTransformer::class, new NullMediaTransformer);

    $errors = spRulesValidator()->item(Media::image('/tmp/a.png'), image: new ImageRules(extensions: ['jpg', 'jpeg', 'webp'], convert: ['png' => 'jpg']));

    expect($errors->first('media.0'))
        ->toContain('Unsupported file type .png; allowed: jpg, jpeg, webp.')
        ->toContain('Convert it to .jpg');
});

it('accepts a convertible extension when the transformer can convert it, skipping size and MIME checks', function () {
    app()->instance(MediaTransformer::class, new ImageConvertingTransformer);

    $inspector = spRulesInspector(new MediaMetadata(sizeBytes: 50_000_000, width: 1000, height: 1000, mimeType: 'image/png'));
    $rules = new ImageRules(extensions: ['jpg', 'jpeg', 'webp'], types: ['image/jpeg'], maxSizeBytes: 20_971_520, maxWidth: 1080, convert: ['png' => 'jpg']);

    expect(spRulesValidator($inspector)->item(Media::image('/tmp/a.png'), image: $rules)->isEmpty())->toBeTrue();

    $tooWide = spRulesInspector(new MediaMetadata(width: 2000, height: 1000));
    expect(spRulesValidator($tooWide)->item(Media::image('/tmp/a.png'), image: $rules)->first('media.0'))
        ->toContain('1080px maximum');
})->skip(! function_exists('imagecreatefromstring'), 'ext-gd is required');

it('enforces video formats when the inspector reports one, without rejecting QuickTime-branded files', function (array $extensions, string $mime, bool $valid) {
    $validator = spRulesValidator(spRulesInspector(new MediaMetadata(mimeType: $mime)));

    expect($validator->item(Media::video('/tmp/v.mp4'), video: new VideoRules(extensions: $extensions))->isEmpty())->toBe($valid);
})->with([
    'mp4 file' => [['mp4', 'mov'], 'video/mp4', true],
    'mov on a platform that accepts mov' => [['mp4', 'mov'], 'video/quicktime', true],
    'QuickTime brand in a .mp4 on an mp4-only platform' => [['mp4'], 'video/quicktime', true],
    'webm renamed to .mp4' => [['mp4', 'mov'], 'video/webm', false],
    'avi renamed to .mp4' => [['mp4'], 'video/x-msvideo', false],
    'webm where webm is allowed' => [['mp4', 'webm'], 'video/webm', true],
]);
