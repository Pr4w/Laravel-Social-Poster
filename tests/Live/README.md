# Live checks

The unit and feature suites never leave the machine: every request is faked from
production-shaped fixtures. Some of those shapes could not be confirmed against the
real APIs when 1.1 was written. This directory holds the checks that confirm them,
against real accounts, when you choose to run them.

## Safety first

- **Nothing runs by default.** Each `*LiveTest.php` file looks for its
  `SOCIAL_LIVE_<PLATFORM>_*` variables when Pest collects it. If they are missing, it
  registers a single skipped placeholder and stops. A normal `vendor/bin/pest` run
  sends nothing anywhere.
- **Read-only probes** run as soon as a platform's variables are set. They only read
  the account: its profile, listings and limits.
- **Anything that creates content** also needs `SOCIAL_LIVE_PUBLISH=1`. Those tests use
  the least visible mode the platform has, and delete what they made wherever an API
  allows it:

  | Platform | Mode | Deleted afterwards |
  |---|---|---|
  | Facebook | scheduled post | yes |
  | Instagram | real post | **no, there is no delete API** |
  | Threads | real post | yes, with `threads_delete` |
  | X | real post | yes |
  | LinkedIn | connections-only post for a member | yes |
  | TikTok | inbox draft | n/a, it stays in the creator's inbox |
  | YouTube | private video | yes |
  | Bluesky | real post | yes |
  | Pinterest | Sandbox Pin | yes |

  Use dedicated test accounts.
- Some probes are billed or spend quota: X bills every read on pay-per-use plans, and
  every YouTube upload session costs `videos.insert` quota.
- Observations are written as JSON lines to `$SOCIAL_LIVE_OUTPUT/<platform>.jsonl`
  (default: `<system temp dir>/social-poster-live/`). They hold the status, the
  headers and the body, with tokens redacted. Read them after a run: several checks
  exist to record a shape, not to assert one.

## Running

```bash
# One platform, read-only probes:
SOCIAL_LIVE_X_TOKEN=... SOCIAL_LIVE_X_USER_ID=... vendor/bin/pest tests/Live/XLiveTest.php

# Including the checks that post (and clean up):
SOCIAL_LIVE_PUBLISH=1 SOCIAL_LIVE_X_TOKEN=... SOCIAL_LIVE_X_USER_ID=... vendor/bin/pest tests/Live/XLiveTest.php

# Everything you have credentials for:
vendor/bin/pest --testsuite=Live
```

Tests named `[manual] ...` are checklist entries that cannot be automated safely:
provoking a quota, a suspension or a server-side race. They always skip. Each one is
described below with the steps to verify it by hand. When a check is settled, update
the fixture or the driver it concerns, and remove the entry.

## Variables

