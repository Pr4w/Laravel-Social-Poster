# Bluesky

| | |
|---|---|
| Platform key | `'bluesky'` / `Platform::Bluesky` |
| Driver | `SocialPoster\Platforms\Bluesky\BlueskyDriver` (implements `SupportsFirstComment`) |
| API | AT Protocol XRPC on the account's PDS (`{pds}/xrpc/<nsid>`), `https://video.bsky.app` for video, the public AppView `https://public.api.bsky.app` for handle lookups. XRPC has no version number |
| Auth | OAuth access token bound with DPoP, a bearer session token, or (opt-in) an app password. Picked by `DefaultBlueskyAuthorizer`, or by your own authorizer |
| Media transfer | Upload: images and caption files go to the PDS as blobs, the video is streamed to the video service. Remote URLs are downloaded by the `MediaGateway` first |
| Options class | `SocialPoster\Platforms\Bluesky\BlueskyOptions`, or `RawOptions(Platform::Bluesky, [...])` (merged into the record) |
| First comment | Yes, as a reply to the new post |
| Async | Always at least two job runs: `publish()` prepares, the `create` step writes the record. Video adds an upload and processing polls |

Settings are read from `config('social.api.bluesky.*')`. The package's `config/social.php` is merged
into your app's config at boot, and a key that is set nowhere falls back to the driver default shown
on this page. A value in a config file always wins over the driver default. The code in
`src/Platforms/Bluesky/` is the reference.

---

## What you can publish

A post is one record (`app.bsky.feed.post`) with at most one embed. Images and a video can't be
mixed, and a link card can't sit next to media.

| Post | Media | Notes |
|---|---|---|
| Text | none | 300 graphemes and 3000 bytes. Links, `#hashtags` and `@mentions` become facets |
| Text with a link card | none | Built from the first `https://` link, or given explicitly (`linkCard`). `app.bsky.embed.external` |
| Images | 1 to 4 (`jpg`, `jpeg`, `png`, `webp`) | Shown as a grid (`app.bsky.embed.images`), with alt text and aspect ratio |
| Video | exactly 1 `.mp4`, alone | Uploaded to `video.bsky.app`, processed, then posted (`app.bsky.embed.video`). Optional WebVTT captions |
| Quote post | none, images or video | `BlueskyOptions::$quote`. With media it becomes `app.bsky.embed.recordWithMedia` |
| Reply / thread | any of the above | `BlueskyOptions::$replyTo` |
| Reply and quote controls | any of the above | `replyControl` (threadgate), `disableQuotes` (postgate), written atomically with the post |

Bluesky has no stories, reels, shorts or carousels. The driver always publishes immediately: there is
no draft or scheduled mode, and `PostResult::$state` is `published`. GIF images are refused (Bluesky
does not animate them): convert the GIF to an MP4 and set `videoPresentation: 'gif'`.

### Text

```php
use SocialPoster\Facades\SocialPoster;

SocialPoster::on('bluesky')
    ->using([
        'did' => 'did:plc:exampleaccount234567abcd',
        'access_token' => $accessToken,
        'dpop_private_key' => $dpopKeyPem,
    ])
    ->caption('Version 1.1 is out: https://example.com/changelog #release')
    ->post();
```

The link gets a link facet and, since the post has no media, a link card. `#release` gets a tag
facet.

### Images (1 to 4)

```php
use SocialPoster\ValueObjects\Media;

SocialPoster::on('bluesky')
    ->using($credentials)
    ->media(
        Media::image('/var/app/storage/shots/dashboard.png', altText: 'Dashboard in light mode'),
        Media::image('https://cdn.example.com/shots/settings.jpg', altText: 'Settings page'),
    )
    ->caption('Two screens from the new release.')
    ->post();
```

Every image gets an `alt` field (empty when you give none), and an `aspectRatio` when the
`MediaInspector` or `Media::$meta` knows its width and height.

### Video

```php
use SocialPoster\Platforms\Bluesky\BlueskyOptions;

SocialPoster::on('bluesky')
    ->using($credentials)
    ->media(Media::video('/var/app/storage/clips/demo.mp4', altText: 'Walkthrough of the editor'))
    ->caption('A two-minute walkthrough.')
    ->withOptions(new BlueskyOptions(videoCaptions: ['en' => '/var/app/storage/clips/demo.en.vtt']))
    ->post();
```

The video's alt text is sent when it is not blank. A `thumbnail` given to `Media::video()` is not
sent: Bluesky makes its own. A looping, silent clip (the replacement for a GIF):

```php
new BlueskyOptions(videoPresentation: 'gif');
```

