<?php

namespace SocialPoster\Platforms\Pinterest;

/**
 * How Pin images reach Pinterest. Videos are always uploaded (POST /media,
 * then S3); this only concerns image and carousel Pins.
 *
 *  - Auto (default, config api.pinterest.image_transfer): a URL when the
 *    MediaGateway can provide one (remote media, or a gateway that re-hosts
 *    local files), else the bytes as base64. When Pinterest cannot fetch a
 *    URL, the create is retried once with base64, provided every image is a
 *    JPEG or PNG and they total at most api.pinterest.base64_max_bytes: at
 *    once after a fetch error ("could not fetch", codes 2786/2787); after a
 *    5xx (its ~7 s create deadline, but the Pin may have been saved anyway)
 *    only once a board lookup after verify_after seconds did not find it.
 *  - Url: always a URL (image_url / multiple_image_urls). A local file needs a
 *    gateway that re-hosts it (FilesystemMediaGateway).
 *  - Base64: always the bytes (image_base64 / multiple_image_base64). Pinterest
 *    takes only image/jpeg and image/png this way, so WebP cannot be sent.
 *
 * A carousel is never mixed: when one image has no URL, every image goes as
 * base64.
 */
enum PinterestImageTransfer: string
{
    case Auto = 'auto';
    case Url = 'url';
    case Base64 = 'base64';
}
