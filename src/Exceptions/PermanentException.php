<?php

namespace SocialPoster\Exceptions;

/**
 * Not retryable without intervention: bad or expired token, missing scope,
 * restricted account. The job fails and a PostFailed event is dispatched.
 */
class PermanentException extends SocialPosterException
{
}
