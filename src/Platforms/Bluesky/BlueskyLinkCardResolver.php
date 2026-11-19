<?php

namespace SocialPoster\Platforms\Bluesky;

use DOMDocument;
use DOMElement;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Imagick;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SocialPoster\Support\PublicUrl;
use Throwable;

/**
 * Builds the app.bsky.embed.external link card the Bluesky app shows for a
 * post's first link: the API leaves it to the client. Strictly best effort:
 * any failure gives no card (or a card without a thumbnail) and is logged at
 * info level; the post still goes out with its link clickable.
 *
 * The page and its og:image are user-controlled URLs fetched by the server, so:
 * - https only, TLS verification on, at most 3 redirects followed by hand, each
 *   hop re-checked with PublicUrl and, when private networks are blocked,
 *   pinned to the checked address (CURLOPT_RESOLVE) so DNS cannot be rebound;
 * - short timeouts and a streamed byte cap (1 MB of HTML, a few MB of image);
 * - text/html for the page and image/* for the thumbnail, or nothing.
 *
 * Parsing uses DOMDocument: og:* tags, then twitter:*, then <title> and the
 * description meta. og:image is resolved against the final page URL (RFC 3986).
 * A thumbnail over 1,000,000 bytes (the external.thumb limit) is scaled down
 * to at most 1200 px wide as JPEG with GD or Imagick, or dropped.
 */
class BlueskyLinkCardResolver
{
    public const MAX_REDIRECTS = 3;

    public function __construct(
        protected int $timeout = 10,
        protected ?string $userAgent = null,
        protected int $maxHtmlBytes = 1_000_000,
        protected int $maxImageBytes = 5_000_000,
        protected int $thumbMaxBytes = 1_000_000,
        protected int $imageTimeout = 15,
    ) {}

    /**
     * The card for the first https link of $text, or null.
     *
     * @param  callable(string, string): ?array<string, mixed>  $uploadBlob  bytes, mime => blob or null
     * @return array<string, mixed>|null
     */
    public function forText(string $text, callable $uploadBlob): ?array
    {
        foreach (BlueskyRichText::links($text) as $link) {
            if ($link['scheme'] && str_starts_with(strtolower($link['uri']), 'https://')) {
                return $this->forUrl($link['uri'], $uploadBlob);
            }
        }

        return null;
    }