Bluesky lets only accounts with a verified email post videos (see
[Error handling](#error-handling)).

### Link card

Without options, a text post whose caption contains an `https://` link gets a card for the first one.
To set the card yourself (nothing is fetched except the thumbnail):

```php
new BlueskyOptions(linkCard: [
    'uri' => 'https://example.com/changelog',
    'title' => 'Changelog',
    'description' => 'Everything that changed in 1.1.',
    'thumb' => '/var/app/storage/cards/changelog.jpg', // or an https:// URL
]);
```

`linkPreview: false` turns the automatic card off. See [Link cards](#link-cards).

### Reply or thread

```php
$first = SocialPoster::on('bluesky')
    ->using($credentials)
    ->caption('1/ What changed in 1.1')
    ->postNow()['bluesky'];

SocialPoster::on('bluesky')
    ->using($credentials)
    ->caption('2/ Uploads now resume across job runs.')
    ->withOptions(new BlueskyOptions(replyTo: [
        'uri' => $first->payload['uri'],
        'cid' => $first->payload['cid'],
    ]))
    ->post();
```

A reply needs the thread root as well as the parent. Without `replyTo['root']`, `publish()` reads the
parent with `com.atproto.repo.getRecord` and takes its `reply.root` (or the parent itself when it is a
top-level post), so a thread can be built one `replyTo` at a time. If the parent no longer exists,
`publish()` fails with `PermanentException(NotFound)`. A `replyTo['root']` you pass is used as is, and
no lookup is made.

### Quote post

```php
new BlueskyOptions(quote: ['uri' => $quotedUri, 'cid' => $quotedCid]);
```

A quote with no media is an `app.bsky.embed.record`. With images or a video it becomes
`app.bsky.embed.recordWithMedia`. A quote takes the embed slot, so no automatic link card is built, and
an explicit `linkCard` together with a quote is a validation error (`linkCard`: `A link card can't be
combined with a quoted post.`). A quote post may have an empty caption.

### Reply and quote controls

```php
new BlueskyOptions(
    replyControl: ['following', 'mention'], // [] = nobody can reply, null = anyone
    disableQuotes: true,
);
```

The post, its threadgate and its postgate are written in one `com.atproto.repo.applyWrites` call on
the same record key, so they exist together or not at all.

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `did` | yes | The account DID (`did:plc:…` or `did:web:…`), not a handle. It is the repo every write goes to, and the account's identity in idempotency keys. Missing or malformed → `PermanentException(Misconfigured)` with `missing_credentials`, before any request |
| `access_token` | one of the three modes | OAuth access token. With `dpop_private_key` it is sent with DPoP proofs, without one as a bearer token |
| `dpop_private_key` | with a DPoP-bound token | The P-256 private key the token is bound to: a PEM string (literal `\n` escapes accepted), a private JWK array (`kty`, `crv`, `x`, `y`, `d`), or that JWK as a JSON string |
| `token_type` | optional | When it is `DPoP` and no `dpop_private_key` (and no `access_jwt`) is given, validation fails under `credentials.dpop_private_key` and the authorizer refuses the token (`Misconfigured`) instead of sending it as a bearer |
| `access_jwt` | one of the three modes | A `createSession` `accessJwt`, sent as a bearer token. Wins over `access_token` without a key |
| `identifier` + `app_password` | one of the three modes | Handle, DID or email, plus an app password. Only when `api.bluesky.app_password_mode` is `true` |
| `pds` | recommended | The account's PDS origin, for example `https://pds.example.com`. `http(s)` only; a trailing `/` or `/xrpc` is removed. When missing or equal to `entryway`, the PDS is read from the DID document |
| `pds_url` | optional | Same as `pds`, used when `pds` is not a usable URL |
| `scopes` | optional | The granted scopes (a list, or a space- or comma-separated string). When present, validation and the comment step check for `atproto` |

With a custom authorizer (see below), only `did` is required: the authorizer decides what else it
needs, for example a `connection_id` of your own. The package itself never reads such keys.

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'did' => 'did:plc:exampleaccount234567abcd',
    'pds' => 'https://pds.example.com',
    'access_token' => $accessToken,
    'dpop_private_key' => $dpopKeyPem,
    'scopes' => 'atproto transition:generic',
]);

// Or keep the secrets out of the queue payload. A CredentialsResolver
// (config social.credentials_resolver) returns the real values on every job run:
Credentials::ref('social_account:42', ['did' => 'did:plc:exampleaccount234567abcd']);
```

`Credentials::ref()` refuses secret-looking keys (`access_token`, `access_jwt`, `app_password`,
`dpop_private_key`…): return those from your resolver.

### Auth modes

`DefaultBlueskyAuthorizer` is used when `config('social.authorizers.bluesky')` is unset (or names it).
It picks the mode from the credentials, in this order:

| Mode | Credentials | What is sent |
|---|---|---|
| OAuth DPoP | `access_token` + `dpop_private_key` | `Authorization: DPoP <token>` and a fresh `DPoP` proof (ES256) per request. The server nonce is remembered per origin for 300 s, and a `use_dpop_nonce` refusal is resent once with the new nonce |
| Bearer | `access_jwt`, or `access_token` without a key | `Authorization: Bearer <token>` |
| App password | `identifier` + `app_password`, with `app_password_mode` on | See [App passwords](#app-passwords) |

- A `dpop_private_key` without `access_token`, or a key that is not a usable P-256 key, fails as
  `Misconfigured` (`dpop_key_invalid` for the latter).
- No usable credentials, or an app password while `app_password_mode` is off, fail as `Misconfigured`.
- The video service never gets the account token and never gets DPoP. The driver asks the PDS for a
  service token (`com.atproto.server.getServiceAuth`, through the mode above) and sends it to
  `video.bsky.app` as a bearer token. `getJobStatus` is called without auth.
- Record lookups (`com.atproto.repo.getRecord`) are public and sent without auth. That is why a post
  that already exists is still recognised after its token expired.

**Your own authorizer.** Set `config('social.authorizers.bluesky')` to a class implementing
`SocialPoster\Contracts\AuthorizesRequests` (`request(Platform, Credentials): PendingRequest`), for
example one that delegates to a token manager which renews tokens on demand. The driver adds the URL,
the timeouts and `Accept: application/json`. Only `did` is then required, and the DPoP check of
`token_type` is skipped.

### Hosts

- **PDS.** Every XRPC call goes to the account's PDS. The driver takes `pds` (else `pds_url`). When
  neither is set, or the value equals `api.bluesky.entryway` (`https://bsky.social`), it reads the
  `#atproto_pds` service of the DID document: `did:plc` from `api.bluesky.plc_directory`
  (`https://plc.directory/<did>`), `did:web` from `https://<host>/.well-known/did.json`. Redirects are
  not followed, and a document or endpoint on a private address is ignored. The result is cached for
  `api.bluesky.pds_cache_ttl` (3600 s) in the default cache store.
- When `pds` is the entryway and the DID document cannot be read or names no PDS, the entryway is
  used as is (it proxies the XRPC calls). When `pds` is missing, an unreadable document is retried
  and a document without a PDS fails the post (see [Error handling](#error-handling)).
- **Video uploads need the real PDS.** The `uploadBlob` service token's audience is
  `did:web:<PDS host>` (a non-default port is written `%3A<port>`), and the video service refuses the
  entryway. With `pds` missing or equal to the entryway, a video post therefore always takes the PDS
  from the DID document (or the cache), never the entryway. If the document cannot be read,
  `publish()` throws a `TemporaryException` (context `pds_resolution`) and is retried. If it names no
  PDS, the post fails `Misconfigured` with `missing_credentials` `['pds']`.
- Pass `pds` for accounts on a self-hosted PDS. It saves the lookup, and the app-password login uses it
  (see below).

### App passwords

App passwords are off by default. Turn them on with `api.bluesky.app_password_mode` = `true`
(`SOCIAL_BLUESKY_APP_PASSWORD_MODE` in `config/social.php`).

- The authorizer calls `com.atproto.server.createSession` with `identifier` and `app_password` at
  `pds`, else `pds_url` (both normalised as above: `http(s)` only, trailing `/xrpc` removed), else the
  PDS named by the `did` credential's DID document (resolved as for the driver), else
  `api.bluesky.entryway`. A self-hosted account known only by its `did` therefore logs in at its own
  PDS. A DID document that cannot be read is raised (and retried) rather than logging in at the wrong
  host.
- The session is cached for `api.bluesky.session_ttl` (3600 s) under a key derived from the host, the
  identifier and the app password. Sessions cached by an earlier build under an unnormalised `pds_url`
  are not found, so one more session is created after the upgrade.
- When the PDS answers `ExpiredToken`, the cached session is dropped, a new one is created, and the
  request is resent once.
- Bluesky limits `createSession` to 30 per 5 minutes and 300 per day per account. The cache keeps
  normal use far below that.
- A main account password on an account with two-factor sign-in gets `AuthFactorTokenRequired`:
  create an app password in Bluesky (Settings → Privacy and security → App passwords).

### Scopes

`SocialPoster::requiredScopes()` returns them as groups:

| Feature | Scopes |
|---|---|
| `Feature::Publish` | `atproto` |
| `Feature::Comment` | `atproto` |
| `Feature::Video` / `Feature::Document` | none |

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::Bluesky, Feature::Publish); // [['atproto']]
```

- Every atproto OAuth grant includes `atproto`. Writing also needs `transition:generic` or granular
  `repo:` scopes. The driver does not list them, because their spelling varies and a stricter list
  would refuse valid grants. Request `atproto transition:generic` (or the granular scopes that cover
  `app.bsky.feed.post`, `app.bsky.feed.threadgate`, `app.bsky.feed.postgate`, blob uploads and
  `getServiceAuth`).
- When the credentials include `scopes` without `atproto`, validation reports
  `The Bluesky token is missing a permission needed to publish: grant atproto.` under
  `credentials.scopes`. Without `scopes`, nothing is checked. A missing write scope shows up at
  request time as `InsufficientScope` (`InsufficientPermissions`).
- atproto OAuth has no app review or client registration: the client is identified by the URL of its
  client metadata document ([atproto OAuth spec](https://atproto.com/specs/oauth)). Videos need an
  account with a verified email.

### Token lifetime

The package never obtains or refreshes tokens, and this driver does not read `expires_at`. OAuth
access tokens are short-lived (about 15 minutes on Bluesky's authorization server), while a queued
publish can span several job runs: the video upload, processing polls, retries and the comment delay.

- Bind a `CredentialsResolver` (`config('social.credentials_resolver')`) that returns a fresh token on
  every job run, or a delegating authorizer that renews tokens itself.
- With a custom resolver bound, a rejected token (a 401, `ExpiredToken`, an `invalid_token`
  challenge) is retried once, immediately, with re-resolved credentials. A second rejection is final.
  The comment job does the same. `AuthFactorTokenRequired` is never retried.
- Without one, the rejection is final (`PermanentException(InvalidToken)`), and `CredentialsRejected`
  is dispatched.

---

## Typed options

```php
use SocialPoster\Platforms\Bluesky\BlueskyOptions;

new BlueskyOptions(
    langs: null,
    linkPreview: true,
    linkCard: null,
    detectFacets: true,
    mentions: [],
    facets: [],
    shortenLinks: false,
    tags: [],
    selfLabels: [],
    replyControl: null,
    disableQuotes: false,
    replyTo: null,
    quote: null,
    videoCaptions: [],
    videoPresentation: null,
    extra: [],
);
```

| Option | Type | Default | Effect | Validation (error key) |
|---|---|---|---|---|
| `langs` | `?array` (list of strings) | `null` | `record.langs`, so language feeds pick the post up. Also copied to the first comment | At most 3, each a BCP-47-like tag such as `en` or `pt-BR` (`langs`) |
| `linkPreview` | `bool` | `true` | Build a card from the first `https://` link when the post has no media and no quote | — |
| `linkCard` | `?array` | `null` | An explicit card `{uri, title?, description?, thumb?}`. Used even when `linkPreview` is `false` | `uri` must be `https://` (`linkCard`). Not allowed with media or with a `quote` (`linkCard`) |
| `detectFacets` | `bool` | `true` | Detect links, `#hashtags` and `@mentions` | — |
| `mentions` | `array<string,string>` | `[]` | Handle ⇒ DID. Those handles skip the AppView lookup (`'alice.bsky.social' => 'did:plc:…'`, a leading `@` is fine) | Each value must start with `did:` (`mentions`) |
| `facets` | `list<array>` | `[]` | Raw `app.bsky.richtext.facet` entries appended as given. Byte offsets into the text as sent (after `shortenLinks`). Not used for the first comment | `index.byteStart` < `index.byteEnd` (integers) and at least one feature (`facets.N`) |
| `shortenLinks` | `bool` | `false` | Show each link as `host/path...` like the Bluesky app (path, query and fragment cut to 13 characters plus `...` when longer than 15). The facet keeps the full URL. Length limits apply to the shortened text | — |
| `tags` | `list<string>` | `[]` | Extra hashtags not shown in the text (`record.tags`). A leading `#` is removed, duplicates dropped | At most 8, no spaces, 64 graphemes / 640 bytes each (`tags`) |
| `selfLabels` | `list<string>` | `[]` | Content warnings (`record.labels`, `com.atproto.label.defs#selfLabels`) | Each one of `sexual`, `nudity`, `porn`, `graphic-media`, `!no-unauthenticated` (`selfLabels`) |
| `replyControl` | `?array` (list of strings) | `null` | `null`: anyone can reply. `[]`: nobody. Otherwise who can: `mention`, `following`, `followers` (also `mentions`, `mentioned`, `follower`), or a list AT URI `at://did:…/app.bsky.graph.list/…`. Written as a threadgate | At most 5 rules, each a known name or a list AT URI (`replyControl`) |
| `disableQuotes` | `bool` | `false` | Nobody can quote the post. Written as a postgate with `disableRule` | — |
| `replyTo` | `?array` | `null` | Post as a reply: `{uri, cid, root?: {uri, cid}}`. See [Reply or thread](#reply-or-thread) | `uri` must be a post AT URI `at://did:…/app.bsky.feed.post/…` and `cid` non-blank (`replyTo`, `replyTo.root`) |
| `quote` | `?array` | `null` | Quote a post: `{uri, cid}` | As for `replyTo` (`quote`) |
| `videoCaptions` | `array<string,string>` | `[]` | Language ⇒ WebVTT file (local path or URL). Uploaded as `text/vtt` blobs in the create step | At most 20, BCP-47-like keys, non-blank values (`videoCaptions`). Needs a video in the post. Each file must be non-blank and at most 20,000 bytes, checked when it is uploaded |
| `videoPresentation` | `?string` | `null` | `embed.presentation`: `default`, or `gif` (loops silently like a GIF) | `default` or `gif` (`videoPresentation`) |
| `extra` | `array` | `[]` | Raw fields merged into the post record | Not validated |

Constants on `BlueskyOptions`: `MAX_LANGS` = 3, `MAX_TAGS` = 8, `MAX_GATE_RULES` = 5,
`MAX_CAPTIONS` = 20, `MAX_CAPTION_BYTES` = 20000, `SELF_LABELS`, `REPLY_RULES`.

### Rich text

The API never detects links, hashtags or mentions: without facets they render as plain text. The
driver builds them (unless `detectFacets` is `false`):

- **Links**: found with the shared `Urls` rules. Trailing punctuation and an unbalanced `)` are left
  out; a balanced `(…)` inside the URL is kept. A bare domain with a real TLD (`example.com/docs`)
  stays as written in the text, and its facet points to `https://…`. Scheme URLs on IP hosts are also
  links. The facet covers the whole URL when its path or query is in another script or contains
  `{`, `}`, `^`, `` ` `` or `\` (for example `https://ja.wikipedia.org/wiki/東京`). A link still ends at
  whitespace, at `< > "`, at CJK or full-width punctuation (`。、「」…`), and where CJK text is glued
  to an ASCII path or host (`詳しくはhttps://example.com/news/123をご覧ください` links
  `https://example.com/news/123`).
- **Hashtags**: `#` or full-width `＃` at the start or after whitespace. Trailing punctuation is
  dropped; a tag of only digits or punctuation, or longer than 64 graphemes / 640 bytes, is not a tag.
  The facet range starts at the `#`.
- **Mentions**: `@handle.domain` at the start, after whitespace or after `(`. All handles are resolved
  at once with `com.atproto.identity.resolveHandle` on `api.bluesky.appview` (5 concurrent requests,
  `api.bluesky.mention_timeout` = 5 s), and cached for `api.bluesky.handle_cache_ttl` (86400 s). A
  handle the AppView does not know is cached as unknown for 600 s. An unresolved handle stays plain
  text and never fails the post.
- A link wins over a tag or mention written inside it (`#https://…`). Facets are sorted by start, and
  any facet overlapping an earlier one (or, at the same start, a longer one) is dropped. That applies
  to raw `facets` too: a raw facet that overlaps a detected one can be dropped.
- With `shortenLinks` and `detectFacets: false`, link facets are still built (for the shortened
  links), but no tags or mentions.

### The `extra` passthrough

`extra` is merged into the record. `$type`, `text`, `createdAt`, `facets`, `embed` and `reply` are
always removed from it. `langs`, `labels` and `tags` from `extra` are used only when the typed option
leaves them unset. Any other key (for example a custom field) is sent as given.

```php
new BlueskyOptions(langs: ['en'], extra: ['via' => 'my-app']);

// Without a typed class (the defaults apply: facets, link card):
use SocialPoster\ValueObjects\RawOptions;
use SocialPoster\Enums\Platform;

new RawOptions(Platform::Bluesky, ['langs' => ['fr']]);
```

The Bluesky driver ignores options bound to another platform. `extra` is never copied to the first
comment.

---

## Limits

### Text

- **Counting rule**: graphemes (`GraphemeCounter`): a flag, a family emoji or a letter with a
  combining mark counts 1. Links count in full, as written (or as shortened with `shortenLinks`).
- **Maximum**: 300 graphemes **and** 3000 UTF-8 bytes (`BlueskyDriver::MAX_GRAPHEMES`, `MAX_BYTES`).
  Both are fixed. They are checked on the trimmed text as it will be sent, and each has its own
  message under `caption`.
- **Text is required** unless the post has media or a quote:
  `Bluesky posts without media should at least contain text.`
- Invalid UTF-8 is replaced before counting and sending, so facet offsets stay valid.

### Media

| | Formats | Count | Size | Other |
|---|---|---|---|---|
| Image | `jpg`, `jpeg`, `png`, `webp` | 1 to 4 (`MAX_IMAGES`, fixed) | `api.bluesky.image_max_bytes` = 1000000 | Alt text up to `api.bluesky.alt_text_max` = 2000 graphemes |
| Video | `mp4` | exactly 1, alone | `api.bluesky.video_max_bytes` = 100000000 | 1 s to `api.bluesky.video_max_duration` = 180 s. Alt text as for images |
| Caption file | WebVTT | up to 20 per video | 20,000 bytes each (fixed) | — |
| Link-card thumbnail | any image | 1 | 1,000,000 bytes after downscaling (fixed) | See [Link cards](#link-cards) |

- The defaults are the production-proven values. The current lexicon allows 2,000,000-byte images and
  300,000,000-byte videos, and bsky.social allows videos of 10 minutes (not yet confirmed live, see
  check 8). To use them, set `image_max_bytes` = 2000000, `video_max_bytes` = 300000000 and
  `video_max_duration` = 600. A self-hosted PDS may refuse large files.
- No dimension or aspect-ratio limits are enforced. The aspect ratio is sent when it is known.
- `.heic`, `.heif`, `.avif`, `.bmp`, `.tif` and `.tiff` images pass validation when
  `social.transformer` is `ImageConvertingTransformer` (ext-gd or ext-imagick) and it can convert the
  format: they are converted to JPEG in the create step, before upload. Without it, validation
  suggests the conversion.
- A `.gif` image gets one error: `Bluesky doesn't animate GIF images: convert the GIF to an MP4 video
  (BlueskyOptions videoPresentation 'gif' makes it loop like one).`
- Documents are not supported.
- Size and duration come from the bound `MediaInspector` (`ffprobe` by default), completed by
  `Media::$meta`. A value that is unknown is not checked at validation.
- The image size is checked again on the bytes actually uploaded (after any conversion): an image over
  `image_max_bytes` fails with `PermanentException(MediaRejected)` and context `media_index`,
  `size_bytes`, `max_bytes`, even when validation was skipped. Images are never downscaled.
- Alt text longer than `alt_text_max` is a validation error under `media.N`. It is not cut when
  validation is skipped.

### Link cards

- Built in `publish()` from the first link written with `https://` (bare domains and `http://` links
  are skipped), when the post has no images, no video and no quote, and `linkPreview` is on.
- The page is fetched with TLS verification, at most 3 redirects followed one by one (`https` only),
  every hop checked with `PublicUrl` and, when private networks are blocked
  (`social.media.block_private_networks`), pinned to the checked address.
  Timeout `api.bluesky.link_preview_timeout` (10 s), at most `link_preview_max_bytes` (1,000,000) of
  HTML read, `text/html` or `application/xhtml+xml` only. `link_preview_user_agent` sets the
  User-Agent.
- Title: `og:title`, else `twitter:title`, else `<title>`, else the host, cut to 300 characters.
  Description: `og:description`, `twitter:description` or the `description` meta, cut to 1000.
  Image: `og:image:secure_url`, `og:image`, `og:image:url`, `twitter:image` or `twitter:image:src`,
  resolved against the final page URL.
- The thumbnail (`https` URL, or a local file for an explicit card) is read up to
  `link_preview_image_max_bytes` (5,000,000) within 15 s. Above 1,000,000 bytes it is re-encoded as a
  JPEG (1200 px wide at quality 80, then quality 60, then 800 px; never enlarged) with GD or Imagick,
  or dropped.
- Everything is best effort: a failure gives no card, or a card without a thumbnail, and is logged at
  info level (`[SocialPoster] Bluesky link card skipped: …`). The post still goes out with its link
  facet. Parsing needs ext-dom; without it no automatic card is built (an explicit `linkCard` still
  works).

### Quotas

- **PDS write budget**: 5,000 points per hour and 35,000 per day per account; a create costs 3 points
  ([rate limits](https://docs.bsky.app/docs/advanced-guides/rate-limits)). A 429 whose reset is beyond
  `max_rate_limit_wait` (such as the daily budget) fails as `PermanentException(RateLimited)` with
  `retry_at`. See [Rate limits](#rate-limits).
- **App-password logins**: 30 per 5 minutes and 300 per day.
- **Videos**: about 25 videos or 10 GB a day per account on bsky.social, enforced by the video service.
  The driver asks `app.bsky.video.getUploadLimits` before uploading (`check_upload_limits`).

---

## First comment

`->withComment('...')` posts a reply to the new post once it is live.

```php
SocialPoster::on('bluesky')
    ->using($credentials)
    ->caption('Release notes are up.')
    ->withComment('Full changelog: https://example.com/changelog')
    ->post();
```

| Condition | Behaviour |
|---|---|
| Length | 300 graphemes and 3000 bytes. Checked at `validate()` under `comment` |
| Scopes | `atproto`. If the credentials include `scopes` without it, the comment is skipped (`CommentSkipped`, reason `missing_scope`). Without `scopes`, it is attempted |
| Delay | `social.comment_delay` (60 s). Bluesky sets no minimum. On the `sync` queue connection the reply is sent right away |
| Parent failed, scheduled or draft | No comment |
| Thread | `root` is the parent's thread root (the post's own `reply_root` when it was itself a reply), `parent` is the post. Both come from the parent's payload (`uri`, `cid`, `reply_root`); when the `cid` is missing, the parent is read with `getRecord` |
| Record | `text`, its facets (links, tags and mentions detected as for the post; no raw `facets`, no shortening), `reply`, and `langs` from the options. No embed, gates, labels, tags or `extra` |
| Record key | Derived from the parent's key and the text (`Tid::derive()`): a key 1 to 2 s after the parent's. A retried comment job targets the same record |

A lost response or a 5xx is followed by a `getRecord` lookup on that key. If the reply exists, the
result is the existing reply with `recovered = true` in its payload; if not, it is sent once more
with the same key. A refused create that could hide an existing record is looked up the same way. A
2xx without a `uri` is looked up, else it is a `PermanentException(OutcomeUnknown)`. A second lost
connection is left to the comment job's retries, which keep the same key. When the parent's key is
not a TID, a random key is used and this protection holds within one call only.

Errors are mapped as for the post. The result's id is the reply's `at://` URI, its url the permalink,
and its payload holds `uri`, `cid`, `did`, `rkey` (and `recovered`). Outcomes arrive as
`CommentPublished`, `CommentFailed` or `CommentSkipped`. The comment job makes up to
`social.comment_tries` (5) attempts on temporary errors.

---

## Async behaviour

A publish is a small state machine. Each step is one job run, and the job carries the state from one
run to the next. `PostProcessing` fires for every wait except the one before the create.

| Phase (`state['phase']`) | What happens | Next |
|---|---|---|
| `publish()` | Checks the credentials, picks the record key (a TID), builds the text and facets (mentions resolved), looks up the thread root of a `replyTo` without `root`, builds the link card (page fetch and thumbnail blob). Video: `getUploadLimits` pre-flight, service token, upload to `app.bsky.video.uploadVideo` | `create` (commit), or `await_video` |
| `await_video` | `GET app.bsky.video.getJobStatus?jobId=…` (no auth). A blob: done. `JOB_STATE_FAILED`: fails (see below). Anything else: wait again | `await_video`, or `create` |
| `create` | Downloads and converts the images, uploads them and the caption files as blobs, stamps `createdAt`, then writes the record: `com.atproto.repo.createRecord`, or `applyWrites` with gates. The only non-idempotent call (the commit phase) | Done |

`verify_post` (runs `create` as a recovery) and `restart` (runs `publish()` again) are accepted as
phase names too. There was no Bluesky driver in v1.0, so there are no older continuations.

What each `Pending` means:

- **Commit** (`phase` `create`, recheck 0): nothing has been written to the account yet. The state
  holds `rkey`, `text`, `facets`, and, when they apply, `reply_root`, `embed` (the link card) and
  `video.blob`. `createdAt` is not in the state: it is stamped when the record is written, so video
  processing, a queue backlog or retries never backdate the post in feeds. On the queue, the create is
  a second job run dispatched without delay. `postNow()` does not sleep between the two.
- **Video processing** (`await_video`, not committed): the video is on the video service, and no post
  exists. The recheck is 5 s for the first 6 polls, 10 s up to the 18th, then 30 s, and 5 s again
  once the job reports 90 % progress. The state holds `job_id`, `polls` and `deadline`.
- When the upload answer already carries the blob (the service has processed the same file before,
  even as an `already_exists` error), the post goes straight to `create`.

How long it takes: text, link and image posts need no waiting (two runs back to back; images are
uploaded in the second). A video needs its upload (within `upload_timeout`, 300 s) plus the
processing time. Bluesky does not publish processing times. The driver sets the deadline to the end
of the upload plus `api.bluesky.video_max_wait` (1800 s, at least 60), and that deadline replaces
`social.async.max_wait` for the chain. Past it, the publish fails with `Timeout`; the post was never
created. A video whose processing finishes on the last poll, at the deadline, is still posted: the job
runs the ready `create` step once.

`postNow()` waits up to `social.sync.max_wait` (120 s), then hands the publish to the queue. The result
is then a success with state `processing`, and the final outcome arrives as `PostPublished` or
`PostFailed`. On the `sync` queue connection there is no queue to hand over to, so the publish times
out instead.

**Remote media is downloaded where it is used.** With the default `LocalMediaGateway`, a remote video
is downloaded in `publish()`, and remote images and caption files in the `create` run, within
`social.download_timeout` (120 s). Temp files are deleted at the end of each run. Images are uploaded
in the same run as the record because the PDS deletes blobs that no record references.

Timeouts: PDS calls use `api.bluesky.timeout` (`social.http_timeout`, 60 s) and
`api.bluesky.connect_timeout` (10 s). Blob uploads use `api.bluesky.blob_timeout` (60 s). The video
upload uses `api.bluesky.upload_timeout` (`social.upload_timeout`, 300 s). Job-status polls,
`getUploadLimits` and `getRecord` lookups use 30 s. DID documents use 10 s (5 s to connect), and
`createSession` 30 s.

### Results

| Field | Value |
|---|---|
| `platformPostId` | The record's AT URI, `at://<did>/app.bsky.feed.post/<rkey>` (`idKind` = `at_uri`) |
| `url` | `{permalink_host}/profile/<did>/post/<rkey>` (`https://bsky.app/...`). The DID survives handle changes |
| `state` | `published` |
| `payload` | `uri`, `cid`, `did`, `rkey`, `validation_status` (from the PDS), `reply_root` (for a reply), `recovered` (when the post was found by a lookup) |

---

## Error handling

XRPC errors are `{error, message}`. The exception context holds `status`, `http_status`, `error` (the
XRPC code) and `surface` (`pds`, or `video` for `video.bsky.app` answers). The checks run in this
order:

| Bluesky answer | Exception | `FailureReason` | Action |
|---|---|---|---|
| `use_dpop_nonce` (code or `WWW-Authenticate`) after the middleware already resent once | Temporary, 5 s | `ServerError` | Retried. Context `dpop_nonce` |
| `invalid_dpop_proof` | Permanent | `Misconfigured` | Wrong key for the token, or the server clock is off. Context `dpop_proof_invalid` |
| `AccountTakedown`, `AccountDeactivated`, `RepoTakendown`, `RepoDeactivated`, `RepoSuspended` (any status, the PDS sends a 401) | Permanent | `AccountRestricted` | The account must be restored on Bluesky. No re-resolve, no `CredentialsRejected`, no "reconnect" message |
| `ExpiredToken`, `InvalidToken`, `AuthenticationRequired`, `AuthMissing`, `BadJwt`, `BadJwtSignature`, any other 401, or an `invalid_token` challenge | Permanent | `InvalidToken` | Reconnect or refresh (one re-resolve retry when a resolver is bound). Context `reresolvable`, and `token_expired` for `ExpiredToken` |
| `AuthFactorTokenRequired` | Permanent, terminal | `InvalidToken` | A main password with two-factor sign-in: use an app password |
| Video service: 5xx | Temporary (`Retry-After`, else 60 s) | `ServerError` | Retried |
| Video service: a message about the email | Permanent | `AccountRestricted` | Verify the email in Bluesky. Context `email_unverified` |
| Video service: a message with `daily`, `per day`, `today`, `quota` or `24h` | Permanent | `RateLimited` | Daily video limit. Context `daily_limit`, `retry_after_hint` (from `Retry-After` or a reset header, else 86400), `retry_at` |
| Video service: `too large`, `too big`, `too long`, `file size`, `exceeds the maximum`, `duration` | Permanent | `MediaRejected` | Shorten or compress the video |
| Video service: `limit` together with `video`, `bytes` or `upload`, but not `rate limit` | Permanent | `RateLimited` | Daily video limit, as above. A bare "Rate Limit Exceeded" goes to the 429 row |
| 403, `Forbidden`, `InsufficientScope` | Permanent | `InsufficientPermissions` | The session lacks a permission: reconnect with the write scope |
| 429, `RateLimitExceeded` | Temporary or Permanent | `RateLimited` | See [Rate limits](#rate-limits) |
| 5xx, `UpstreamFailure`, `UpstreamTimeout`, `NotEnoughResources`, `InternalServerError` | Temporary (`Retry-After`, else 60 s) | `ServerError` | Retried |
| `BlobTooLarge`, 413 | Permanent | `MediaRejected` | A file is over the PDS limit |
| `InvalidMimeType`, `BlobNotFound` | Permanent | `MediaRejected` | `BlobNotFound` on the create restarts the publish first (below) |
| `InvalidRecord` | Permanent | `InvalidContent` | The record failed the lexicon (for example text over 300 graphemes) |
| `RepoNotFound` | Permanent | `Misconfigured` | Check the `did` and `pds` credentials |
| `InvalidRequest`, `InvalidSwap` | Permanent | `Unknown` | `Bluesky refused this post (<message>).` |
| anything else | base table | 404 `NotFound`; 408/425 `ServerError` (Temporary); other statuses `Unknown` | — |

The video-service message rules run only for `video.bsky.app` answers (upload, job status,
pre-flight), never for the PDS. They are matched on the message because the service has no specific
codes (open live check 5).

### Rate limits

The wait is read from `Retry-After`, then `ratelimit-reset` (and the other reset headers). The
window comes from `ratelimit-policy` (`35000;w=86400` → 86400 s).

- **Reset within `api.bluesky.max_rate_limit_wait` (3600 s)**: `TemporaryException(RateLimited)` that
  waits until the reset. Without a reset header the wait is 300 s.
- **Reset further away**, such as the PDS daily write budget or the `createSession` daily limit:
  `PermanentException(RateLimited)`, never waited out in the queue. The context holds `retry_at`
  (ISO-8601 UTC, also in the message: `It resets at 2026-11-20T08:00:00Z: try again after that.`),
  `retry_after_hint` (seconds), `daily_limit` (true when the policy window is a day or more, or,
  without a policy, when the reset is more than an hour away) and `rate_limit_window` (seconds, when
  the response has a `ratelimit-policy`). Re-dispatch the post after `retry_at`.
- Earlier builds retried such a 429 in the queue every hour until the tries ran out.

### App-password login errors

`createSession` failures are raised by the authorizer (context `create_session`):

| Answer | Exception | `FailureReason` |
|---|---|---|
| 429 / `RateLimitExceeded` | as in [Rate limits](#rate-limits) | `RateLimited` |
| 5xx | Temporary (`Retry-After`, else 60 s) | `ServerError` |
| `AuthFactorTokenRequired` | Permanent, terminal | `InvalidToken` |
| `AccountTakedown`, `AccountDeactivated` | Permanent | `AccountRestricted` |
| 401, `AuthenticationRequired`, `AuthRequired`, `AuthMissing`, `InvalidToken` | Permanent, terminal ("check the identifier and app password") | `InvalidToken` |
| Any other refusal (404, 400 `InvalidRequest`, 403…) | Permanent, not terminal. The message names the host it logged in at | `Misconfigured` |
| 2xx without `accessJwt` | Temporary, 60 s | `ServerError` |

A refusal that is not about the password (a wrong URL, a malformed request) never disables a valid
connection: it is `Misconfigured`, not `InvalidToken`.

### Other failures raised by the driver

| Situation | Exception / reason |
|---|---|
| No `did`, or no token, `access_jwt` or `app_password` (without a custom authorizer) | Permanent `Misconfigured` (`missing_credentials`) |
| `did` is not a DID (a handle, for example) | Permanent `Misconfigured` (`missing_credentials` `['did']`) |
| DID document unreachable, 5xx or 429 | Temporary (`Network`, or `ServerError` after `Retry-After` / 60 s), context `pds_resolution`. Not raised when `pds` is the entryway, except for videos |
| No PDS in the DID document, a 4xx for it, a private address, or a DID method other than `plc`/`web` | Permanent `Misconfigured` (`missing_credentials` `['pds']`). With `pds` = the entryway, non-video posts use the entryway instead |
| The `replyTo` parent no longer exists | Permanent `NotFound` (`parent_id`) |
| Image over `image_max_bytes` at upload | Permanent `MediaRejected` (`media_index`, `size_bytes`, `max_bytes`) |
| Media file unreadable | Permanent `MediaRejected`. A remote URL that no `MediaGateway` downloaded: `Misconfigured` |
| Caption file unreadable, empty or over 20,000 bytes | Permanent `InvalidContent` (`caption_lang`) |
| `BlobNotFound` (or a 400 "Could not find blob") on the create | Temporary `NotFound` with `restart`: the job drops the state and publishes again from scratch, at most `api.bluesky.blob_restarts` (1) times (and `social.max_restarts`). Then Permanent `MediaRejected` |
| Video job `JOB_STATE_FAILED` | Permanent `MediaRejected` (`Bluesky could not process the video (…).`, plus an MP4/H.264 hint for `validation_failure`), or the email / daily-limit reasons above when the job's message says so. Context `job_state`, `job_id`, `error`, `failure_code` |
| `getUploadLimits` says `canUpload: false` | The email / daily-limit / size reasons above, else the daily video limit. Context `upload_limits` |
| 2xx without a service token, blob or video job id | Temporary `ServerError` (safe to retry) |
| 2xx create without a `uri`, and the lookup finds no record | Permanent `OutcomeUnknown` with `possibly_published = true` |
| Video processing past the deadline | `Timeout` from the job. Nothing was posted |
| Unknown phase, an invalid record key, or `await_video` without `job_id` | Permanent `Unknown` |
| Connection lost (no response) | Handled by the job as `Network` |

**Failures before the create is sent.** In the `create` step, everything up to sending
`createRecord` / `applyWrites` (image download or conversion, blob and caption uploads, the PDS
lookup, the app-password login) is flagged `possibly_published = false` and `request_sent = false`.
When such a failure is final, the job reports `PostFailed` with `possiblyPublished = false` and
releases the idempotency key, so the same post can be dispatched again with the same key. A temporary
one is retried, and the failure itself never makes the retry a recovery.

`FailureReason::category()` groups the reasons for your UI: `retry` (RateLimited, ServerError,
Timeout, ProcessingTimeout, Network), `reconnect` (InvalidToken, InsufficientPermissions;
`requiresReconnect()` is true for both), `user_fix` (AccountRestricted, MediaRejected,
InvalidContent…), `operator` (Misconfigured, QuotaExceeded, Unknown), `verify` (OutcomeUnknown) and
`not_found`. An exception flagged `possibly_published` reports `verify` through `errorSummary()`,
whatever its reason.

Every failed response is logged (`[SocialPoster] bluesky API error: ...`) with tokens redacted and the
`Authorization` and `DPoP` headers dropped: temporary failures at warning level, permanent ones at
error level. Turn logging off with `social.error_logging`, or route it with
`social.error_log_channel`.

---

## Duplicate safety

Writing the record is the only call that can create a post. The driver makes it safe to repeat by
choosing the record key itself:

1. `publish()` picks the record key (a TID: 13 characters of sortable base32 encoding the time in
   microseconds and a random clock id) and puts it in the state. The state travels with the
   continuation and, with an atomic idempotency store, is recorded before the create runs.
2. The `create` step writes the record under that key. The PDS refuses a second create on a key that
   already exists, so repeating the step can never make a second post.
3. When the outcome of a run is ambiguous, the job runs the step again with `_recovering` set. That
   covers a worker that died or timed out, a queue redelivery, a store record still marked
   committing, and a lost connection or 5xx after the request may have been sent. The driver then
   **looks the post up first**: `GET com.atproto.repo.getRecord` with `repo` = `did`,
   `collection` = `app.bsky.feed.post` and `rkey` = the state's key, on the account's PDS, without
   auth.

| Lookup result | What happens |
|---|---|
| Found | `Published` with the real `uri` and `cid`, and `recovered = true` in the payload. Nothing is written |
| Not found | The record is written with the same key. If the PDS then refuses it with a clear 4xx, no post exists: the failure carries `possibly_published = false` and the key is released. A 5xx may still have created it and keeps the job's verdict |
| The lookup fails (5xx, lost connection…) | The error is raised as is. The job retries, and the retry is still a recovery. Nothing is written. A permanent lookup error ends the publish with `possiblyPublished` |

There is no matching on text or time: the key identifies the post exactly. A recovered post has its id
and url, and gets its first comment.

Outside recovery, the driver also looks before it reports:

- **A refused create that could hide an existing record** (any status from 400 up, except 401, 403,
  413 and 429, and except auth, account, rate-limit, blob, scope, `InvalidRecord`, DPoP and
  `RepoNotFound` codes; this covers a 5xx and an `InvalidRequest`/`InvalidSwap` for a key already
  taken) is followed by one `getRecord`. Found → `Published` with `recovered = true`. Not found → the
  original error.
- **A 2xx without a `uri`** is looked up too. Not found → `PermanentException(OutcomeUnknown)` with
  `possibly_published = true`.
- **A lost connection on the create** is not caught by the driver: the job turns it into
  `TemporaryException::connection()` and runs the step again with `_recovering` when the request may
  have been sent.
- **Gates** are part of the same `applyWrites` commit as the post, so a post never exists without its
  threadgate or postgate.
- **A restart** after `BlobNotFound` picks a new key. That is safe: the PDS refused the create.

What this means for your code:

- `PostFailed` with `possiblyPublished === true` (reason `OutcomeUnknown`) is rare here, but means:
  check the account before posting again. The store keeps the record, and `reconcile()`, given the
  original post, runs the `create` step again with `_recovering`: the lookup decides.
- `PostFailed` with `possiblyPublished === false` means nothing was posted, and the key was released.
- Keep an idempotency store bound (the default `CacheIdempotencyStore`, or
  `DatabaseIdempotencyStore`) and give the publish your own `->idempotencyKey()`. The record key
  protects repeats of a step; the idempotency key protects a second dispatch of the same post, which
  would otherwise get a new record key.

---

## Configuration (`social.api.bluesky`)

Every key is optional. The default is the driver's own, used when no config file sets the key.

| Key | Default | Effect |
|---|---|---|
| `entryway` | `https://bsky.social` (env `SOCIAL_BLUESKY_ENTRYWAY` in `config/social.php`) | A `pds` equal to it is resolved from the DID document; app-password login host when neither `pds` nor a `did` is given |
| `plc_directory` | `https://plc.directory` | Where `did:plc` documents are read |
| `pds_cache_ttl` | `3600` | Seconds a resolved PDS is cached (at least 60) |
| `appview` | `https://public.api.bsky.app` | Handle lookups for mentions, and `getRecord` for a post whose PDS cannot be found |
| `mention_timeout` | `5` | Timeout (s) of a handle lookup |
| `handle_cache_ttl` | `86400` | Seconds a resolved handle is cached |
| `video_service` | `https://video.bsky.app` | Video upload and job status |
| `video_service_did` | `did:web:video.bsky.app` | Audience of the `getUploadLimits` service token |
| `check_upload_limits` | `true` | Run the `getUploadLimits` pre-flight before a video upload (best effort: its own failures are ignored) |
| `video_max_bytes` | `100000000` | Video size limit |
| `video_max_duration` | `180` | Video duration limit (s) |
| `video_max_wait` | `1800` | Processing deadline (s) after the upload (at least 60) |
| `image_max_bytes` | `1000000` | Per-image limit, at validation and before upload |
| `alt_text_max` | `2000` | Alt text limit (graphemes), images and video |
| `blob_timeout` | `60` | Timeout (s) of `uploadBlob` |
| `blob_restarts` | `1` | Restarts after `BlobNotFound` |
| `permalink_host` | `https://bsky.app` | Host of post and comment URLs |
| `link_preview_timeout` | `10` | Timeout (s) of the link-card page fetch |
| `link_preview_user_agent` | `null` | User-Agent of link-card fetches (null: the HTTP client's default) |
| `link_preview_max_bytes` | `1000000` | HTML read for a link card |
| `link_preview_image_max_bytes` | `5000000` | Link-card image read before downscaling |
| `app_password_mode` | `false` (env `SOCIAL_BLUESKY_APP_PASSWORD_MODE`) | Allow `identifier` + `app_password` |
| `session_ttl` | `3600` | Seconds an app-password session is cached (at least 60) |
| `max_rate_limit_wait` | `3600` | A 429 whose reset is further away fails as `RateLimited` with `retry_at`. Also used for `createSession` |
| `timeout` | `social.http_timeout` (60) | Read timeout (s) of PDS calls |
| `connect_timeout` | `10` | Connect timeout (s) |
| `upload_timeout` | `social.upload_timeout` (300) | Read timeout (s) of the video upload |

`timeout`, `connect_timeout` and `upload_timeout` are not listed in the package's
`config/social.php`. Add them under `api.bluesky` in your config file to change them.

Related global keys:

- Credentials: `social.credentials_resolver`, `social.authorizers.bluesky` (unset or
  `DefaultBlueskyAuthorizer::class`: the default authorizer).
- Media: `social.transformer`, `social.upload_timeout` (300), `social.download_timeout` (120),
  `social.media.block_private_networks`, `social.media.max_download_bytes`.
- Waits: `social.sync.max_wait` (120), `social.max_recheck_after` (900).
- Comments: `social.comment_delay` (60), `social.comment_tries` (5).
- Retries: `social.tries` (5), `social.backoff`, `social.max_restarts` (3).
- Idempotency and logging: `social.idempotency`, `social.error_logging`, `social.error_log_channel`.
- Caches: resolved PDSes, handles, app-password sessions and DPoP nonces use the default cache store.

---

## Extending the driver

New in 1.1, so nothing here has an earlier version to stay compatible with:

- `writeRequest(PreparedPost $post, string $did, string $rkey, array $record): array` returns
  `[PendingRequest, string $url, array $body]` for `createRecord`, or `applyWrites` when the post has
  gates. Building it may already talk to the network (PDS lookup, app-password login); sending it is
  the create.
- `beforeCreate(SocialPosterException $e): SocialPosterException` flags a failure as
  `possibly_published = false` and `request_sent = false`. Wrap anything an override does before the
  create is sent.
- `pds(PreparedPost $post, bool $entrywayFallback = true): string`. With `false`, a failed DID lookup
  is thrown instead of falling back to the entryway (used for the video service token).
- `DefaultBlueskyAuthorizer::sessionFailed(Response $response, string $host)` classifies a refused
  `createSession`.
- `SocialPoster\Platforms\Bluesky\Xrpc` (`pdsUrl()`, `rateLimited()`, `policyWindow()`) is
  `@internal`: shared by the driver and the authorizer, not a public API.
- Other hooks: `classifyError($response, string $surface = 'pds')`, `recordFor()`, `embedFor()`,
  `defaultAuthorizer()`, `richText()`, `linkCardResolver()`, `videoRecheck()`.

---

## Known limitations

- No `app.bsky.embed.gallery`: at most 4 images, shown as a grid.
- Oversized images are refused, never downscaled. GIF images are refused.
- Only `.mp4` video, one per post, never with images. Video thumbnails are not sent.
- An explicit `linkCard` can't be combined with media or a quote (validation error).
- Link cards and mentions are best effort. An automatic card needs ext-dom to read the page's tags,
  and GD or Imagick to shrink a thumbnail over 1 MB.
- Raw `facets` that overlap detected links, tags or mentions can be dropped.
- Tokens are never refreshed and `expires_at` is not checked. OAuth tokens live minutes: bind a
  `CredentialsResolver` or a delegating authorizer for queued posts.
- The video-service refusals (unverified email, daily quota, size) are recognised by their message
  text, which Bluesky does not document.
- The `getUploadLimits` pre-flight is best effort and can be turned off.
- The default limits (1 MB images, 100 MB / 180 s video) are below what bsky.social accepts today.
- `extra` is sent as given and never validated.
- A first comment on a parent whose record key is not a TID gets a random key, so a retried comment
  job could reply twice. Posts made by this driver always have TID keys.

---

## Open live checks

Fixtures cover these behaviours, but they have not all been confirmed against the live API. Run
`tests/Live/BlueskyLiveTest.php` with `SOCIAL_LIVE_BLUESKY_DID` and `SOCIAL_LIVE_BLUESKY_ACCESS_JWT`,
or `SOCIAL_LIVE_BLUESKY_IDENTIFIER` + `SOCIAL_LIVE_BLUESKY_APP_PASSWORD` (optional:
`SOCIAL_LIVE_BLUESKY_PDS`). The check that posts (and then deletes) also needs `SOCIAL_LIVE_PUBLISH=1`.
See `tests/Live/README.md`.

1. The exact error for `createRecord` on a record key that already exists (400 `InvalidRequest` or
   `InvalidSwap` is assumed; both lead to a lookup). Automated.
2. The `BlobNotFound` name and message for a garbage-collected blob (handled: the code, or a 400
   "Could not find blob"). Manual.
3. DPoP against a bsky.social PDS: the `use_dpop_nonce` 401 with `DPoP-Nonce`, the resend, nonce reuse
   per origin, and whether the OAuth token works on the resolved PDS host. Manual.
4. `getServiceAuth` with OAuth tokens (`transition:generic` and granular scopes), for
   `com.atproto.repo.uploadBlob` with `aud did:web:<PDS host>` and for
   `app.bsky.video.getUploadLimits` with `aud did:web:video.bsky.app`. Manual.
5. The video service's real status codes and messages for an unverified email and the daily quota,
   and the HTTP status of `already_exists`. Manual.
6. Whether the derived reply key (a TID 1 to 2 s after the parent's, with a later `createdAt`) is
   accepted by the PDS and indexed normally by the AppView. Manual.
7. Video alt text over 1000 graphemes, caption blobs sent as `text/vtt`, `presentation: gif`, and a
   threadgate/postgate written with `applyWrites` on the same key. Manual.
8. The current limits (300 MB / 10 min video and 2 MB images on bsky.social) and self-hosted PDS
   behaviour. Manual.
9. `createSession` on the PDS and the real `accessJwt` lifetime (automated, partly), and the same on the
   entryway plus the `ExpiredToken` re-login (manual).
10. plc.directory accepting the raw `did:plc` path, and the AppView serving `getRecord` for other
    repos. Automated.
11. Link cards on real sites: charsets, `og:image:secure_url`, CDN redirects. Manual.