| Platform | Required | Optional |
|---|---|---|
| Facebook | `SOCIAL_LIVE_FACEBOOK_PAGE_ID`, `SOCIAL_LIVE_FACEBOOK_PAGE_TOKEN` (Page token: `pages_manage_posts`, `pages_read_engagement`, and `pages_read_user_content` for the comments listing) | `..._IMAGE_URL`, `..._IMAGE_URL_2` (public JPEGs) |
| Instagram | `SOCIAL_LIVE_INSTAGRAM_ACCOUNT_ID`, `SOCIAL_LIVE_INSTAGRAM_TOKEN` | `..._LOGIN` (`instagram` or `facebook`; defaults to the token prefix), `..._IMAGE_URL`, `..._PNG_URL` |
| Threads | `SOCIAL_LIVE_THREADS_ACCOUNT_ID` (numeric Threads user id), `SOCIAL_LIVE_THREADS_TOKEN` | |
| X | `SOCIAL_LIVE_X_TOKEN` (OAuth 2.0 user token: `tweet.read tweet.write users.read media.write`), `SOCIAL_LIVE_X_USER_ID` | |
| LinkedIn | `SOCIAL_LIVE_LINKEDIN_TOKEN`, `SOCIAL_LIVE_LINKEDIN_AUTHOR` (`urn:li:person:…` or `urn:li:organization:…`) | |
| TikTok | `SOCIAL_LIVE_TIKTOK_TOKEN`, `SOCIAL_LIVE_TIKTOK_OPEN_ID` | `..._IMAGE_URL` (on a domain verified for the app) |
| YouTube | `SOCIAL_LIVE_YOUTUBE_TOKEN` (`youtube.upload`, plus `youtube.force-ssl` for the delete), `SOCIAL_LIVE_YOUTUBE_CHANNEL_ID` | `..._VIDEO_PATH` (a small local mp4) |
| Bluesky | `SOCIAL_LIVE_BLUESKY_DID`, plus `..._ACCESS_JWT` or `..._IDENTIFIER` + `..._APP_PASSWORD` | `..._PDS` (resolved from the DID otherwise) |
| Pinterest | `SOCIAL_LIVE_PINTEREST_TOKEN`, `SOCIAL_LIVE_PINTEREST_BOARD_ID` | `..._SANDBOX` (default `true`), `..._IMAGE_URL` |
| All | | `SOCIAL_LIVE_PUBLISH=1`, `SOCIAL_LIVE_OUTPUT=/path` |

## The open checks

**Automated** marks a check that a test runs or records; its name is in parentheses.
**Manual** marks a check you do by hand, with the steps to follow.

### Facebook

1. **Automated** (lists published_posts…). `GET /{page}/published_posts?fields=id,message,created_time,attachments{target{id},subattachments{target{id}}}&since=&limit=25`
   returns posts made through `/feed`, and `since` is honoured. The test "attaches
   unpublished photos…" records the subattachment target ids next to the
   `media_fbid`s of the unpublished `/photos` uploads, for one photo and for two.
   Confirm in the log that they are equal.
2. **Automated** (lists a just-scheduled /feed post…). `GET /{page}/scheduled_posts` lists a
   post scheduled a moment ago, with its message.
3. **Automated** (lists regular videos…). `GET /{page}/videos?fields=id,title,description,created_time&since=`.
   **Manual:** also schedule a regular video (`published=false`) and confirm that it is
   listed with its title and description.
4. **Manual.** Upload a Reel and a video story, then poll `GET /{video-id}?fields=status`.
   Record the shapes of `uploading_phase`, `processing_phase` and `publishing_phase`;
   whether failures use `errors: [{code, message}]` or `error`; the `publish_status`
   values (published, scheduled); and which signal marks a video story as live
   (`video_status: ready` or processing complete).
5. **Manual.** Rupload hosted-file upload: check for the `{"success":true}` body and the
   `debug_info` error shape (type `ProcessingFailedError`…). Confirm that a local upload
   with `offset: 0`, the `file_size` header and the raw body works for `/video_reels`
   and `/video_stories`.
6. **Manual.** Reel finish: is `post_id` returned bare, or absent? Are `title`,
   `video_state=SCHEDULED` with `scheduled_publish_time`, and extra keys such as
   `is_ai_generated` accepted? What happens when finish is called before the upload
   completes?
7. **Manual.** `/videos` accepts form-encoded `published='false'` with
   `scheduled_publish_time`, and multipart with thumb bytes still works.
8. **Automated** (attaches unpublished photos…). `/feed` with `published=false`,
   `scheduled_publish_time`, `unpublished_content_type=SCHEDULED` and `attached_media`
   of `temporary=true` photos.
9. **Manual.** `/photo_stories`: is the success value `true` or `"true"`? Is `post_id`
   bare?
10. **Automated, partly** (resolves the www.facebook.com/{id} permalink fallback…). Records
    whether the fallback resolves for a `{page}_{post}` id. **Manual:** check
    `permalink_url` on a Video node (relative `/{page}/videos/{id}/` or absolute), on a
    Comment node, and the fallback for a video id.
