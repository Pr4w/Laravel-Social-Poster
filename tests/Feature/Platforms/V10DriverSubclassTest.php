<?php

/*
 * The v1.0 README invites extending the first-party drivers. A subclass written
 * for 1.0 must still load (PHP refuses an override whose signature is
 * narrower than the parent's), and its override must still be called.
 */

namespace SocialPoster\Tests\Feature\Platforms\V10Subclass;

use Illuminate\Http\Client\Response;
use SocialPoster\Platforms\Instagram\InstagramDriver;
use SocialPoster\Platforms\LinkedIn\LinkedInDriver;
use SocialPoster\Platforms\YouTube\YouTubeDriver;

it('loads a 1.0-style classifyError($response) override of the LinkedIn, Instagram and YouTube drivers', function (string $parent) {
    $class = 'V10'.class_basename($parent).bin2hex(random_bytes(4));

    eval(<<<PHP
        namespace SocialPoster\\Tests\\Feature\\Platforms\\V10Subclass;

        class {$class} extends \\{$parent}
        {
            protected function classifyError(\$response): \\SocialPoster\\Exceptions\\TemporaryException|\\SocialPoster\\Exceptions\\PermanentException
            {
                return new \\SocialPoster\\Exceptions\\PermanentException('custom', \\SocialPoster\\Enums\\Platform::X, \\SocialPoster\\Enums\\FailureReason::InvalidContent);
            }

            public function classify(\$response): \\SocialPoster\\Exceptions\\TemporaryException|\\SocialPoster\\Exceptions\\PermanentException
            {
                return \$this->mapError(\$response, 'post', 'urn:li:person:1');
            }
        }
        PHP);

    $fqcn = __NAMESPACE__.'\\'.$class;
    $response = new Response(new \GuzzleHttp\Psr7\Response(400, [], '{}'));

    expect((new $fqcn)->classify($response)->getMessage())->toBe('custom');
})->with([LinkedInDriver::class, InstagramDriver::class, YouTubeDriver::class]);
