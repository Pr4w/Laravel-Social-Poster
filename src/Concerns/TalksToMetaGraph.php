<?php

namespace SocialPoster\Concerns;

use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Support\RetryAfter;
use SocialPoster\ValueObjects\PreparedPost;

/**
 * Shared plumbing for the Meta Graph family (Facebook, Instagram, Threads).
 * These platforms address a node under a versioned Graph host, poll a container
 * for a processing status, read a public permalink off the published node and
 * report errors in the same {error: {code, error_subcode, message, ...}} shape:
 * the same calls, differing only in host/version and a field name or two.
 *
 * Implementers supply graphBase() (host + version, typically config-driven) and
 * may override:
 *
 * - graphBaseFor(): a per-post host (Instagram Login accounts live on
 *   graph.instagram.com, Facebook Login ones on graph.facebook.com);
 * - containerStatusField() / containerErrorFields() / permalinkField(): where
 *   their Graph flavour keeps those values.
 *
 * They inherit http()/ok()/mapError() from AbstractPlatform, and call
 * classifyMetaError() from their classifyError() (after their own subcodes,
 * before parent::classifyError()).
 */
trait TalksToMetaGraph
{
    /** Full Graph base URL including the API version, e.g. https://graph.facebook.com/v25.0 */
    abstract protected function graphBase(): string;

    /**
     * The Graph base URL for this post's account. Defaults to graphBase(); a
     * driver whose accounts live on different hosts (Instagram Login vs
     * Facebook Login) overrides it. Every edge, container and permalink call
     * goes through it.
     */
    protected function graphBaseFor(PreparedPost $post): string
    {
        return $this->graphBase();
    }

    /**
     * Graph field carrying a container's processing status (Instagram uses
     * status_code). A dotted path reads a nested value (e.g.
     * 'status.video_status'); only its first segment is requested.
     */
    protected function containerStatusField(): string
    {
        return 'status';
    }

    /**
     * Graph fields that may carry a container's failure description, in order.
     * Threads uses error_message; Instagram, whose status lives in status_code,
     * describes the failure in status.
     *
     * @return list<string>
     */
    protected function containerErrorFields(): array
    {
        return $this->containerStatusField() === 'status' ? ['error_message'] : ['status'];
    }

    /** Graph field carrying a node's public permalink (Facebook uses permalink_url). */
    protected function permalinkField(): string
    {
        return 'permalink';
    }

    /** Seconds allowed for a Meta create/publish call (media_publish, threads_publish, /feed...). */
    protected function publishTimeout(): int
    {
        return (int) $this->apiConfig('publish_timeout', config('social.publish_timeout', 120));
    }

    /**
     * Build an edge URL under the account node: {base}/{account_id}{path}.
     *
     * @throws PermanentException When account_id is missing (Misconfigured), instead of
     *                            calling '{base}//feed' and getting a confusing Graph error.
     */
    protected function edge(PreparedPost $post, string $path): string
    {
        $account = $post->credentials->get('account_id');

        if (! is_scalar($account) || trim((string) $account) === '') {
            throw new PermanentException(
                "The {$this->platform()->value} credentials have no account_id.",
                $this->platform(),
                FailureReason::Misconfigured,
                ['missing_credentials' => ['account_id']],
            );
        }

        return rtrim($this->graphBaseFor($post), '/').'/'.trim((string) $account).$path;
    }