11. **Manual.** Do Page endpoints emit subcode 2207003 and the 1363xxx codes as `code` or
    as `error_subcode`? The same for 1609005 (an unreachable link).
12. **Automated, partly** (records the X-Business-Use-Case-Usage header…). Records the
    header format. **Manual:** on an 80001 throttle, confirm that
    `estimated_time_to_regain_access` is in minutes.
13. **Automated, partly** (lists the Page stories with post_id, media_id and creation_time…).
    `GET /{page}/stories?since=&limit=25` answers 200 with a `data` list; the photo-story
    recovery after an unanswered publish relies on it. **Manual:** publish a photo story and
    confirm that its listed `media_id` equals the id of the unpublished `/photos` upload it
    was made from (the driver recognises the story by it).
14. **Automated, partly** (reads a post's top-level comments newest first, with their
    author). `GET /{post}/comments?fields=id,message,from,created_time&filter=toplevel&order=reverse_chronological`
    answers 200 (the first-comment duplicate check reads it; it needs
    `pages_read_user_content`). **Manual:** confirm that a comment the Page made carries
    `from.id` = the Page id, and record whether `from` is present on comments by other
    users: the duplicate check matches a comment without `from` on its text alone, so an
    identical earlier comment by a user would be taken for the Page's own.

### Instagram

1. **Automated** (accepts Authorization: Bearer on GETs…). Bearer works for GETs on the
   account's host. **Manual:** POSTs (media, media_publish, comments) with Bearer on
   both hosts. The publishing test covers the account's own host. Production sends
   `access_token` as a parameter.
2. **Automated** (publishes a photo… sees its container turn PUBLISHED). After
   `media_publish`, the container's `status_code` turns `PUBLISHED`. Recovery relies on
   this. Run it once per login type.
3. **Automated** (accepts Authorization: Bearer on GETs…). `GET /{ig-user}/media?fields=id,caption,timestamp,permalink,media_type&limit=25`
   and `GET /{ig-user}/stories` work on the account's host.
4. **Automated** (records the 190 body…). The exact body when an IG token is sent to
   graph.facebook.com, or an EAA token to graph.instagram.com.
5. **Manual.** Container `ERROR` status text: is it `Media upload has failed with error code N`?
6. **Manual.** For carousel video children, is `media_type=REELS` accepted, and is `VIDEO`?
   (Configurable through `carousel_video_media_type`.)
7. **Manual.** In a JSON body: `collaborators` as an array, `share_to_feed` as a JSON
   boolean, `thumb_offset` in ms.
8. **Automated** for a single feed image (publishes a photo with alt text…). **Manual:**
   `alt_text` on carousel image children.
9. **Manual.** Is 2207051 a spam block (now Permanent `AccountRestricted`; v1.0 retried it
   as Temporary `RateLimited` after 120 s)?
10. **Automated** (accepts Authorization: Bearer on GETs…). The `content_publishing_limit`
    shape (`data[0].quota_usage`, `data[0].config.quota_total`). Run it once per login type.
11. **Manual.** `Http::pool` with concurrency 5 for a 10-item carousel does not trigger
    throttling.
12. **Automated** (still accepts a PNG image…). Meta still accepts PNG images. They stay
    accepted unless `jpeg_only` is set.

### Threads

1. **Automated** (accepts Authorization: Bearer on GETs…). Bearer on the profile, on
   `GET {account}/threads` and on `threads_publishing_limit`. The publishing test covers
   the container status (`fields=status,error_message`) and the permalink. If Bearer is
   refused, GETs must send `access_token` as a query parameter, redacted in logs.
2. **Manual.** POSTs carrying both the Bearer header and an `access_token` body field
   (JSON body) are accepted for creates, `threads_publish` and pooled carousel items.
   The text publish covers creates and `threads_publish`.
