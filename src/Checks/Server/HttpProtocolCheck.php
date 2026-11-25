<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Server;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * The HTTP version and transport the site is served over.
 *
 * Only meaningful on a web request — from the console there is no request to describe, so the
 * check skips rather than reporting the absence of a protocol as a problem.
 */
class HttpProtocolCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'httpProtocol';
    }

    public function category(): string
    {
        return Category::SERVER;
    }

    public function label(): string
    {
        return t('HTTP protocol', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks the HTTP version and whether the connection is secure.', category: 'microscope');
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $context->php->isWebSapi() && $context->server->protocol() !== null;
    }

    public function unavailableReason(CheckContext $context): string
    {
        return t('This scan ran from the command line, where there is no HTTP request to inspect. Run a scan from the control panel to check the protocol.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $protocol = $context->server->protocol();
        $findings = [];

        if ($protocol !== null && str_contains($protocol, 'HTTP/1')) {
            $findings[] = $this->warning([
                'title' => t('The site is being served over HTTP/1.1', category: 'microscope'),
                'summary' => t('This request arrived as {protocol}. HTTP/2 has been universally supported for years.', [
                    'protocol' => $protocol,
                ], category: 'microscope'),
                'observed' => $protocol,
                'expected' => 'HTTP/2 or HTTP/3',
                'target' => 'server.protocol',
                'impact' => t('HTTP/1.1 allows about six connections per host and no multiplexing, so a page with thirty assets serialises them into queues. HTTP/2 sends them all down one connection concurrently, with header compression — on an asset-heavy page it typically removes hundreds of milliseconds without a single change to the site itself.', category: 'microscope'),
                'remediation' => $this->instructions(),
                'docsUrl' => 'https://nginx.org/en/docs/http/ngx_http_v2_module.html',
            ]);
        }

        $isSecure = ($_SERVER['HTTPS'] ?? '') !== ''
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

        if (! $isSecure && $context->isProduction()) {
            $findings[] = $this->critical([
                'title' => t('The control panel was reached over plain HTTP', category: 'microscope'),
                'summary' => t('This request wasn’t encrypted.', category: 'microscope'),
                'observed' => 'http://',
                'expected' => 'https://',
                'target' => 'server.https',
                'impact' => t('Beyond the obvious security problem — a control panel login in clear text — HTTP/2 is only negotiated over TLS in every browser, so an unencrypted site cannot use it at all. Every performance benefit above depends on fixing this first.', category: 'microscope'),
                'remediation' => "Get a certificate and redirect everything to it. Let's Encrypt makes this free and automatic:\n\n```bash\nsudo certbot --nginx -d example.com -d www.example.com\n```\n\nThen make the site's own URLs HTTPS, so Craft and Laravel generate them that way:\n\n```bash\n# .env\nAPP_URL=https://example.com\n```\n\n(Behind a load balancer or proxy that terminates TLS, also trust it — `TrustProxies` middleware — or Laravel will think every request is plain HTTP.)\n\nand redirect at the server rather than in PHP, so the redirect costs nothing:\n\n```nginx\nserver {\n    listen 80;\n    server_name example.com www.example.com;\n    return 301 https://\$host\$request_uri;\n}\n```\n\nIf the site sits behind a load balancer or CDN that terminates TLS, this check may be a false alarm — but only if Craft is configured to trust the proxy's headers. Without that, Craft generates `http://` URLs for a site served over `https://`, which breaks in its own ways. See `config/general.php`'s `trustedHosts` / Yii's `request` component configuration.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('Modern HTTP over TLS', category: 'microscope'),
                $protocol,
            );
        }

        return $findings;
    }

    private function instructions(): string
    {
        return <<<'MD'
**nginx** — HTTP/2 requires TLS in practice, so it goes on the `listen` directive of the SSL
server block:

```nginx
server {
    listen 443 ssl;
    http2 on;             # nginx 1.25.1+
    # listen 443 ssl http2;   # earlier versions
    …
}
```

For HTTP/3 (QUIC), on nginx 1.25+ built with it:

```nginx
listen 443 quic reuseport;
listen 443 ssl;
http2 on;
add_header Alt-Svc 'h3=":443"; ma=86400';
```

**Apache** — with `mod_http2`:

```apache
Protocols h2 http/1.1
```

Restart the server, then confirm:

```bash
curl -sI --http2 https://your-site.example/ | head -1
```

You should see `HTTP/2 200`.

**One thing to undo afterwards**: if the front end was optimised for HTTP/1.1 — sprite sheets,
concatenating every stylesheet into one file, sharding assets across subdomains — those are now
counterproductive. HTTP/2 prefers several small cacheable files over one large one, because a
one-line CSS change no longer invalidates the whole bundle.
MD;
    }
}
