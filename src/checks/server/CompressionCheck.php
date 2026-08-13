<?php

namespace justinholtweb\microscope\checks\server;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Ini;
use justinholtweb\microscope\models\Category;

/**
 * Whether responses are being compressed on the way out.
 *
 * Craft's HTML is verbose and compresses extremely well — 70–80% is typical — so this is one of
 * the largest wins available for the least work. The awkward part is that it's configured on the
 * web server, which PHP can't reliably see, so this check reports what it can establish and is
 * explicit about what it can't.
 */
class CompressionCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'compression';
    }

    public function category(): string
    {
        return Category::SERVER;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Response compression');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks whether HTML responses are being gzip- or Brotli-compressed before they go over the wire.');
    }

    public function run(CheckContext $context): array
    {
        $findings = [];
        $server = $context->server;

        // The one thing knowable for certain from inside PHP: whether the response this very
        // request is producing is being compressed by PHP itself.
        $phpCompression = Ini::bool('zlib.output_compression');

        // And what the web server said about the request that's running right now.
        $acceptedEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null;
        $brotli = $server->nginxHasModule('brotli');

        if ($brotli === false) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Brotli compression is not available'),
                'summary' => Craft::t('microscope', 'nginx was built without the Brotli module, so responses can only be gzipped.'),
                'observed' => Craft::t('microscope', 'gzip only'),
                'expected' => Craft::t('microscope', 'Brotli, falling back to gzip'),
                'target' => 'server.brotli',
                'impact' => Craft::t('microscope', 'Brotli produces files roughly 15–20% smaller than gzip at comparable CPU cost, and every browser in current use supports it. On a text-heavy page that is a meaningful slice of the transfer time, particularly on mobile connections.'),
                'remediation' => $this->brotliInstructions(),
            ]);
        }

        if ($phpCompression) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'PHP is compressing output itself'),
                'summary' => Craft::t('microscope', '`zlib.output_compression` is on, so PHP compresses responses rather than the web server.'),
                'observed' => 'zlib.output_compression=On',
                'expected' => Craft::t('microscope', 'Compression handled by nginx or Apache'),
                'target' => 'zlib.output_compression',
                'impact' => Craft::t('microscope', 'It works, but it does the compression inside the PHP worker — occupying a worker that could be serving another request — and it applies to PHP responses only, leaving CSS, JavaScript and SVG uncompressed. It also interferes with streamed responses and with `Content-Length`.'),
                'remediation' => "Turn it off in `php.ini` and let the web server do it for every response type:\n\n```ini\nzlib.output_compression = Off\n```\n\nThen configure compression at the server — see the gzip and Brotli configuration below.",
            ]);
        }

        // Without a shell, nginx's config is invisible — say so rather than guessing.
        if ($brotli === null) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Compression settings couldn’t be verified'),
                'summary' => Craft::t('microscope', 'Microscope can’t read the web server’s configuration from here, so this needs checking by hand.'),
                'observed' => $server->webServer() ?? Craft::t('microscope', 'Unknown web server'),
                'expected' => Craft::t('microscope', 'gzip or Brotli on HTML, CSS, JS and SVG'),
                'target' => 'server.compression',
                'impact' => Craft::t('microscope', 'Uncompressed HTML is typically three to four times larger than it needs to be. If compression is off, it is one of the biggest single improvements available — and it is entirely possible for it to be off without anyone noticing, because nothing breaks.'),
                'remediation' => $this->verifyInstructions() . "\n\n" . $this->gzipInstructions(),
            ]);
        } elseif ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Compression is available'),
                Craft::t('microscope', 'Brotli and gzip'),
                Craft::t('microscope', 'Confirm it’s applied to HTML, CSS, JS and SVG — the module being present doesn’t guarantee it’s switched on for every type.'),
            );
        }

        return $findings;
    }

    private function verifyInstructions(): string
    {
        return <<<'MD'
**Check it in ten seconds** from any machine:

```bash
curl -sI -H 'Accept-Encoding: gzip, br' https://your-site.example/ | grep -i content-encoding
```

`content-encoding: br` or `content-encoding: gzip` means it's working. No such header means
responses are going out uncompressed.

Repeat it for a stylesheet and a JavaScript file — it's common for HTML to be compressed while
static assets are not.
MD;
    }

    private function gzipInstructions(): string
    {
        return <<<'MD'
**nginx** — in `http {}` or the server block:

```nginx
gzip on;
gzip_comp_level 5;
gzip_min_length 256;
gzip_proxied any;
gzip_vary on;
gzip_types
  text/plain text/css text/xml
  application/javascript application/json application/xml
  application/rss+xml image/svg+xml font/woff2;
```

`text/html` is always compressed and cannot be listed — nginx rejects the config if you try.
`gzip_vary on` is important behind a CDN: without it, a cached compressed response can be served
to a client that didn't ask for compression.

**Apache** — with `mod_deflate`:

```apache
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml \
    application/javascript application/json application/xml image/svg+xml
</IfModule>
```

**Don't compress images or video.** JPEG, PNG, WebP and MP4 are already compressed; running them
through gzip spends CPU to make them very slightly larger.
MD;
    }

    private function brotliInstructions(): string
    {
        return <<<'MD'
Brotli needs a module nginx isn't built with by default.

**Debian/Ubuntu** with the official nginx repository:

```bash
sudo apt install libnginx-mod-brotli
```

Then in `nginx.conf`:

```nginx
brotli on;
brotli_comp_level 5;
brotli_static on;
brotli_types
  text/plain text/css text/xml
  application/javascript application/json application/xml
  application/rss+xml image/svg+xml font/woff2;
```

Keep gzip configured alongside it — nginx picks Brotli for clients that advertise it and falls
back to gzip for anything that doesn't.

`brotli_static on` is worth having: if a `file.css.br` exists next to `file.css`, nginx serves the
pre-compressed one directly. Compressing your built assets at build time with maximum effort
(`brotli -Z`) gives better ratios than anything achievable per-request.

**If a CDN sits in front of the site**, this may be moot — Cloudflare, Fastly and CloudFront all
compress at the edge, and configuring it at the origin as well only helps the origin-to-edge hop.
MD;
    }
}
