<?php

namespace justinholtweb\microscope\checks\craft;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * How and when Craft generates image transforms.
 *
 * Images are usually the largest thing on a page by bytes, and transform generation is the most
 * CPU-expensive thing Craft does. Getting the *timing* right — out of the request — matters more
 * than any individual setting.
 */
class ImageHandlingCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'imageHandling';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Image handling');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks the image driver, when transforms are generated, and whether Craft is optimising the files it produces.');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $findings = [];

        if ($craft->generateTransformsBeforePageLoad()) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'Image transforms are generated during page requests'),
                'summary' => Craft::t('microscope', '`generateTransformsBeforePageLoad` is on, so every missing transform is created before the page can finish rendering.'),
                'observed' => 'generateTransformsBeforePageLoad = true',
                'expected' => 'false',
                'target' => 'generateTransformsBeforePageLoad',
                'impact' => Craft::t('microscope', 'The first visitor to any page with new images waits for every one of them to be resized — inside their request, holding a PHP worker for the duration. A listing page with thirty new images can take tens of seconds, and on a busy site enough of those in parallel will exhaust the worker pool.'),
                'remediation' => "```php\n// config/general.php\n'generateTransformsBeforePageLoad' => false,\n```\n\nWith this off, Craft returns a URL that points at its own transform generator. The browser requests the image, Craft generates it then, and every subsequent request is served straight from the volume. The page itself renders immediately.\n\n**The trade-off**, worth knowing before you flip it: the very first request for each image is slower, and `getWidth()` / `getHeight()` on a not-yet-generated transform have to fall back to computing dimensions from the source. If you rely on those in markup — and you should, for layout stability — define your transforms as **named transforms** in **Settings → Assets → Image Transforms** rather than inline, so Craft knows their dimensions without generating anything.\n\nFor a site where the first-visit delay is unacceptable, pre-generate instead of switching this off:\n\n```bash\nphp craft resave/assets --set-transform thumb\n```",
                'docsUrl' => 'https://craftcms.com/docs/5.x/reference/config/general.html#generatetransformsbeforepageload',
            ]);
        }

        if ($craft->imageDriver() === 'GD') {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Craft is using GD for image transforms'),
                'summary' => Craft::t('microscope', 'GD is the fallback driver. Imagick produces better output and uses far less memory on large files.'),
                'observed' => 'GD',
                'expected' => 'Imagick',
                'target' => 'imageDriver',
                'impact' => Craft::t('microscope', 'GD decompresses the entire source image into PHP’s memory — width × height × 4 bytes, so a 6000×4000 photo needs about 96 MB before any work starts. It also handles colour profiles and progressive JPEGs poorly, producing larger files that look worse.'),
                'remediation' => "Install Imagick and select it:\n\n```bash\nsudo apt install php8.3-imagick\n```\n\n```php\n// config/general.php\n'imageDriver' => 'imagick',\n```\n\nIf Imagick isn't available, limit what GD is asked to do:\n\n```php\n// config/general.php\n'maxUploadFileSize' => 1024 * 1024 * 32,\n'transformGifs' => false,\n'transformSvgs' => false,\n```",
            ]);
        }

        if (!$craft->optimizeImageFilesize()) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Transformed images aren’t being optimised'),
                'summary' => Craft::t('microscope', '`optimizeImageFilesize` is off, so Craft writes transforms without stripping metadata or tuning compression.'),
                'observed' => 'optimizeImageFilesize = false',
                'expected' => 'true',
                'target' => 'optimizeImageFilesize',
                'impact' => Craft::t('microscope', 'Transformed images carry EXIF data, colour profiles and thumbnails that no browser needs — commonly 10–20% of the file size, on the largest assets a page downloads.'),
                'remediation' => "```php\n// config/general.php\n'optimizeImageFilesize' => true,\n```\n\nThis is Craft's default, so it has been turned off deliberately — occasionally for good reason, if the site serves photography where colour profiles matter. If that's the case, leave it and instead set the quality per transform:\n\n```twig\n{{ image.getUrl({ width: 1200, quality: 82, format: 'webp' }) }}\n```\n\nWebP at quality 80–85 is typically 30% smaller than JPEG at visually identical quality, and Craft will fall back to the original format for browsers that can't take it.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Image handling is well configured'),
                $craft->imageDriver() . Craft::t('microscope', ', transforms generated outside the request'),
            );
        }

        return $findings;
    }
}