    /**
     * Read a container's (or video node's) processing state.
     *
     * 'status' is the raw value of containerStatusField(), or null when Graph
     * did not return one: null means UNKNOWN, never "still in progress", so
     * callers decide (poll again with a budget, or verify). 'error_message' is
     * the first non-empty containerErrorFields() value. 'raw' is the decoded body.
     *
     * @param  list<string>|null  $fields  Fields to request; defaults to the status field plus the error fields.
     * @return array{status: ?string, error_message: ?string, raw: array<string, mixed>}
     */
    protected function containerState(PreparedPost $post, string $id, ?array $fields = null): array
    {
        $statusField = $this->containerStatusField();
        $fields ??= [explode('.', $statusField)[0], ...$this->containerErrorFields()];
        $fields = array_values(array_unique(array_filter($fields, static fn ($field) => is_string($field) && $field !== '')));

        $response = $this->http($post)->get(rtrim($this->graphBaseFor($post), '/').'/'.$id, ['fields' => implode(',', $fields)]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $raw = $response->json();
        $raw = is_array($raw) ? $raw : [];

        $status = data_get($raw, $statusField);
        $errorMessage = null;

        foreach ($this->containerErrorFields() as $field) {
            $value = data_get($raw, $field);

            if (is_string($value) && trim($value) !== '') {
                $errorMessage = trim($value);
                break;
            }
        }

        return [
            'status' => is_string($status) && trim($status) !== '' ? trim($status) : null,
            'error_message' => $errorMessage,
            'raw' => $raw,
        ];
    }

    /**
     * Poll a container's processing status. Requests only the status field (as
     * in 1.0). Returns null when Graph did not report one: treat it as unknown,
     * not IN_PROGRESS.
     */
    protected function containerStatus(PreparedPost $post, string $containerId): ?string
    {
        return $this->containerState($post, $containerId, [explode('.', $this->containerStatusField())[0]])['status'];
    }

    /**
     * Best-effort fetch of a node's public permalink. The post is already live, so
     * a failure here must not fail it; the result then carries the id with a null
     * url.
     */
    protected function fetchPermalink(PreparedPost $post, string $id): ?string
    {
        if (trim($id) === '') {
            return null;
        }

        $field = $this->permalinkField();

        try {
            $response = $this->http($post)->get(rtrim($this->graphBaseFor($post), '/').'/'.$id, ['fields' => $field]);

            if (! $this->ok($response)) {
                return null;
            }

            $permalink = $response->json($field);

            return is_string($permalink) && $permalink !== '' ? $permalink : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The parts of a Graph error body every classifier needs:
     *
     * - code / subcode: error.code and error.error_subcode (0 when absent);
     * - raw: the technical error.message. Match strings against this, never
     *   against the user message, which Meta localises;
     * - message: what the exception should say (error_user_msg, else raw, else a
     *   generic line with the HTTP status);
     * - error: the whole error object; json: whether the body was JSON at all;
     * - context: diagnostics for the exception (code, subcode, type,
     *   is_transient, error_user_title, error_user_msg, fbtrace_id, raw_message,
     *   error, status, http_status).
     *
     * @return array{code: int, subcode: int, raw: string, message: string, error: array<string, mixed>, json: bool, context: array<string, mixed>}
     */
    protected function metaErrorParts($response): array
    {
        $body = null;

        try {
            $body = $response->json();
        } catch (\Throwable) {
            // Non-JSON body (an HTML 502 from a proxy).
        }

        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $status = (int) $response->status();
        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : 0;
        $subcode = is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : 0;
        $raw = is_string($error['message'] ?? null) ? trim($error['message']) : '';
        $userMessage = is_string($error['error_user_msg'] ?? null) ? trim($error['error_user_msg']) : '';

        $message = $userMessage !== '' ? $userMessage : $raw;

        if ($message === '') {
            $message = "{$this->platform()->value} request failed with HTTP {$status}.";
        }

        $context = array_filter([
            'status' => $status,
            'http_status' => $status,
            'code' => $code !== 0 ? $code : null,
            'subcode' => $subcode !== 0 ? $subcode : null,
            'type' => $error['type'] ?? null,
            'is_transient' => $error['is_transient'] ?? null,
            'error_user_title' => $error['error_user_title'] ?? null,
            'error_user_msg' => $userMessage !== '' ? $userMessage : null,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
            'raw_message' => $raw !== '' ? $raw : null,
            'error' => $error !== [] ? $error : null,
        ], static fn ($value) => $value !== null && $value !== '');

        return [
            'code' => $code,
            'subcode' => $subcode,
            'raw' => $raw,
            'message' => $message,
            'error' => $error,
            'json' => is_array($body),
            'context' => $context,
        ];
    }

    /**
     * The Graph-wide error table shared by Facebook, Instagram and Threads.
     * Returns the classified exception, or null when the error is not one of
     * Meta's generic codes, so the driver continues with its own rules and then
     * parent::classifyError().
     *
     * Drivers must classify their own subcodes BEFORE calling this: several
     * product subcodes ride on generic codes (Instagram 2207051 comes with code
     * 4, 2207057 with code 1) and must not be retried as throttling.
     *
     * Rows, in order:
     * - token: codes 102/190, subcodes 458/459/460/463/464/467/492, or the raw
     *   message 'Error validating access token' / 'has not authorized
     *   application' => InvalidToken (terminal for 458/460/463/467/492);
     *   459/464 => AccountRestricted (log in on facebook.com); 492 =>
     *   InsufficientPermissions (lost the Page role); 460 => password changed;
     * - throttling: codes 4/17/32/341/613 and the Business Use Case codes
     *   80000-80014, HTTP 429 or 'rate limit' => Temporary RateLimited, waiting
     *   the estimated_time_to_regain_access from X-Business-Use-Case-Usage /
     *   X-App-Usage, else Retry-After, else 900s (4/17/613/800xx) or 300s;
     * - transient: is_transient, codes 1/2/-1/-2, any 5xx (JSON or not) =>
     *   Temporary ServerError, 60s;
     * - permissions: codes 3/10/200-299, or 100 with subcode 33 =>
     *   InsufficientPermissions;
     * - 368 => AccountRestricted (temporarily blocked; context retry_later);
     * - 506 => DuplicateContent.
     */
    protected function classifyMetaError($response): TemporaryException|PermanentException|null
    {
        [
            'code' => $code,
            'subcode' => $subcode,
            'raw' => $raw,
            'message' => $message,
            'error' => $error,
            'context' => $context,
        ] = $this->metaErrorParts($response);

        $platform = $this->platform();
        $status = (int) $response->status();
        $lower = strtolower($raw);

        // Token rejected.
        if (in_array($code, [102, 190], true)
            || in_array($subcode, [458, 459, 460, 463, 464, 467, 492], true)
            || str_contains($lower, 'error validating access token')
            || str_contains($lower, 'has not authorized application')) {
            $terminal = in_array($subcode, [458, 460, 463, 467, 492], true);
            $context['terminal'] = $terminal;

            return match (true) {
                in_array($subcode, [459, 464], true) => new PermanentException(
                    "The account owner must log in on facebook.com and clear a security checkpoint before posting again. ({$message})",
                    $platform,
                    FailureReason::AccountRestricted,
                    $context,
                ),
                $subcode === 492 => new PermanentException(
                    "The token's user no longer has a role on this Page; restore the role or reconnect with a Page admin. ({$message})",
                    $platform,
                    FailureReason::InsufficientPermissions,
                    $context,
                ),
                $subcode === 460 => new PermanentException(
                    "The access token was invalidated because the user changed their password; reconnect the account. ({$message})",
                    $platform,
                    FailureReason::InvalidToken,
                    $context,
                ),
                default => new PermanentException(
                    $message,
                    $platform,
                    FailureReason::InvalidToken,
                    $context + ['reresolvable' => ! $terminal],
                ),
            };
        }

        // Throttling.
        if (in_array($code, [4, 17, 32, 341, 613], true)
            || ($code >= 80000 && $code <= 80014)
            || $status === 429
            || str_contains($lower, 'rate limit')) {
            $long = in_array($code, [4, 17, 613], true) || ($code >= 80000 && $code <= 80014);
            $retryAfter = $this->metaRegainAccessSeconds($response)
                ?? RetryAfter::fromResponse($response)
                ?? ($long ? 900 : 300);

            return new TemporaryException($message, $platform, FailureReason::RateLimited, $retryAfter, $context);
        }

        // Transient outage.
        if (! empty($error['is_transient']) || in_array($code, [1, 2, -1, -2], true) || $status >= 500) {
            return new TemporaryException($message, $platform, FailureReason::ServerError, 60, $context);
        }

        // Missing permission or lost access to the object.
        if (in_array($code, [3, 10], true) || ($code >= 200 && $code <= 299) || ($code === 100 && $subcode === 33)) {
            return new PermanentException($message, $platform, FailureReason::InsufficientPermissions, $context);
        }

        if ($code === 368) {
            return new PermanentException(
                $message,
                $platform,
                FailureReason::AccountRestricted,
                $context + ['retry_later' => true],
            );
        }

        if ($code === 506) {
            return new PermanentException($message, $platform, FailureReason::DuplicateContent, $context);
        }

        return null;
    }

    /**
     * Seconds until Meta lifts a throttle, from the largest
     * estimated_time_to_regain_access (minutes) in the X-Business-Use-Case-Usage
     * or X-App-Usage headers. Null when neither carries a positive estimate.
     */
    protected function metaRegainAccessSeconds($response): ?int
    {
        $minutes = [];

        foreach (['X-Business-Use-Case-Usage', 'X-App-Usage'] as $header) {
            try {
                $value = (string) $response->header($header);
            } catch (\Throwable) {
                continue;
            }

            $decoded = $value !== '' ? json_decode($value, true) : null;

            if (is_array($decoded)) {
                array_walk_recursive($decoded, static function ($item, $key) use (&$minutes) {
                    if ($key === 'estimated_time_to_regain_access' && is_numeric($item)) {
                        $minutes[] = (float) $item;
                    }
                });
            }
        }

        $max = $minutes === [] ? 0.0 : max($minutes);

        return $max > 0 ? (int) ceil($max * 60) : null;
    }
}
