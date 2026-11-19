# LinkedIn

| | |
|---|---|
| Platform key | `'linkedin'` / `Platform::LinkedIn` |
| Driver | `SocialPoster\Platforms\LinkedIn\LinkedInDriver` (implements `SupportsFirstComment`, and the deprecated `SupportsComments`) |
| API | LinkedIn versioned REST API at `https://api.linkedin.com/rest` (Posts, Images, Videos, Documents, Comments). Every REST call sends `LinkedIn-Version: 202605` (`api.linkedin.version`) and `X-Restli-Protocol-Version: 2.0.0`; byte uploads go to the upload URLs LinkedIn returns. The base URL is fixed |
| Auth | OAuth 2.0 bearer token of a member. Posts as that member (`urn:li:person:…`) or as a page the member manages (`urn:li:organization:…`) |
| Media transfer | Upload. The driver sends the bytes itself; remote URLs are downloaded by the `MediaGateway` first |
| Options class | `SocialPoster\Platforms\LinkedIn\LinkedInOptions` (+ `LinkedInMention`, `LinkedInPoll`, `LinkedInArticle`), or `RawOptions(Platform::LinkedIn, [...])` for the raw passthrough only |
| First comment | Yes, as a comment by the author, at least 60 s after the post |
| Async | Text, polls, reshares, link cards and images: no processing, the post is created in a separate commit step. Video: staged multi-part upload, then processing polls. Document: upload, then processing polls |

Every limit and setting on this page is read from `config('social.api.linkedin.*')` with the default
shown, so nothing depends on the published config file. The code in `src/Platforms/LinkedIn/` is
the reference.

---

## What you can publish

A post is one of the following. Media, a link card, a poll and a reshare can't be combined.

| Post | Media | Notes |
|---|---|---|
| Text | none | Up to 3000 characters. Links stay bare links: LinkedIn does not build previews for API posts |
| Link card | none | `LinkedInOptions::$article`, or `autoLinkCard: true` to build it from the caption |
| Poll | none | `LinkedInOptions::$poll`, 2 to 4 options |
| Reshare | none | `LinkedInOptions::$reshareOf`. The caption may be empty |
| Image | 1 image (`jpg`, `jpeg`, `gif`, `png`) | Alt text supported |
| Multi-image | 2 to 20 images | Images only. Alt text per image |
| Video | exactly 1 `.mp4` | Optional thumbnail, title and alt text. Staged upload and processing |
| Document | exactly 1 `.pdf`, `.doc`, `.docx`, `.ppt` or `.pptx` | Shown as a document post. Title from `->title()` or the file name |

LinkedIn has no stories, reels or shorts. The driver always publishes immediately
(`lifecycleState: PUBLISHED`): there is no draft or scheduled mode, and `PostResult::$state` is
`published`. The id is the post URN (`urn:li:share:…` or `urn:li:ugcPost:…`, `PostResult::$idKind`
= `urn`), and the url is `https://www.linkedin.com/feed/update/{urn}/`, built without an extra call.

### Text

```php
use SocialPoster\Facades\SocialPoster;

SocialPoster::on('linkedin')
    ->using(['access_token' => $token, 'author' => 'urn:li:person:AbC123dEf'])
    ->caption('Version 1.1 is out. #php')
    ->post();
```

To post as a page, pass the organization URN as `author`:
`['access_token' => $token, 'author' => 'urn:li:organization:12345678']`.

### Image or multi-image

```php
use SocialPoster\ValueObjects\Media;

SocialPoster::on('linkedin')
    ->using($credentials)
    ->media(
        Media::image('/var/app/storage/shots/dashboard.png', altText: 'Dashboard in light mode'),
        Media::image('https://cdn.example.com/shots/settings.jpg'),
    )
    ->caption('Two screens from the new release.')
    ->post();
```

One image is sent as `content.media`, two to twenty as `content.multiImage`. Alt text is trimmed;
a blank one is not sent.

### Video

```php
SocialPoster::on('linkedin')
    ->using($credentials)
    ->media(Media::video('/var/app/storage/clips/demo.mp4', thumbnail: '/var/app/storage/clips/demo.jpg', altText: 'Product walkthrough'))
    ->title('Release walkthrough')
    ->caption('A two-minute walkthrough.')
    ->post();
```

The title becomes the video's `media.title`. The thumbnail is uploaded before the upload is
finalized; if it fails, a warning is logged and the video keeps its default frame. Set
`api.linkedin.upload_thumbnail` to `false` to never send it. Video captions (subtitles) are not
uploaded.

### Document

```php
SocialPoster::on('linkedin')
    ->using($credentials)
    ->media(Media::document('/var/app/storage/decks/q3-results.pdf'))
    ->title('Q3 results')
    ->caption('Our quarter in twelve slides.')
    ->post();
```

The document title is the post title, else the file name without its extension (`q3-results`),
else `Document`.

### Poll

```php
use SocialPoster\Platforms\LinkedIn\{LinkedInOptions, LinkedInPoll};

SocialPoster::on('linkedin')
    ->using($credentials)
    ->caption('Help us pick the next feature.')
    ->withOptions(new LinkedInOptions(
        poll: new LinkedInPoll('Which one first?', ['Dark mode', 'Exports'], LinkedInPoll::SEVEN_DAYS),
    ))
    ->post();
```

The caption is the text above the poll and is required.

### Reshare

```php
SocialPoster::on('linkedin')
    ->using($credentials)
    ->caption('')                                  // optional commentary
    ->withOptions(new LinkedInOptions(reshareOf: 'urn:li:share:7100000000000000001'))
    ->post();
```

### Link card

LinkedIn does not read the page behind a link for API posts, so a link in the caption gets no
preview unless the post carries a card. Give it explicitly:

```php
use SocialPoster\Platforms\LinkedIn\LinkedInArticle;

SocialPoster::on('linkedin')
    ->using($credentials)
    ->caption('Release notes: https://example.com/blog/v1-1')
    ->withOptions(new LinkedInOptions(article: LinkedInArticle::make(
        source: 'https://example.com/blog/v1-1',
        title: 'What is new in 1.1',
        description: 'Staged uploads, first comments and more.',
        thumbnail: 'https://example.com/og/v1-1.png',   // local path, URL, or an urn:li:image:… already uploaded
        thumbnailAltText: 'Release banner',
    )))
    ->post();
```