3. **Manual.** `poll_attachment` as a nested object `{option_a..option_d}` in a JSON body.
   Confirm Threads' own per-option length limit against the 25 characters the driver
   enforces (`ThreadsPoll::MAX_OPTION_LENGTH`).
4. **Manual.** `allowlisted_country_codes`: `'US,CA'` or an array?
5. **Manual.** Where does `is_spoiler_media` go on a carousel: the parent or each item? Is
   `topic_tag` accepted without `#`?
6. **Automated** (publishes a text post…). The new post shows up at once in
   `GET {account}/threads?fields=id,text,timestamp,permalink&limit=25`. Its text equals
   the submitted text after trim, emoji and newlines included. The timestamp is
   ISO-8601 with `+0000`.
7. **Automated** (publishes a text post…). The id that `threads_publish` returns is the
   media id that permalink lookups accept. **Manual:** check that `reply_to_id` accepts
   it too, with a first comment.
8. **Manual.** The error `threads_publish` returns for a container that is already
   `PUBLISHED`. The driver avoids this case by reading the status first.
9. **Manual.** The `THREADS_API__LINK_LIMIT_EXCEEDED` body shape. Post 6 distinct links;
   the fixture shape is a guess.
10. **Manual.** Does the 1920 px video limit apply to the display (rotation-corrected)
    width, or to the coded width?
11. **Manual.** Do TEXT reply containers ever report `IN_PROGRESS`, or no status? Does
    4279009 on a reply publish clear on retry?
12. **Manual.** The error code and body for the 250/24 h post quota and the 1,000/24 h reply
    quota.
13. **Manual.** `Http::pool` with 10 concurrent carousel-item creates is not throttled.

### X

1. **Automated** (posts, records the duplicate answer…). A re-send of identical text is
   refused as a 403 duplicate. **Manual:** the same check when the same `media_ids` are
   reused within the expiry window, and whether reusing a media id in a second post is
   refused on its own.
2. **Automated** for the duplicate body. **Manual:** the v2 bodies for locked and
   suspended accounts, 402 `CreditsDepleted` (pay-per-use), 429 usage-capped, the
   Feb 2026 reply restriction, and the 403 for a video above the account's duration cap.
3. **Automated, partly** (posts, records…). Records the `x-user-limit-24hour-*` and
   `x-app-limit-24hour-*` headers. **Manual:** their meaning on a 429 for `POST /2/tweets`.
4. **Manual.** Can `GET /2/media/upload?command=STATUS` omit `processing_info`? It is
   treated as ready.
5. **Manual.** Does `POST /2/media/metadata` alt text work on a GIF after processing? Is it
   refused or ignored for video?
6. **Manual.** Does `APPEND` accept a re-sent `segment_index` after a lost connection?
   (Segments never exceed 5 MB: the driver uses up to 10000 of them, 4 MB by default, which
   covers files up to about 42 GB, and caps `api.x.chunk_size` at 5 MB.)
7. **Automated** (records the user timeline format…). `GET /2/users/{id}/tweets` with
   `start_time`, `exclude=retweets` and `tweet.fields=attachments,note_tweet`: the
   `media_keys` format `{type}_{media_id}` (`3_` photo, `7_` video, `16_` GIF), `&amp;`
   entities in the text, and `note_tweet.text` for long posts.
8. **Manual.** Is `quote_tweet_id` accepted together with media? Do poll options count as
   25 characters by code point?
9. **Manual.** 4K videos, and extreme aspect ratios within 1:3 to 3:1. Only 1080p is
   production-proven.
10. **Manual.** Does an OAuth 2.0 user token need `media.write` for one-shot image upload,
    for INITIALIZE/APPEND/FINALIZE/STATUS, and for metadata?

### LinkedIn

1. **Manual.** Does the 3,000-character limit count the visible text or the raw little-text
   string (finding 24)? The publishing test records an escaped post. Repeat it with a
   caption whose visible text is 3,000 characters but whose escaped form is longer.