    /**
     * The card for $url, built from its Open Graph tags, or null.
     *
     * @param  callable(string, string): ?array<string, mixed>  $uploadBlob
     * @return array<string, mixed>|null
     */
    public function forUrl(string $url, callable $uploadBlob): ?array
    {
        if (! $this->canParse()) {
            // Without a parser every card would be a bare host name.
            $this->skip('ext-dom is not installed, so the page cannot be parsed', $url);

            return null;
        }

        try {
            $page = $this->fetch($url, $this->maxHtmlBytes, $this->timeout, ['text/html', 'application/xhtml+xml'], 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1');
        } catch (Throwable $e) {
            $this->skip('the page could not be read', $url, $e);

            return null;
        }

        if ($page === null) {
            $this->skip('the page is not an HTML page that could be read', $url);

            return null;
        }

        $meta = $this->parse($page['body'], $page['charset']);
        $host = (string) parse_url($url, PHP_URL_HOST);

        $external = [
            'uri' => $url,
            'title' => Str::limit($meta['title'] !== '' ? $meta['title'] : $host, 300, ''),
            'description' => Str::limit($meta['description'], 1000, ''),
        ];

        if ($meta['image'] !== '') {
            try {
                $image = (string) UriResolver::resolve(new Uri($page['url']), new Uri($meta['image']));
                $thumb = $this->thumbFromUrl($image, $uploadBlob);

                if ($thumb !== null) {
                    $external['thumb'] = $thumb;
                }
            } catch (Throwable $e) {
                $this->skip('no thumbnail', $url, $e);
            }
        }

        return ['$type' => 'app.bsky.embed.external', 'external' => $external];
    }

    /**
     * An explicit card (BlueskyOptions::$linkCard): nothing is fetched but the
     * thumbnail, which may be an https URL or a local file.
     *
     * @param  array<string, mixed>  $card
     * @param  callable(string, string): ?array<string, mixed>  $uploadBlob
     * @return array<string, mixed>
     */
    public function fromCard(array $card, callable $uploadBlob): array
    {
        $uri = (string) ($card['uri'] ?? '');

        $external = [
            'uri' => $uri,
            'title' => Str::limit(trim((string) ($card['title'] ?? '')) ?: (string) parse_url($uri, PHP_URL_HOST), 300, ''),
            'description' => Str::limit(trim((string) ($card['description'] ?? '')), 1000, ''),
        ];

        $thumb = is_string($card['thumb'] ?? null) ? trim($card['thumb']) : '';

        if ($thumb !== '') {
            try {
                $blob = preg_match('~^https?://~i', $thumb) === 1
                    ? $this->thumbFromUrl($thumb, $uploadBlob)
                    : $this->thumbFromFile($thumb, $uploadBlob);

                if ($blob !== null) {
                    $external['thumb'] = $blob;
                }
            } catch (Throwable $e) {
                $this->skip('no thumbnail', $uri, $e);
            }
        }

        return ['$type' => 'app.bsky.embed.external', 'external' => $external];
    }

    /** Whether HTML pages can be parsed (ext-dom). */
    protected function canParse(): bool
    {
        return class_exists(DOMDocument::class);
    }

    /**
     * Parse the card fields out of an HTML page.
     *
     * @return array{title: string, description: string, image: string}
     */
    public function parse(string $html, ?string $charset = null): array
    {
        $empty = ['title' => '', 'description' => '', 'image' => ''];

        if (! class_exists(DOMDocument::class) || trim($html) === '') {
            return $empty;
        }

        if ($charset !== null && ! in_array(strtolower($charset), ['utf-8', 'utf8'], true)) {
            try {
                $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
                $html = is_string($converted) ? $converted : $html;
            } catch (Throwable) {
                // unknown charset: parse as is
            }
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return $empty;
        }

        $meta = [];

        foreach ($document->getElementsByTagName('meta') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $key = strtolower(trim($element->getAttribute('property') ?: $element->getAttribute('name')));
            $content = trim($element->getAttribute('content'));

            if ($key !== '' && $content !== '' && ! isset($meta[$key])) {
                $meta[$key] = $content;
            }
        }

        $titleTag = $document->getElementsByTagName('title')->item(0);
        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? ($titleTag !== null ? trim($titleTag->textContent) : '');

        return [
            'title' => (string) preg_replace('/\s+/u', ' ', $title),
            'description' => (string) preg_replace('/\s+/u', ' ', $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? ''),
            'image' => $meta['og:image:secure_url'] ?? $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? $meta['twitter:image:src'] ?? '',
        ];
    }

    // --- Thumbnails ---------------------------------------------------------------

    /**
     * @param  callable(string, string): ?array<string, mixed>  $uploadBlob
     * @return array<string, mixed>|null
     */
    protected function thumbFromUrl(string $url, callable $uploadBlob): ?array
    {
        $image = $this->fetch($url, $this->maxImageBytes, $this->imageTimeout, ['image/'], 'image/*');

        if ($image === null) {
            return null;
        }

        return $this->uploadThumb($image['body'], $image['type'], $uploadBlob);
    }

    /**
     * @param  callable(string, string): ?array<string, mixed>  $uploadBlob
     * @return array<string, mixed>|null
     */
    protected function thumbFromFile(string $path, callable $uploadBlob): ?array
    {
        if (! is_file($path) || ! is_readable($path) || (int) filesize($path) > $this->maxImageBytes) {
            return null;
        }

        $bytes = (string) file_get_contents($path);
        $mime = $this->sniffMime($bytes);

        return str_starts_with($mime, 'image/') ? $this->uploadThumb($bytes, $mime, $uploadBlob) : null;
    }

    /**
     * @param  callable(string, string): ?array<string, mixed>  $uploadBlob
     * @return array<string, mixed>|null
     */
    protected function uploadThumb(string $bytes, string $mime, callable $uploadBlob): ?array
    {
        if (strlen($bytes) > $this->thumbMaxBytes) {
            $bytes = $this->downscale($bytes);
            $mime = 'image/jpeg';

            if ($bytes === null) {
                return null;
            }
        }

        return $uploadBlob($bytes, $mime);
    }

    /**
     * Re-encode an oversized image as a JPEG of at most thumbMaxBytes, never
     * enlarging it: 1200 px wide at quality 80, then lower quality, then 800 px.
     * Null when no image library can read it or it stays too big.
     */
    protected function downscale(string $bytes): ?string
    {
        foreach ([[1200, 80], [1200, 60], [800, 60]] as [$width, $quality]) {
            $jpeg = $this->encodeJpeg($bytes, $width, $quality);

            if ($jpeg === null) {
                return null;
            }

            if (strlen($jpeg) <= $this->thumbMaxBytes) {
                return $jpeg;
            }
        }

        return null;
    }

    protected function encodeJpeg(string $bytes, int $maxWidth, int $quality): ?string
    {
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $image = @imagecreatefromstring($bytes);

            if ($image === false) {
                return null;
            }

            $width = imagesx($image);

            if ($width > $maxWidth) {
                $scaled = imagescale($image, $maxWidth);
                $image = $scaled !== false ? $scaled : $image;
            }

            // Flatten transparency onto white (a transparent PNG would turn black).
            $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

            ob_start();
            imagejpeg($canvas, null, $quality);

            return (string) ob_get_clean();
        }

        if (class_exists(Imagick::class)) {
            try {
                $image = new Imagick;
                $image->readImageBlob($bytes);

                if ($image->getImageWidth() > $maxWidth) {
                    $image->thumbnailImage($maxWidth, 0);
                }

                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality($quality);

                return $image->getImageBlob();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    // --- Fetching -----------------------------------------------------------------

    /**
     * GET an https URL with every redirect hop checked (and pinned), a byte
     * cap and a content-type gate. Null when the response is not usable.
     *
     * @param  list<string>  $types  Accepted content-type prefixes.
     * @return array{url: string, body: string, type: string, charset: ?string}|null
     */
    protected function fetch(string $url, int $maxBytes, int $timeout, array $types, string $accept): ?array
    {
        for ($hop = 0; ; $hop++) {
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                return null;
            }

            // A page is parsed from its first $maxBytes (its <head>), so a longer
            // one still downloads, up to a hard limit; an image must fit the cap.
            $abortAfter = str_starts_with($types[0] ?? '', 'image/') ? $maxBytes : max(5 * $maxBytes, 5242880);
            $response = $this->send($url, $timeout, $accept, $abortAfter);

            if (! $response->redirect()) {
                break;
            }

            $location = trim((string) $response->header('Location'));

            if ($location === '' || $hop >= self::MAX_REDIRECTS) {
                return null;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        if (! $response->successful()) {
            return null;
        }

        $header = strtolower(trim((string) $response->header('Content-Type')));
        $type = trim(explode(';', $header)[0]);
        $accepted = false;

        foreach ($types as $prefix) {
            $accepted = $accepted || str_starts_with($type, $prefix);
        }

        if (! $accepted) {
            return null;
        }

        $body = $this->readCapped($response, $maxBytes);

        // An image cut at the cap is unusable; a page cut at the cap still has its <head>.
        if ($body === null || ($body['truncated'] && ! str_starts_with($type, 'text/'))) {
            return null;
        }

        preg_match('/charset\s*=\s*"?([a-z0-9._-]+)/i', $header, $charset);

        return ['url' => $url, 'body' => $body['bytes'], 'type' => $type, 'charset' => $charset[1] ?? null];
    }

    protected function send(string $url, int $timeout, string $accept, ?int $abortAfter = null): Response
    {
        $options = $this->transferOptions($url, $abortAfter);
        $headers = ['Accept' => $accept];

        if ($this->userAgent !== null && $this->userAgent !== '') {
            $headers['User-Agent'] = $this->userAgent;
        }

        return Http::withHeaders($headers)
            ->connectTimeout(min(5, $timeout))
            ->timeout($timeout)
            ->withOptions($options)
            ->get($url);
    }

    /**
     * Guzzle options for one hop. Never 'stream': Guzzle hands streamed
     * requests to its StreamHandler, which ignores the 'curl' option, so the
     * CURLOPT_RESOLVE pin against DNS rebinding would be dropped silently.
     * The size is bounded instead by aborting the transfer once the announced
     * or received bytes pass $abortAfter.
     *
     * @return array<string, mixed>
     */
    protected function transferOptions(string $url, ?int $abortAfter = null): array
    {
        $options = ['allow_redirects' => false];

        if (PublicUrl::blocksPrivateNetworks()) {
            $options += PublicUrl::curlOptions(PublicUrl::resolvePinned($url, true));
        } else {
            PublicUrl::check($url, false);
        }

        if ($abortAfter !== null && $abortAfter > 0) {
            $options['on_headers'] = static function (ResponseInterface $response) use ($abortAfter): void {
                $length = $response->getHeaderLine('Content-Length');

                if (is_numeric($length) && (int) $length > $abortAfter) {
                    throw new RuntimeException("The link card resource is larger than {$abortAfter} bytes.");
                }
            };
            $options['progress'] = static function ($total, $downloaded) use ($abortAfter): void {
                if ((int) $total > $abortAfter || (int) $downloaded > $abortAfter) {
                    throw new RuntimeException("The link card resource is larger than {$abortAfter} bytes.");
                }
            };
        }

        return $options;
    }

    /** @return array{bytes: string, truncated: bool}|null */
    protected function readCapped(Response $response, int $maxBytes): ?array
    {
        try {
            $stream = $response->toPsrResponse()->getBody();
            $bytes = '';

            while (! $stream->eof() && strlen($bytes) <= $maxBytes) {
                $chunk = $stream->read(65536);

                if ($chunk === '') {
                    break;
                }

                $bytes .= $chunk;
            }

            $truncated = strlen($bytes) > $maxBytes;
            $stream->close();

            return ['bytes' => substr($bytes, 0, $maxBytes), 'truncated' => $truncated];
        } catch (Throwable) {
            return null;
        }
    }

    protected function sniffMime(string $bytes): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $bytes);
                finfo_close($finfo);

                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return 'application/octet-stream';
    }

    protected function skip(string $why, string $url, ?Throwable $e = null): void
    {
        try {
            Log::info('[SocialPoster] Bluesky link card skipped: '.$why.'.', array_filter([
                'url_host' => parse_url($url, PHP_URL_HOST),
                // Transport messages quote the URL; its query string may carry tokens.
                'error' => $e !== null ? preg_replace('/\?[^\s"\'<>]*/', '?[redacted]', $e->getMessage()) : null,
            ]));
        } catch (Throwable) {
            // logging is never worth failing a post over
        }
    }
}