or let the driver build it from the first link in the caption:

```php
->withOptions(new LinkedInOptions(autoLinkCard: true))
```

See [Link cards](#link-cards) for how the page is read.

### Mentions

```php
use SocialPoster\Platforms\LinkedIn\{LinkedInOptions, LinkedInMention};

SocialPoster::on('linkedin')
    ->using($credentials)
    ->caption('Thanks to {{mention:0}} and {{mention:1}} for the launch.')
    ->withOptions(new LinkedInOptions(mentions: [
        new LinkedInMention('urn:li:organization:12345678', 'Acme Inc'),
        ['urn' => 'urn:li:person:XyZ987wVu', 'name' => 'Jane Doe'],
    ]))
    ->post();
```

Each `{{mention:N}}` marker is replaced by entry `N` of `$mentions`, rendered as LinkedIn's
`@[name](urn)`. The name must match the member's or organization's name on LinkedIn, and the match
is case-sensitive: an organization needs its full name (`Acme Inc`, not `ACME`), a member may be
matched on one of their names. When it does not match, LinkedIn publishes the name as plain text
with no link and reports no error. See [Mentions from profile and company links](#mentions-from-profile-and-company-links)
to look the exact names up.

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `access_token` | yes | OAuth 2.0 access token of the member. Missing → `PermanentException(Misconfigured)` with `missing_credentials`, before any request |
| `author` | yes | `urn:li:person:{id}` or `urn:li:organization:{id}` (plain id: letters, digits, `_`, `-`). Missing or malformed → `PermanentException(Misconfigured)` before any request. It is also the account identity in idempotency keys |
| `scopes` | optional | The granted scopes (a list, or a space- or comma-separated string). When present, validation, the comment step and the recovery policy use them |
| `expires_at` | optional | Token expiry (unix seconds or milliseconds, ISO-8601, or a `DateTimeInterface`). The driver refuses a token that expires within 60 s before `publish()` and before the create step |
| `organization` | optional | An organization URN the member administers. Used only by `autoMentionUrls` to resolve profile links when the author is a person |

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'access_token' => $token,
    'author' => 'urn:li:organization:12345678',
    'scopes' => 'w_organization_social w_organization_social_feed r_organization_social',
    'expires_at' => $expiresAt,
]);

// Or keep the token out of the queue payload and resolve it per job run:
Credentials::ref('social_account:42', ['author' => 'urn:li:organization:12345678']);
```

`validate()` checks the `author` shape only when an author is given (under `credentials.author`),
so content-only validation without credentials still works.

### Auth model

- The bearer is a 3-legged OAuth 2.0 member token. The driver sends `Authorization: Bearer <access_token>`.
- The driver does not obtain or refresh tokens. Pass `expires_at`, and bind a `CredentialsResolver`
  (`config('social.credentials_resolver')`) that returns a current token on every job run. With a
  resolver bound, an expired token and a 401 get one retry with re-resolved credentials. A revoked
  token (`REVOKED_ACCESS_TOKEN`) is final.
- If `config('social.authorizers.linkedin')` names a class implementing `AuthorizesRequests`, API
  requests are built from it instead of the bearer token. Video parts are then uploaded one at a
  time, and `autoMentionUrls` lookups still use `access_token`.

### Scopes and page roles

`Poster::requiredScopes()` (also `SocialPoster::requiredScopes()`) returns groups that are all
required; any one scope of a group satisfies it:

| Feature | Person author | Organization author | Author unknown |
|---|---|---|---|
| `Feature::Publish` | `w_member_social` | `w_organization_social` | any of the two |
| `Feature::Comment` | `w_member_social_feed` or `w_member_social` | `w_organization_social_feed` or `w_organization_social` | any of the four |
| `Feature::Video`, `Feature::Document` | nothing extra | nothing extra | nothing extra |

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::LinkedIn, Feature::Publish, ['author' => 'urn:li:organization:12345678']);
// [['w_organization_social']]
```

- With `scopes` in the credentials, a missing publish group is a validation error under
  `credentials.scopes`, for example
  `The LinkedIn token is missing a permission needed to publish: grant w_member_social.`
  Without `scopes`, nothing is checked.
- Posting as a page also needs one of these roles on it: `ADMINISTRATOR`,
  `DIRECT_SPONSORED_CONTENT_POSTER` or `CONTENT_ADMIN`. Commenting as a page needs `ADMINISTRATOR`,
  `DIRECT_SPONSORED_CONTENT_POSTER` or `RECRUITING_POSTER`. A 403 names the scope and roles for the
  author type.
- LinkedIn documents the `*_social_feed` scopes for the Comments API. The driver also accepts
  `w_member_social` / `w_organization_social` for comments; whether LinkedIn does is an open live
  check.