2. **Automated** (reads posts by author…, and publishes a post… read back).
   `GET /rest/posts?q=author&author=…&sortBy=LAST_MODIFIED` with
   `X-RestLi-Method: FINDER` on version 202605, and whether the commentary that comes
   back matches the little text sent.
3. **Manual.** Video thumbnail `PUT` (media-type-family `STILLIMAGE`,
   `application/octet-stream`, 201) before `finalizeUpload`, and a non-empty
   `uploadToken` accepted in finalize.
4. **Manual.** `uploadUrlsExpireAt` is in milliseconds, and an expired part URL answers 401
   (the restart path).
5. **Manual.** Concurrent part PUTs (`upload_concurrency` > 1) against real upload URLs.
6. **Manual.** Documents reach `AVAILABLE` in reasonable time. v1.0 and the app posted
   while still `PROCESSING`.
7. **Manual.** Poll content shape `{question, options[{text}], settings{duration}}` and its
   limits on 202605.
8. **Manual.** Article content: is the title required? What are the real title and
   description limits? Auto cards clip to 200/300.
9. **Manual.** `feedDistribution: NONE` and `targetEntities` payloads for organization
   authors; `visibility` `CONNECTIONS` or `LOGGED_IN` per author type.
10. **Automated** (publishes a post with a first comment…). The comment create response:
    `x-restli-id` is the bare id, `commentUrn` is in the body, and the `?commentUrn=`
    permalink works with a share URN. Check the recorded result.
11. **Manual.** Can `w_member_social` alone (without `w_member_social_feed`) create
    comments?
12. **Manual.** The daily throttle message format contains `DAY` (e.g. `Resource level
    throttle APPLICATION DAY limit …`).
13. **Manual.** Where do `MEDIA_ASSET_WAITING_UPLOAD` and `MEDIA_ASSET_PROCESSING_FAILED`
    appear: top-level `code` or `inputErrors`? Also record the `RESTRICTED_MEMBER` status
    code.
14. **Manual.** The 422 `Content is a duplicate of urn:li:share:…` message. Only
    third-party reports exist so far.
15. **Manual.** `rest/vanityUrl?q=vanityUrlAsOrganization`, `rest/people/(id:…)` and
    `v2/organizations?q=vanityName` with Rest.li 2.0 headers on 202605. These are the
    mention resolver lookups.

### TikTok

1. **Manual.** `DIRECT_POST` with `brand_content_toggle=false` and `brand_organic_toggle=false`
   on an unaudited (`SELF_ONLY`) client, for video and for photo. Commit 18162da saw
   `invalid_params` there, while production (an audited client) sends them.
2. **Automated** (sends a caption-less draft photo…). A `MEDIA_UPLOAD` photo with no caption
   or title: TikTok must accept `post_info` as `{}`.
3. **Manual.** The upload host's `Content-Range` format on 416 (`bytes 0-N/size` or
   `bytes */size`), and whether a re-sent chunk TikTok already holds returns 416, 206
   or 201.
4. **Manual.** Does the upload host ever answer 200 instead of 206 or 201? A final 200
   without `Content-Range` is treated as complete.
5. **Manual.** Does an orphaned `FILE_UPLOAD` init count against
   `spam_risk_too_many_pending_share` for drafts? (An orphaned init is one re-run after an
   interrupted init or after a restart.)
6. **Manual.** Does the `url_ownership_unverified` fallback to `FILE_UPLOAD` (inbox and
   direct `video/init`) work on the same account?
7. **Manual.** Does `publicaly_available_post_id` arrive as a JSON number or a string? Both
   are handled. How long after `PUBLISH_COMPLETE` does it appear (`public_id_wait` 600)?
8. **Manual.** Is `is_aigc` accepted on `DIRECT_POST` `video/init` (in post_info) and on
   photo `content/init` (top level, direct and draft)? It is not sent on draft videos, which
   carry no post_info.
