<?php

namespace SocialPoster\Capabilities;

final class ImageRules
{
    /**
     * @param  string[]|null  $extensions  Accepted file extensions (lowercase, no dot).
     * @param  string[]  $types  Accepted MIME types. Opt-in: empty means the extension
     *                           list is the only format gate. Set this only to additionally
     *                           enforce MIME when the inspector reports one.
     * @param  array{0: float, 1: float}|null  $aspectRatioRange  [min, max] width/height.
     * @param  int|null  $maxPixels  Upper bound on width x height (LinkedIn: 36,152,319).
     * @param  int|null  $maxWidth  Maximum width in pixels, after EXIF orientation.
     * @param  int|null  $maxHeight  Maximum height in pixels, after EXIF orientation.
     * @param  int|null  $minSizeBytes  Smallest accepted file (catches empty or truncated files).
     * @param  array<string, string>  $convert  Source extension => target extension a MediaTransformer
     *                                          may produce (e.g. ['png' => 'jpg']). A convertible
     *                                          extension passes validation when the bound transformer
     *                                          can convert it; the driver converts before upload.
     * @param  int|null  $minWidth  Minimum width in pixels, after EXIF orientation.
     * @param  int|null  $minHeight  Minimum height in pixels, after EXIF orientation.
     */
    public function __construct(
        public readonly ?array $extensions = null,
        public readonly array $types = [],
        public readonly ?int $maxSizeBytes = null,
        public readonly ?array $aspectRatioRange = null,
        public readonly ?int $maxPixels = null,
        public readonly ?int $maxWidth = null,
        public readonly ?int $maxHeight = null,
        public readonly ?int $minSizeBytes = null,
        public readonly array $convert = [],
        public readonly ?int $minWidth = null,
        public readonly ?int $minHeight = null,
    ) {}

    /** The extension $extension would be converted to, or null when it is accepted as is or not convertible. */
    public function conversionTarget(string $extension): ?string
    {
        $extension = strtolower($extension);

        if ($this->extensions === null || in_array($extension, $this->extensions, true)) {
            return null;
        }

        $target = $this->convert[$extension] ?? null;

        return is_string($target) && $target !== '' ? strtolower($target) : null;
    }
}