- `r_member_social` (person) or `r_organization_social` (organization) in `scopes` turns on
  recovery reads, see [Duplicate safety](#duplicate-safety).

App review. In LinkedIn's developer portal, `w_member_social` comes with the self-serve *Share on
LinkedIn* product. The organization scopes and the `*_social_feed` comment scopes come with the
*Community Management API*, which LinkedIn grants after an access request and review.
`r_member_social` is restricted, and most apps do not have it, so recovery reads for member
authors are usually unavailable.

### API version

`api.linkedin.version` (env `SOCIAL_LINKEDIN_VERSION`, default `202605`) is sent as
`LinkedIn-Version`. LinkedIn retires versions: bump it at least once a year. A retired or missing
version (426, `NONEXISTENT_VERSION`, `VERSION_MISSING`) fails as `PermanentException(Misconfigured)`
with `linkedin_version` in the context.

---

## Typed options

```php
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;

new LinkedInOptions(
    escapeText: true,
    extra: [],
    mentions: [],
    visibility: LinkedInOptions::VISIBILITY_PUBLIC,
    disableReshare: false,
    feedDistribution: LinkedInOptions::FEED_MAIN,
    targetEntities: [],
    reshareOf: null,
    poll: null,
    article: null,
    autoLinkCard: false,
    autoMentionUrls: false,
);
```

The first three positions (`escapeText`, `extra`, `mentions`) are v1.0's; pass the rest by name.

| Option | Type | Default | Effect | Validation (key) |
|---|---|---|---|---|
| `escapeText` | `bool` | `true` | Escapes LinkedIn's reserved little-text characters in the caption (see below). `false` sends the caption untouched, for hand-built little text. Ignored when `mentions` is not empty | — |
| `extra` | `array` | `[]` | Merged into the `POST /rest/posts` body (see below) | Not validated |
| `mentions` | `list<LinkedInMention\|array{urn, name}>` | `[]` | Entities referenced by `{{mention:N}}` markers | URN must be `urn:li:person:{id}` or `urn:li:organization:{id}`, name not blank (`mentions.N`). A marker without entry `N` is an error (`caption`) |
| `visibility` | `string` | `PUBLIC` | `visibility`: `PUBLIC`, `CONNECTIONS` or `LOGGED_IN` (case-insensitive) | `visibility` |
| `disableReshare` | `bool` | `false` | `isReshareDisabledByAuthor` | — |
| `feedDistribution` | `string` | `MAIN_FEED` | `distribution.feedDistribution`: `MAIN_FEED` or `NONE` (a post kept out of the feed) | `feedDistribution`. `NONE` is refused for a person author |
| `targetEntities` | `array` | `[]` | `distribution.targetEntities` (targeting facets such as `geoLocations`, `seniorities`, `industries`). LinkedIn needs a target audience above 300 | Each entry a non-empty array (`targetEntities`). Refused for a person author |
| `reshareOf` | `?string` | `null` | `reshareContext.parent`. The caption may be empty | `urn:li:share:{digits}` or `urn:li:ugcPost:{digits}` (`reshareOf`). No media |
| `poll` | `LinkedInPoll\|array\|null` | `null` | `content.poll` | See `LinkedInPoll` (`poll`). No media |
| `article` | `LinkedInArticle\|array\|null` | `null` | `content.article` (link card) | See `LinkedInArticle` (`article`). No media |
| `autoLinkCard` | `bool` | `false` | Build a card from the first non-LinkedIn link of a text post | — |
| `autoMentionUrls` | `bool` | `false` | Turn `linkedin.com/in/…`, `/company/…` and `/school/…` links in the caption into mentions | — |

Only one of `article`, `poll` and `reshareOf` may be set. The author-type checks run only when an
author is known: `validate()` without credentials does not refuse `NONE` or `targetEntities`.
Options bound to another platform are ignored by the LinkedIn driver.

### Escaping

With `escapeText` on (the default) and no mentions, the driver escapes
`\ | ( ) [ ] { } @ * < > _ ~` everywhere, and `#` when it does not start a hashtag (`C#`, `# 1`,
a trailing `#`). `#php`, `#café` and `#2026` stay hashtags. Invalid UTF-8 is escaped byte-wise.
With mentions, the driver escapes the text between markers and each mention name itself.

### `LinkedInMention`

`new LinkedInMention(string $urn, string $name)` or `LinkedInMention::make(...)`. The URN is trimmed.
`hasValidUrn()` accepts only `urn:li:person:{id}` and `urn:li:organization:{id}` with a plain id,
because the URN is written raw into `@[name](urn)`.

### `LinkedInPoll`

`new LinkedInPoll(string $question, array $options, string $duration = LinkedInPoll::THREE_DAYS)`,
`LinkedInPoll::make(...)`, or an array `['question' => ..., 'options' => [...], 'duration' => ...]`.

| Constant | Value |
|---|---|
| `MAX_QUESTION` | 140 characters |
| `MIN_OPTIONS` / `MAX_OPTIONS` | 2 / 4 |
| `MAX_OPTION` | 30 characters per option |
| `DURATIONS` | `ONE_DAY`, `THREE_DAYS` (default), `SEVEN_DAYS`, `FOURTEEN_DAYS` (case-insensitive) |

The question and options are trimmed; empty or duplicate options (compared case-insensitively)
are errors.

### `LinkedInArticle`

`new LinkedInArticle(string $source, ?string $title = null, ?string $description = null, ?string $thumbnail = null, ?string $thumbnailAltText = null)`,
`LinkedInArticle::make(...)`, or an array with the same keys (`url` is accepted for `source`,
`thumbnail_alt_text` for `thumbnailAltText`).

- `source`: required, an `http(s)` URL.
- `title`: required. LinkedIn does not read it from the page.
- `description`: optional.
- `thumbnail`: optional. A local path or an `http(s)` URL is uploaded through the Images API; an
  `urn:li:image:…` or `urn:li:digitalmediaAsset:…` is used as is. A thumbnail that cannot be
  fetched or uploaded is dropped, and the card goes out without it.
- `thumbnailAltText`: optional, up to `LinkedInArticle::MAX_ALT_TEXT` = 4086 characters; sent only
  with a thumbnail.

### The `extra` passthrough

`extra` (or `RawOptions(Platform::LinkedIn, [...])`) is merged into the `POST /rest/posts` body
with a shallow merge, for fields the typed options do not cover (for example
`contentLandingPage`).

- The driver's keys always win: `author`, `commentary`, `visibility`, `distribution`,
  `lifecycleState` and `isReshareDisabledByAuthor`. Use the typed options to change them.
- `content` and `reshareContext` win only when the driver sets them (media, a card, a poll, a
  reshare). On a text post without them, `extra['content']` and `extra['reshareContext']` are sent
  as given.
- `extra` is never sent with the first comment, and never validated.

### Mentions from profile and company links

`autoMentionUrls: true` replaces each resolvable `linkedin.com/in/{vanity}`,
`linkedin.com/company/{vanity}` and `linkedin.com/school/{vanity}` link in the caption with a
mention carrying LinkedIn's exact name. The lookups run in `publish()` with the posting token:

- Organizations and schools: `GET https://api.linkedin.com/v2/organizations?q=vanityName`.
- Persons: `GET /rest/vanityUrl?q=vanityUrlAsOrganization`, then `GET /rest/people/(id:…)`. This
  needs an organization the member administers: the author when it is an organization, else the
  `organization` credential. Without one, profile links stay links.
- At most `api.linkedin.max_auto_mentions` (10) links become mentions per post, and at most twice
  that many distinct links are looked up (failed lookups cost calls too). A link that cannot be
  resolved (unknown, refused, network error) stays a link and a warning is logged.
- Ignored with `escapeText: false` and no `mentions`, so hand-built little text is never altered.

The resolver can be used directly, for example in a connect or compose screen:

```php
use SocialPoster\Platforms\LinkedIn\LinkedInMentionResolver;

$resolver = new LinkedInMentionResolver();                 // (version '202605', timeout 15)
$acme = $resolver->organization($token, 'acme');           // ?LinkedInMention
$jane = $resolver->person($token, 'urn:li:organization:12345678', 'jane-doe');
$any  = $resolver->fromUrl($token, 'https://www.linkedin.com/company/acme/', $organizationUrn);
```

Each method returns `null` when LinkedIn knows no such entity (404 or an empty answer) and throws
when the call fails: 401 `InvalidToken`, 403 `InsufficientPermissions`, 429 `RateLimited`
(temporary, `Retry-After` or 60 s), 5xx `ServerError` (temporary), anything else `Unknown`. Bind
your own instance in the container (`LinkedInMentionResolver::class`) to change how the driver
resolves.

### Link cards

A card is built only for text posts (not with media, a poll or a reshare). An explicit `article`
wins over `autoLinkCard`. Building a card never fails the post: when it cannot be built, the post
goes out without it and a warning is logged.

With `autoLinkCard: true`:

- The link is the first one in the caption whose host is not `linkedin.com`, `*.linkedin.com` or
  `lnkd.in`.
- The URL passes the media URL policy (`social.media.allow_http`, `allowed_hosts`,
  `block_private_networks`), and redirects (at most 3) are checked again. When private networks
  are blocked, the connection is pinned to the checked address. Connect timeout 5 s, read timeout
  `api.linkedin.link_card_timeout` (8 s).
- Only an HTML page is read (`text/html`, `application/xhtml+xml`, or no `Content-Type`). A
  download larger than 5 MB is aborted, and only the first 1 MB is parsed.
- Title: `og:title`, else `twitter:title`, else `<title>`. Description: `og:description`,
  `twitter:description` or `description`. Image: `og:image`, `og:image:url`,
  `og:image:secure_url` or `twitter:image`, made absolute. No title, no card.
- The title is clipped to `api.linkedin.link_card_title_max` (200) and the description to
  `api.linkedin.link_card_description_max` (300) characters, with `…`.

Card thumbnails from a URL (automatic or explicit) go through the same guard. An image whose
`Content-Type` is not `image/*`, or whose announced or actual size is over
`api.linkedin.link_card_image_max_bytes` (5242880), is skipped without being read whole.

---

## Limits

### Text

- **Counting rule**: Unicode code points (`mb_strlen`) of the trimmed caption as you wrote it,
  before escaping. The escape backslashes do not count.
- With `mentions`, the visible text is counted: each marker counts as the mention's name, URNs do
  not count.
- With `escapeText: false`, the raw string is counted, markup included (`@[…](urn:li:…)`,
  `{hashtag|\#|…}`, backslashes). Whether LinkedIn counts visible or raw text is an open live
  check.
- **Maximum**: `api.linkedin.max_characters` = 3000.
- **A caption is required**, except for a reshare: `LinkedIn posts can't have an empty caption.`

### Media

| | Formats | Count | Size | Other |
|---|---|---|---|---|
| Image | `jpg`, `jpeg`, `gif`, `png` | 1, or 2 to `api.linkedin.max_images` (20) | not checked | Fewer than 36,152,320 pixels: `api.linkedin.max_image_pixels` = 36152319 |
| Video | `mp4` | exactly 1, alone | `api.linkedin.min_video_bytes` = 76800 (75 KB) to `api.linkedin.max_video_bytes` = 524288000 (500 MB) | `api.linkedin.min_video_duration` = 3 s to `api.linkedin.max_video_duration` = 1800 s |
| Document | `pdf`, `doc`, `docx`, `ppt`, `pptx` | exactly 1, alone | `api.linkedin.max_document_bytes` = 104857600 (100 MB) | — |

- Alt text (images and video) is limited to `api.linkedin.max_alt_text` = 4086 characters
  (`media.N`).
- No aspect ratio, width, height or frame rate is checked. Formats come from the file extension;
  there is no automatic conversion (a `.webp` is refused).
- Size, duration and pixels come from the bound `MediaInspector` (`ffprobe` by default), completed
  by `Media::$meta` (`size`, `width`, `height`, `duration`). What is unknown is not checked.
- The video size is checked again on the file itself before the upload starts, also when
  validation is skipped: outside the limits it fails as `PermanentException(MediaRejected)` with
  `size`, `min_bytes` and `max_bytes`.
- With 2 or more items, all must be images. A video or document in the set gets
  `video media is not supported here on linkedin.` (or `document media …`) under `media.N`.
- LinkedIn limits GIFs to 250 frames; the driver does not check it.
- v1.0 capped video at 200 MB with no minimum. 1.1 follows LinkedIn's current 75 KB to 500 MB.

---

## First comment

`->withComment('...')` posts a comment as the author on the new post
(`POST /rest/socialActions/{post urn}/comments` with `actor`, `object` and `message.text`).

```php
SocialPoster::on('linkedin')
    ->using($credentials)
    ->caption('Release notes are up.')
    ->withComment('Full changelog: https://example.com/changelog')
    ->post();
```

| Condition | Behaviour |
|---|---|
| Length | `api.linkedin.comment_max_characters` = 1250 code points. Checked at `validate()` under `comment`, so an over-long comment never fails after the post is live |
| Text | Sent as given: no little-text escaping, no mentions |
| Delay | `max(social.comment_delay, api.linkedin.comment_min_delay)`: 60 s by default. A brand-new post answers 404 until its activity reaches the Comments API |
| Scopes | See [Scopes](#scopes-and-page-roles). With `scopes` in the credentials and none of the group granted, the comment is skipped (`CommentSkipped`, reason `missing_scope`). Without `scopes`, it is attempted |
| Parent failed | No comment |
| `postNow()` | Queued with the delay, even with `inlineComment()`. On the `sync` queue connection, which cannot delay a job, `postNow()` waits `comment_min_delay` in-process and adds the comment there, as long as it fits `social.sync.max_wait` (120 s); otherwise `CommentFailed(Misconfigured)` explains why |

| Comment error | Result |
|---|---|
| 404 (activity not propagated yet) | Temporary `NotFound`, retried after 60 s, context `activity_not_ready` |
| 429 (`Comment create throttled` and other short limits) | Temporary `RateLimited`, retried after `Retry-After` or 60 s, at most 300 s |
| 429 daily limit, reset further than `max_rate_limit_wait` | Permanent `RateLimited` with `retry_at` |
| 403 | Permanent `InsufficientPermissions`, with the comment scope and page roles in the message |
| Connection lost after the request was sent | Permanent `OutcomeUnknown`, `possibly_published = true`. Not retried: the comment may exist |
| A recovery (an earlier attempt got a 5xx, or its worker died mid-`POST`) | The comment job passes `_recovering`. Before posting, the driver reads the post's comments (`GET /rest/socialActions/{urn}/comments`) and returns the author's comment with the same text (payload `recovered = true`). Nothing found: the comment is posted. The comments cannot be read (they need `r_organization_social_feed` for a page, or the restricted `r_member_social_feed` for a member): a temporary error is retried; a refusal fails as `OutcomeUnknown` with `possibly_published = true`, `recovering = true` and `lookup_reason`, never a second comment |
| Connection failed before sending (DNS, refused, TLS, connect timeout) | Temporary `Network`, retried |
| Parent result without a URN | Permanent `NotFound`, nothing sent |
| Other errors | As for posts, see [Error handling](#error-handling) |

The comment job retries temporary errors up to `social.comment_tries` (5) times, waiting
`Retry-After` (at most 600 s) or `social.comment_backoff`. The result's id is the bare comment id
from `x-restli-id`, else the body's `id`, else the comment URN. When LinkedIn returns the comment
URN, the url is `https://www.linkedin.com/feed/update/{post urn}/?commentUrn={encoded urn}`. The
payload holds `comment_id`, `comment_urn` and `response`. Outcomes arrive as `CommentPublished`,
`CommentFailed` or `CommentSkipped`.

---

## Async behaviour

A publish is a small state machine. Each step is one job run, and the job carries the state between
runs. `PostProcessing` fires for every wait that is not a commit step.

| Phase (`state['phase']`) | What happens | Next |
|---|---|---|
| `publish()` | Text, poll, reshare, card: commentary rendered, mentions resolved, card built. Images: each one `POST /rest/images?action=initializeUpload`, then `PUT` of the bytes. Video: `initializeUpload` with `fileSizeBytes`, then the first parts. Document: `initializeUpload` and one streamed `PUT` | `create_post`, `upload_video`, `await_video` or `await_document` |
| `upload_video` | More parts, from `next`, while the run's budget lasts. The planned parts, their ETags, `upload_token` and `upload_expires_at` are kept in the state | `upload_video` again, then the thumbnail and `finalizeUpload` |
| `finalize_video` | The finalize answer was lost. The video status is read first: `PROCESSING` or `AVAILABLE` means it was finalized; otherwise finalize again | `await_video` |
| `await_video` | `GET /rest/videos/{urn}` | `await_video`, or `create_post` once `AVAILABLE` |
| `await_document` | `GET /rest/documents/{urn}` | `await_document`, or `create_post` once `AVAILABLE` |
| `create_post` | `POST /rest/posts`, the only non-idempotent call (the commit step) | Done, or a verify run of `create_post` |

What each `Pending` means:

- **Create step**: every post ends with a commit `Pending` (recheck 0). On the queue that is one more
  job run, dispatched without delay. `postNow()` runs it inline. Text and image posts therefore take
  one more continuation than in v1.0.
- **Upload slice** (`upload_video`): recheck 0. One run uploads for at most
  `api.linkedin.upload_run_seconds` (240 s) and always sends at least one batch of parts, even when
  resolving the media used up the budget. A batch is one part, or `api.linkedin.upload_concurrency`
  parts sent at once (1 to 8). A part that fails after progress keeps the parts already sent and
  retries from that part after 15 s.
- **Expired upload URLs**: a part answering 401 after `uploadUrlsExpireAt` restarts the upload from
  scratch (within `social.max_restarts`, 3). A source file that changed size between runs fails as
  `MediaRejected`.
- **Processing** (`await_video`, `await_document`): the first video check comes 10 s after the
  finalize, then every 15 s; documents every 20 s. `WAITING_UPLOAD` is accepted for
  `api.linkedin.waiting_upload_timeout` (300 s), then fails as `MediaRejected` (no automatic
  re-upload). `PROCESSING_FAILED` fails as `MediaRejected` with LinkedIn's
  `processingFailureReason`. A status answer without `status` is a temporary `ServerError`.
- **Deadline**: the processing wait declares its own deadline, `api.linkedin.processing_timeout`
  (3600 s, at least 60) from the finalize or the document upload. Past it the publish fails as
  `PermanentException(ProcessingTimeout)`, or as `Timeout` when the job sees the deadline first;
  nothing was posted.
- **Create after processing**: the create step declares the deadline
  `max(processing deadline, now + 300 s)`, and a create that is ready when a deadline passes still
  runs once, so media that turns `AVAILABLE` on the last check is posted. On the `sync` connection
  the processing waits are capped by `social.sync.max_wait`; the ready create and upload slices are
  not.

Documents now wait for `AVAILABLE`. v1.0 created the post while LinkedIn was still `PROCESSING`.

Timing. The package records no measured processing times; they depend on the file and on LinkedIn.
The video upload counts toward `social.async.max_wait_per_platform.linkedin` (3600 s in the
published config, else `social.async.max_wait`), measured from the first run. The processing wait
then has its own `processing_timeout` window. For very large videos on slow links, raise
`max_wait_per_platform.linkedin`. Past that limit, an upload still in progress fails with
`Timeout`.

`postNow()` waits up to `social.sync.max_wait` (120 s) and then hands the publish to the queue. The
result's state is then `processing`, and the outcome arrives as `PostPublished` or `PostFailed`.

Timeouts: API calls use `api.linkedin.timeout`, which falls back to `social.http_timeout` (60 s).
Byte uploads (image and document `PUT`, video parts, thumbnails) use `api.linkedin.upload_timeout`,
which falls back to `social.upload_timeout` (300 s). The connect timeout is
`api.linkedin.connect_timeout` (10 s), concurrent part uploads included.

Continuations queued by v1.0 (`await_video` with `video`, `await_document` with `document` and
`title`) still resume. Their commentary is rendered again from the post, and their processing
deadline starts when they resume.

---

## Error handling

LinkedIn answers `{status, serviceErrorCode, code, message}`, or adds
`errorDetails.inputErrors[]` for validation failures. The exception context holds `status`,
`http_status`, `code`, `service_error_code`, `operation` (`post`, `upload` or `comment`) and the
raw body under `error`. Checks run in this order:

| LinkedIn answer | Exception | `FailureReason` | Action |
|---|---|---|---|
| 426, or `NONEXISTENT_VERSION` / `VERSION_MISSING` | Permanent | `Misconfigured` | Set a current `SOCIAL_LINKEDIN_VERSION`. Context `linkedin_version` |
| 401, or `REVOKED_ACCESS_TOKEN`, `EXPIRED_ACCESS_TOKEN`, `INVALID_ACCESS_TOKEN`, `EMPTY_ACCESS_TOKEN` | Permanent | `InvalidToken` | Reconnect. Revoked: `terminal = true`, never re-resolved. Expired: `token_expired = true`. With a resolver bound, one retry with re-resolved credentials |
| `RESTRICTED_MEMBER` | Permanent | `AccountRestricted` | The member must resolve it on LinkedIn |
| 429, or a message containing "throttle" | see below | `RateLimited` | — |
| `MEDIA_ASSET_WAITING_UPLOAD` | Temporary | `ServerError` | Retried after 20 s |
| `MEDIA_ASSET_PROCESSING_FAILED` | Permanent | `MediaRejected` | Fix the media |
| a message naming `duplicate of urn:li:…` (422 `Content is a duplicate of urn:li:share:…`) | Permanent | `DuplicateContent` | Context `existing_urn`. After an ambiguous create, taken as proof the earlier attempt published (see below) |
| 403, or `ACCESS_DENIED` | Permanent | `InsufficientPermissions` | Message names the scope and page roles for the author type and operation |
| 404 on a comment | Temporary | `NotFound` | Retried after 60 s (`activity_not_ready`) |
| 408, 409 | Temporary | `ServerError` | Retried after `Retry-After`, else 30 s |
| 4xx with `inputErrors` | Permanent | `InvalidContent`, or `MediaRejected` when a field path is under `/content` | Message joins the descriptions. Context `input_errors` (codes) and `field_paths` |
| anything else | base table | 404 `NotFound`; 425 and 5xx `ServerError` (temporary, `Retry-After`); other `Unknown` | — |

Throttles. A daily limit (the message contains `DAY` or "Resource level throttle") resets at the
`Retry-After` LinkedIn sends, else at midnight UTC (plus 30 to 300 s of jitter). When that reset is
further away than `api.linkedin.max_rate_limit_wait` (3600 s), the failure is permanent:
`PermanentException(RateLimited)` with `daily_limit = true`, `retry_at` (ISO-8601 UTC: now plus
`Retry-After`, or the next midnight) and `retry_after_hint` (seconds), and you re-dispatch after
that time. A closer reset is waited out as a temporary throttle. Other throttles wait `Retry-After`,
else 60 to 300 s (60 s for a comment). Comment waits are capped at 300 s. Temporary throttles carry
`daily_limit` too.

Other failures raised by the driver:

| Situation | Exception / reason |
|---|---|
| No `access_token` or `author`, or a malformed `author` | Permanent `Misconfigured`, before any request |
| Token expires within 60 s (`expires_at`) | Permanent `InvalidToken` (`token_expired`), or Temporary with `reresolve` when a resolver is bound |
| Upload init answered 2xx without an upload URL, URN or usable `uploadInstructions` | Temporary `ServerError` (a fresh init is safe) |
| Image or document `PUT` refused with a 4xx other than 401, 403, 408 or 429 (a malformed or oversized upload) | Permanent `MediaRejected`, context `http_status` and `upload` |
| Image or document `PUT` refused with 401, 403, 408, 429 or a 5xx | Temporary `ServerError`, context `http_status` and `upload` |
| Video part refused, or accepted without an `ETag` | Temporary `ServerError`; a lost connection is Temporary `Network` with `part` |
| Video file changed size, or shorter than the planned parts | Permanent `MediaRejected` |
| Video outside the byte limits at upload | Permanent `MediaRejected` |
| Processing failed, or `WAITING_UPLOAD` past `waiting_upload_timeout` | Permanent `MediaRejected` |
| Processing past `processing_timeout` | Permanent `ProcessingTimeout` |
| Create answered 2xx without a URN (`x-restli-id`, `x-linkedin-id`, body `id`) | Permanent `OutcomeUnknown`, `possibly_published = true` (never retried) |
| Connection lost during the create | See [Duplicate safety](#duplicate-safety) |
| A continuation without its URN, or an unknown phase | Permanent `Unknown` |
| Thumbnail, link card or mention lookup failed | Logged as a warning; the post goes out without it |

`FailureReason::category()` groups these: `retry` (RateLimited, ServerError, Timeout, Network,
ProcessingTimeout), `reconnect` (InvalidToken, InsufficientPermissions; `requiresReconnect()` is
true), `user_fix` (AccountRestricted, MediaRejected, DuplicateContent, InvalidContent), `operator`
(Misconfigured, Unknown), `not_found`, and `verify` (OutcomeUnknown). An exception flagged
`possibly_published` reports `verify` through `errorSummary()` whatever its reason.

Every failed response is logged (`[SocialPoster] linkedin API error: ...`) with tokens redacted;
non-fatal problems are logged as warnings (`[SocialPoster] linkedin: ...`). Turn both off with
`social.error_logging`, or route them with `social.error_log_channel`.

---

## Duplicate safety

`POST /rest/posts` is the only call that can create a post twice. The driver runs it in a commit
step: the job records the commit before the call. If the outcome of a run is ambiguous, the job
runs the step again with `_recovering` set: after a worker that died or timed out, a queue
redelivery, a store record still marked committing, or a 5xx or lost connection after the request
may have been sent. It does not do so after explicit refusals (4xx including 408, 409 and 429,
token rejections) or a request that provably never left (DNS, refused connection, TLS, connect
timeout), which are retried normally.

LinkedIn posts cannot be read back with the posting scopes. The recovery policy therefore depends
on read access:

| | No read access (default) | Read access |
|---|---|---|
| Connection lost after the create was sent | `PermanentException(OutcomeUnknown)`, `possibly_published = true`, `request_sent = true`. Not sent again | A verify step runs 30 s later (its own deadline: at least 300 s) |
| Recovering run (`_recovering`), or the verify step | `PermanentException(OutcomeUnknown)`, `possibly_published = true`, `recovering = true`. Nothing is sent | The author's posts are read first. Found → `Published` with that URN, payload `recovered = true`. Not found → the post is created again |
| Re-create refused as a duplicate of `urn:li:share:X` | — | `Published` with URN `X`, payload `recovered = true`, `duplicate_refused = true` |

Read access means `api.linkedin.verify_reads = true` (env `SOCIAL_LINKEDIN_VERIFY_READS`), or
`r_member_social` (person author) / `r_organization_social` (organization author) listed in the
credentials' `scopes`.

The read is `GET /rest/posts?q=author&author={author}&count=20&sortBy=LAST_MODIFIED&viewContext=AUTHOR`
with `X-RestLi-Method: FINDER`. `viewContext=AUTHOR` also lists a post that is still `PROCESSING`
or `PUBLISH_REQUESTED`. A listed post matches when all of these hold:

- it was created at or after the attempt start minus 60 s of clock skew. The attempt start is the
  earliest create attempt, also for a delayed verify step;
- its commentary equals the one sent, after hashtag templates (`{hashtag|\#|tag}`, also the
  full-width `＃` form) become plain `#tag`, escapes are removed and whitespace is collapsed;
- it reshares the same post (`reshareContext.parent`), or neither is a reshare;
- when both sides have content, the same content: video or document URN, first image URN, article
  `source` or poll question.

A read that hits the network is retried as a temporary `Network` error, still flagged
`possibly_published`. A refused read ends as `OutcomeUnknown` with `verify_status`.

In a run after an ambiguous create (recovering, or the verify step), any failure (token expired,
read refused, re-create refused, 5xx) is reported with `possibly_published = true`, and the
idempotency record is kept for `reconcile()`.

Outside recovery, a 422 duplicate refusal of a first create is a clean
`PermanentException(DuplicateContent)` with `existing_urn` in the context (LinkedIn refusing the
same content posted again; the exact message is an open live check).

What this means for your code:

- `PostFailed` with `possiblyPublished === true` (reason `OutcomeUnknown`) means: check the account
  before posting again. Never re-dispatch blindly. Without read access this is the outcome of every
  ambiguous create, including a 5xx answer to it.
- `PostPublished` can carry `payload['recovered'] === true`. The URN is real in both recovery paths.
- Keep an idempotency store bound (the default `CacheIdempotencyStore`, or
  `DatabaseIdempotencyStore`) and give the publish your own `->idempotencyKey()`, so a repeat
  dispatch replays the recorded result instead of posting again. The account identity is the
  `author` URN, which does not change when the token is refreshed.
- The first comment follows the same rule: a lost comment response is `OutcomeUnknown` and never
  re-sent, a retry after a 5xx or a dead worker looks the comment up first (or fails as
  `OutcomeUnknown` without read access), and the comment job skips a comment already recorded under
  the publish's key.

---

## Configuration (`social.api.linkedin`)

| Key | Default | Effect |
|---|---|---|
| `version` | `202605` (env `SOCIAL_LINKEDIN_VERSION`) | `LinkedIn-Version` header |
| `timeout` | `social.http_timeout` (60) | Read timeout (s) of API calls. Mention lookups use it too, with 15 when unset |
| `connect_timeout` | `10` | Connect timeout (s) |
| `upload_timeout` | `social.upload_timeout` (300) | Read timeout (s) of byte uploads |
| `upload_run_seconds` | `240` | Video upload time per job run before handing over |
| `upload_concurrency` | `1` | Video parts sent at once (1 to 8; 1 when `social.authorizers.linkedin` is set) |
| `upload_thumbnail` | `true` | Upload `Media::video()` thumbnails |
| `max_characters` | `3000` | Caption limit |
| `max_images` | `20` | Images per post |
| `max_image_pixels` | `36152319` | Pixels per image |
| `max_video_bytes` | `524288000` | Video size limit |
| `min_video_bytes` | `76800` | Smallest video |
| `min_video_duration` | `3` | Seconds |
| `max_video_duration` | `1800` | Seconds |
| `max_document_bytes` | `104857600` | Document size limit |
| `max_alt_text` | `4086` | Image and video alt text limit (characters) |
| `comment_max_characters` | `1250` | First comment limit |
| `comment_min_delay` | `60` | Minimum seconds between the post and its first comment |
| `processing_timeout` | `3600` | Seconds to wait for video or document processing (at least 60) |
| `waiting_upload_timeout` | `300` | Seconds `WAITING_UPLOAD` is accepted |
| `max_rate_limit_wait` | `3600` | A daily-limit reset further away fails as `RateLimited` with `retry_at` |
| `verify_reads` | `false` (env `SOCIAL_LINKEDIN_VERIFY_READS`) | Read posts back to settle an ambiguous create |
| `max_auto_mentions` | `10` | Links turned into mentions per post (`autoMentionUrls`) |
| `link_card_timeout` | `8` | Read timeout (s) of link-card page and image fetches |
| `link_card_title_max` | `200` | Automatic card title clip (characters) |
| `link_card_description_max` | `300` | Automatic card description clip (characters) |
| `link_card_image_max_bytes` | `5242880` | Card thumbnail size cap |

`timeout`, `connect_timeout`, `upload_timeout` and `upload_run_seconds` are not in the published
config file, and `max_characters` is there only as a comment; add them to the `linkedin` block to
change them.

Related global keys: `social.http_timeout` (60), `social.upload_timeout` (300),
`social.async.max_wait` (3600) and `social.async.max_wait_per_platform.linkedin`,
`social.sync.max_wait` (120), `social.comment_delay` (60), `social.comment_tries` (5) and
`social.comment_backoff`, `social.tries` (5) and `social.backoff`, `social.max_restarts` (3),
`social.job_timeout`, `social.credentials_resolver`, `social.authorizers.linkedin`,
`social.idempotency`, `social.media.*` (link-card SSRF guard), `social.error_logging` and
`social.error_log_channel`.

### Extending the driver

Subclasses can override these protected hooks, added in 1.1: `commitCreate()` (trailing
`?int $deadline = null`), `runCreate()`, `attemptStartedAt()`, `createDeadline()`,
`findCreatedPost()`, `matchesPost()`, `normaliseCommentary()`, `reshareParent()`,
`canVerifyPosts()`, `fetchCardResource()`, `cardFetchOptions()`, `isCardType()` and
`readCapped()`, and the constants `CREATE_STEP_SECONDS` (300), `RECOVERY_SKEW_SECONDS` (60),
`CARD_PAGE_READ_BYTES` (1048576) and `CARD_PAGE_MAX_DOWNLOAD` (5242880).

`classifyError($response)` keeps its v1.0 signature, so a 1.0 override still loads and is still
called. The driver passes two extra arguments, the operation (`'post'`, `'upload'` or `'comment'`)
and the author URN; an override reads them with `func_get_args()` (`$args[1]`, `$args[2]`). The
other v1.0 protected methods keep their signatures; `assetStatus()`, `uploadVideoChunks()` and
`urnId()` are deprecated.

---

## Known limitations

- The driver does not obtain or refresh tokens, and has no helper to list the pages a member can
  post as.
- Organization authors and the comment scopes need LinkedIn's Community Management API approval.
  `r_member_social` is restricted, so for member authors an ambiguous create usually ends as
  `OutcomeUnknown` instead of being recovered. The same goes for a first comment retried after a 5xx
  or a dead worker: reading comments needs `r_member_social_feed` (restricted) or
  `r_organization_social_feed`. The lookup reads LinkedIn's first page of comments.
- Recovery reads one page: the 20 most recently modified posts. A post not listed yet is created
  again, and then only LinkedIn's duplicate refusal prevents a second post. A post with the same
  commentary and content, created by the author in the window, is taken as the earlier attempt.
- With `escapeText: false` the caption is measured with its markup; LinkedIn's own counting rule is
  not confirmed.
- The first comment is plain text: no mentions, no escaping.
- No video captions (subtitles), drafts, scheduled posts, stories or reels.
- Image byte size is not checked, nor the GIF frame limit. No format conversion.
- A `WAITING_UPLOAD` that lasts more than 5 minutes fails; the media is not uploaded again.
- The video upload counts toward `social.async.max_wait_per_platform.linkedin`.
- Mention resolution and link cards add requests during `publish()`: up to `max_auto_mentions`
  lookups, a page fetch and a thumbnail upload. Mention lookups use the bearer token even when an
  authorizer is configured.
- `extra` is merged shallowly and never validated.

---

## Open live checks

These LinkedIn behaviours are covered by fixtures but not yet confirmed against the live API. Run
`tests/Live/LinkedInLiveTest.php` with `SOCIAL_LIVE_LINKEDIN_TOKEN` and `SOCIAL_LIVE_LINKEDIN_AUTHOR`
(plus `SOCIAL_LIVE_PUBLISH=1` for the checks that post a connections-only post and delete it). See
`tests/Live/README.md`.

1. Whether the 3,000-character limit counts the visible text or the raw little-text string.
2. `GET /rest/posts?q=author` with `X-RestLi-Method: FINDER` on version 202605, and whether the
   commentary that comes back matches the little text sent. Automated.
3. The video thumbnail `PUT` (`media-type-family: STILLIMAGE`, `application/octet-stream`, 201)
   before `finalizeUpload`, and a non-empty `uploadToken` accepted by finalize.
4. `uploadUrlsExpireAt` in milliseconds, and an expired part URL answering 401 (the restart path).
5. Concurrent part uploads (`upload_concurrency` > 1) against real upload URLs.
6. How long documents take to reach `AVAILABLE`. v1.0 posted while still `PROCESSING`.
7. The poll content shape `{question, options[{text}], settings{duration}}` and its limits.
8. Article content: is the title required, and what are the real title and description limits?
9. `feedDistribution: NONE` and `targetEntities` for organization authors; `CONNECTIONS` and
   `LOGGED_IN` visibility per author type.
10. The comment create response: `x-restli-id` is the bare id, `commentUrn` is in the body, and the
    `?commentUrn=` permalink works with a share URN. Automated.
11. Whether `w_member_social` alone (without `w_member_social_feed`) can create comments.
12. The daily throttle message contains `DAY` (e.g. `Resource level throttle APPLICATION DAY limit …`).
13. Where `MEDIA_ASSET_WAITING_UPLOAD` and `MEDIA_ASSET_PROCESSING_FAILED` appear (top-level `code`
    or `inputErrors`), and the status code of `RESTRICTED_MEMBER`.
14. The 422 `Content is a duplicate of urn:li:share:…` message. Only third-party reports exist.
15. The mention resolver lookups (`rest/vanityUrl?q=vanityUrlAsOrganization`, `rest/people/(id:…)`,
    `v2/organizations?q=vanityName`) with Rest.li 2.0 headers on 202605.
16. The first-comment recovery read (`GET /rest/socialActions/{urn}/comments`): its order and page
    size, and its answer without `r_member_social_feed` / `r_organization_social_feed`.