9. **Manual.** The image size limit on photos ("1080p"): which dimensions trigger
   `picture_size_check_failed`?
10. **Manual.** The webhook signature header is exactly `t=<ts>,s=<hex>`, signed over
    `"{t}.{raw body}"`, and `authorization.removed` `content.reason` is numeric. Check
    against `TikTokWebhook::verify()` with a real delivery. Which spelling does the removal
    webhook use (`post.publish.no_longer_publicaly_available` as documented, or the
    corrected one)? `TikTokWebhookEvent::is()` accepts both.
11. **Automated** (queries creator_info with a null body…). `creator_info` still accepts the
    null body. **Manual:** HTTP-200 error codes from `creator_info` stop the publish as
    expected.
12. **Automated** (answers an invalid token with HTTP 401…). Bearer-token rejection:
    `access_token_invalid` comes back as HTTP 401. This drives the reresolve path.

### YouTube

1. **Automated, partly** (opens a resumable session… status query with 308). A status
   query on a fresh session answers 308 with no `Range`. **Manual:** the `Range: bytes=0-N`
   format after a partial upload, and that a finished session replays the original
   201/200 video resource.
2. **Manual.** What YouTube returns for a chunk sent from a stale (lower) offset: a 400
   (handled by the session re-check) or a 308 with the real `Range`.
3. **Automated** (opens a resumable session on a googleapis.com host…). The session
   `Location` host is always `*.googleapis.com`. The driver refuses any other host.
4. **Automated, partly** (opens a resumable session…, uploads a private video…).
   `notifySubscribers=false` on the session POST. **Manual:** `onBehalfOfContentOwner`
   (+Channel), and the full part list
   `snippet,status,recordingDetails,localizations,paidProductPlacementDetails,brandPartner`.
5. **Manual.** `videos.insert` error shapes and statuses in practice: `uploadLimitExceeded`
   as HTTP 400, at session open or on the final PUT; `quotaExceeded` on the session POST;
   the ErrorInfo details `SERVICE_DISABLED`, `ACCESS_TOKEN_EXPIRED` and
   `ACCESS_TOKEN_SCOPE_INSUFFICIENT`.
6. **Manual.** The daily quota resets at midnight America/Los_Angeles, as used for
   `retry_at`.
7. **Manual.** Short thumbnails: `thumbnails.set` applies only after
   `processingDetails.processingStatus=succeeded`; 404 `videoNotFound` and 429
   `uploadRateLimitExceeded` right after the upload.
8. **Manual.** `playlistItems.insert` immediately after the upload (`videoNotFound` timing),
   and with a `youtube.upload`-only token (403 `insufficientPermissions`).
9. **Manual.** `commentThreads.insert` on a fresh upload: how long `videoNotFound` lasts;
   whether the top-level comment id equals the thread id; the 403 `forbidden` versus
   `commentsDisabled` reasons; refusal on `madeForKids` videos.
10. **Manual.** Range GET support and ETag stability on your media hosts (S3/R2/CDN signed
    URLs), and the 206 `Content-Range` format.
11. **Automated, partly** (uploads a private video…). The recorded result carries
    `privacy_warning` when YouTube applied another privacy. **Manual:** an unaudited
    project forcing private on a public upload.
12. **Manual.** The `rejectionReason` `length` wording, and the 15-minute limit for channels
    that are not verified.

### Bluesky

1. **Automated** (posts, records the error for a second create on the same rkey…). The
   exact error for `createRecord` on an existing rkey. 400 `InvalidRequest` or
   `InvalidSwap` is assumed; both are handled by a `getRecord` lookup.
2. **Manual.** The `BlobNotFound` error name and message for a garbage-collected temp blob
   (handled: code `BlobNotFound`, or 400 `Could not find blob`).
