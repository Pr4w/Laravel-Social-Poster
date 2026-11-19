<?php

namespace SocialPoster\Testing;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\MessageBag;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostQueued;
use SocialPoster\Exceptions\ValidationException;
use SocialPoster\PostBuilder;
use SocialPoster\Poster;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\QueuedPost;
use SocialPoster\ValueObjects\ValidationResult;

/**
 * The builder SocialPosterFake hands out: the same fluent surface (platform
 * selection, per-platform credentials and options, validation flags), but the
 * terminals record into the fake instead of calling drivers or the queue.
 * Credentials are never required and drivers are never resolved.
 */
class FakePostBuilder extends PostBuilder
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected SocialPosterFake $fake,
        SocialManager $manager,
        Dispatcher $events,
        array $config = [],
    ) {
        parent::__construct($manager, $events, $config);
    }

    /** @return array<string, ValidationResult> */
    public function validate(): array
    {
        $results = [];

        foreach ($this->fakeTargets() as $value => $post) {
            $result = new ValidationResult($post->platform, $this->fake->validationErrorsFor($post->platform));
            $this->fake->record(SocialPosterFake::TERMINAL_VALIDATE, $post, null, null, $result);
            $results[$value] = $result;
        }

        return $results;
    }

    /** @return array<string, PostResult> */
    public function postNow(): array
    {
        $targets = $this->fakeTargets();
        $rejected = $this->fakeGate($targets);
        $correlationId = $this->correlationId();
        $results = [];

        foreach ($targets as $value => $post) {
            if (isset($rejected[$value])) {
                $results[$value] = PostResult::failed($post->platform, $rejected[$value])->withMetadata($post->metadata());
                $this->fake->announce($post, $results[$value], $correlationId, null);

                continue;
            }

            $key = $this->fake->keyFor($post->platform, $correlationId);
            $results[$value] = $this->fake->resultFor($post, $correlationId, $key);

            $this->fake->record(SocialPosterFake::TERMINAL_POST_NOW, $post, $correlationId, $key, $results[$value]);
            $this->fake->announce($post, $results[$value], $correlationId, $key);
        }

        return $results;
    }

    public function post(): QueuedPost
    {
        $targets = $this->fakeTargets();
        $rejected = $this->fakeGate($targets);
        $correlationId = $this->correlationId();
        $queued = [];
        $keys = [];

        foreach ($targets as $value => $post) {
            if (isset($rejected[$value])) {
                if ($this->fake->shouldDispatchEvents()) {
                    $this->fake->dispatcher()->dispatch(new PostFailed($post->platform, $rejected[$value], $post->metadata(), $correlationId));
                }

                continue;
            }

            $key = $this->fake->keyFor($post->platform, $correlationId);
            $result = $this->fake->resultFor($post, $correlationId, $key);
            $queued[] = $post->platform;
            $keys[$value] = $key;

            $this->fake->record(SocialPosterFake::TERMINAL_POST, $post, $correlationId, $key, $result);

            if ($this->fake->shouldDispatchEvents()) {
                $this->fake->dispatcher()->dispatch(new PostQueued($post->platform, $post->post, $post->metadata(), $correlationId, $key));
            }

            $this->fake->announce($post, $result, $correlationId, $key);
        }

        return new QueuedPost($correlationId, $queued, $keys, [], $rejected);
    }

    /** @return array<string, PreparedPost> */
    protected function fakeTargets(): array
    {
        $post = $this->socialPost();
        $targets = [];

        foreach ($this->targets() as $platform) {
            $targets[$platform->value] = new PreparedPost($platform, $post, $this->credentialsFor($platform, false));
        }

        return $targets;
    }

    /**
     * The fake's validation gate: scripted errors, all-or-nothing unless
     * bestEffort().
     *
     * @param  array<string, PreparedPost>  $targets
     * @return array<string, ValidationException>
     */
    protected function fakeGate(array $targets): array
    {
        if ($this->skipValidation) {
            return [];
        }

        $combined = new MessageBag;
        $rejected = [];

        foreach ($targets as $value => $post) {
            $errors = $this->fake->validationErrorsFor($post->platform);

            if ($errors->isEmpty()) {
                continue;
            }

            $rejected[$value] = ValidationException::fromMessageBag($post->platform, $errors);

            foreach ($errors->toArray() as $field => $messages) {
                foreach ($messages as $message) {
                    $combined->add("{$value}.{$field}", $message);
                }
            }
        }

        if ($rejected !== [] && ! $this->bestEffort) {
            throw new ValidationException('Content failed validation before posting.', $combined);
        }

        return $rejected;
    }

    protected function poster(): Poster
    {
        return $this->fake;
    }
}
