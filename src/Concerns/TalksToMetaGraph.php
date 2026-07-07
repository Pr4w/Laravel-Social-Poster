<?php

namespace SocialPoster\Concerns;

use SocialPoster\ValueObjects\PreparedPost;

/**
 * Shared plumbing for the Meta Graph family (Facebook, Instagram, Threads).
 * These platforms address a node under a versioned Graph host, poll a container
 * for a processing status, and read a public permalink off the published node —
 * the same three calls, differing only in host/version and a field name or two.
 *
 * Implementers supply graphBase() (host + version, typically config-driven) and
 * override containerStatusField() / permalinkField() where their Graph flavour
 * differs. They inherit http()/ok()/mapError() from AbstractPlatform.
 */
trait TalksToMetaGraph
{
    /** Full Graph base URL including the API version, e.g. https://graph.facebook.com/v23.0 */
    abstract protected function graphBase(): string;

    /** Graph field carrying a container's processing status (Instagram uses status_code). */
    protected function containerStatusField(): string
    {
        return 'status';
    }

    /** Graph field carrying a node's public permalink (Facebook uses permalink_url). */
    protected function permalinkField(): string
    {
        return 'permalink';
    }

    /** Build an edge URL under the account node: {base}/{account_id}{path}. */
    protected function edge(PreparedPost $post, string $path): string
    {
        return $this->graphBase().'/'.$post->credentials->get('account_id').$path;
    }

    /** Poll a container's processing status. */
    protected function containerStatus(PreparedPost $post, string $containerId): string
    {
        $field = $this->containerStatusField();

        $response = $this->http($post)->get($this->graphBase().'/'.$containerId, ['fields' => $field]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return (string) $response->json($field, 'IN_PROGRESS');
    }

    /**
     * Best-effort fetch of a node's public permalink. The post is already live, so
     * a failure here must not fail it; the result then carries the id with a null
     * url.
     */
    protected function fetchPermalink(PreparedPost $post, string $id): ?string
    {
        if ($id === '') {
            return null;
        }

        $field = $this->permalinkField();

        try {
            $response = $this->http($post)->get($this->graphBase().'/'.$id, ['fields' => $field]);

            return $this->ok($response) ? $response->json($field) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