3. **Manual.** DPoP against a bsky.social PDS: the 401 with `WWW-Authenticate: use_dpop_nonce`
   plus `DPoP-Nonce`, the retry, and per-origin nonce reuse. Also, does the OAuth token
   work on the resolved PDS host rather than the entryway?
4. **Manual.** `getServiceAuth` with an OAuth token (scope `transition:generic`, and
   granular scopes): `lxm com.atproto.repo.uploadBlob` with `aud did:web:<PDS host>`, and
   `lxm app.bsky.video.getUploadLimits` with `aud did:web:video.bsky.app`.
5. **Manual.** The video service's real status codes and messages for an unverified email
   and for the daily quota (the message rules are guesses taken from the app), and the
   HTTP status of `already_exists`.
6. **Manual.** Is a deterministic reply rkey (a TID 1-2 s after the parent's, with a later
   `createdAt`) accepted by the PDS and indexed normally by the AppView?
7. **Manual.** Video alt text over 1000 graphemes; caption blobs uploaded with
   `Content-Type: text/vtt`; `embed.video` presentation `gif`; a threadgate/postgate
   written with `applyWrites` on the same rkey.
8. **Manual.** The current limits: 300 MB / 10 min video on bsky.social and 2 MB images (the
   defaults stay 100 MB / 180 s / 1 MB), plus self-hosted PDS behaviour.
9. **Automated, partly** (records the app-password session lifetime). `createSession` on
   the PDS and the real `accessJwt` lifetime (`session_ttl` defaults to 3600).
   **Manual:** the same on the entryway, and the `ExpiredToken` re-login path.
10. **Automated** (resolves the PDS from plc.directory…, reads a record… through the public
    AppView). plc.directory accepts the raw `did:plc` path, and the AppView serves
    `com.atproto.repo.getRecord` for other people's repos.
11. **Manual.** Link-card behaviour on real sites: charset handling, `og:image:secure_url`,
    CDN redirects.

### Pinterest

1. **Manual.** Is a `video_id` Pin with only `cover_image_key_frame_time` (no
   `cover_image_url`) accepted on production, or does it return the misleading 401
   reported on the forums? Does the `GET media/{id}` probe then return 200?
2. **Manual.** Real error codes and messages: 404 board-not-found, 403 "image too
   small/large/broken", 429, and whether `Retry-After` or `x-ratelimit-reset` is sent.
   The fixtures use the official `{code, message}` shape with assumed codes (40, 3, 8).
3. **Manual.** The exact Trial-access 403 code 29 message, and that it never comes with
   another status. To reproduce, use a Trial app with `SOCIAL_LIVE_PINTEREST_SANDBOX=false`.
4. **Manual.** Codes 2786/2787, 503 code 21 and 500 code 12 from the ~7 s create deadline.
   These come from community reports and only trigger the Auto base64 retry.
5. **Automated** (reads the board and its Pins…, pins an image, finds it first…). A new Pin
   shows up at once in `GET boards/{board}/pins`, first. Its title and description come
   back byte-identical. The `created_at` format and timezone are recorded. **Manual:**
   the same for `sections/{section}/pins`.
6. **Manual.** Does `GET boards/{board}/pins` also list Pins saved into a section?
7. **Manual.** The S3 upload: the streamed multipart (fields first, file last, with a
   filename) answers 204, and an expired policy returns `AccessDenied`.
8. **Manual.** Does video upload (`POST /media`) work in the Sandbox?
9. **Manual.** Does Pinterest use `alt_text` on video Pins? Does it use per-item
   title/description/link on `multiple_image_urls` and base64 carousels?
10. **Manual.** The maximum JSON body size of `POST /pins` for `multiple_image_base64`
    (5 × 20 MB).
11. **Manual.** Is `ad_account_id` honoured on `boards/{board}/pins` (Business Access), as it
    is on `pins/create`?
12. **Manual.** The real organic video maximum duration: 900 s, or the 5 minutes the help
    pages state?
