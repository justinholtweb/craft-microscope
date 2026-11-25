<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * How Craft generates image transforms.
 *
 * Images are usually the largest thing on a page by bytes, and transform generation is the most
 * CPU-expensive thing Craft does. (Craft 5's `generateTransformsBeforePageLoad` is gone in Craft 6,
 * so there's no request-timing setting left to check.)
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
        return t('Image handling', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks the image driver and whether Craft is optimising the files it produces.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $findings = [];

        if ($craft->imageDriver() === 'GD') {
            $findings[] = $this->notice([
                'title' => t('Craft is using GD for image transforms', category: 'microscope'),
                'summary' => t('GD is the fallback driver. Imagick produces better output and uses far less memory on large files.', category: 'microscope'),
                'observed' => 'GD',
                'expected' => 'Imagick',
                'target' => 'imageDriver',
                'impact' => t('GD decompresses the entire source image into PHP’s memory — width × height × 4 bytes, so a 6000×4000 photo needs about 96 MB before any work starts. It also handles colour profiles and progressive JPEGs poorly, producing larger files that look worse.', category: 'microscope'),
                'remediation' => "Install Imagick and select it:\n\n```bash\nsudo apt install php8.3-imagick\n```\n\n```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->imageDriver('imagick');\n```\n\nIf Imagick isn't available, limit what GD is asked to do:\n\n```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->maxUploadFileSize(1024 * 1024 * 32)\n    ->transformGifs(false)\n    ->transformSvgs(false);\n```",
            ]);
        }

        if (! $craft->optimizeImageFilesize()) {
            $findings[] = $this->notice([
                'title' => t('Transformed images aren’t being optimised', category: 'microscope'),
                'summary' => t('`optimizeImageFilesize` is off, so Craft writes transforms without stripping metadata or tuning compression.', category: 'microscope'),
                'observed' => 'optimizeImageFilesize = false',
                'expected' => 'true',
                'target' => 'optimizeImageFilesize',
                'impact' => t('Transformed images carry EXIF data, colour profiles and thumbnails that no browser needs — commonly 10–20% of the file size, on the largest assets a page downloads.', category: 'microscope'),
                'remediation' => "```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->optimizeImageFilesize(true);\n```\n\nThis is Craft's default, so it has been turned off deliberately — occasionally for good reason, if the site serves photography where colour profiles matter. If that's the case, leave it and instead set the quality per transform:\n\n```twig\n{{ image.getUrl({ width: 1200, quality: 82, format: 'webp' }) }}\n```\n\nWebP at quality 80–85 is typically 30% smaller than JPEG at visually identical quality, and Craft will fall back to the original format for browsers that can't take it.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('Image handling is well configured', category: 'microscope'),
                $craft->imageDriver().t(', optimised output', category: 'microscope'),
            );
        }

        return $findings;
    }
}
