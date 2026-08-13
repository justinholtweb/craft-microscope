<?php

namespace justinholtweb\microscope\checks\php;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;
use justinholtweb\microscope\models\Category;

/**
 * Execution time, input variables and upload sizes.
 *
 * These aren't speed settings — they're the settings that decide whether slow operations complete
 * at all. A site whose `max_input_vars` is too low loses field data on save with no error, which
 * is the kind of problem people spend a day on before thinking to check php.ini.
 */
class RequestLimitsCheck extends BaseCheck
{
    private const MIN_EXECUTION_TIME = 120;
    private const MIN_INPUT_VARS = 3000;
    private const MIN_UPLOAD = 20 * 1024 * 1024;

    public function handle(): string
    {
        return 'requestLimits';
    }

    public function category(): string
    {
        return Category::PHP;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Request limits');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks execution time, input variable and upload limits against what the control panel needs to save large entries and assets.');
    }

    public function run(CheckContext $context): array
    {
        $php = $context->php;
        $findings = [];

        $executionTime = $php->maxExecutionTime();

        // 0 means no limit, which is normal and correct on the CLI.
        if ($executionTime !== null && $executionTime > 0 && $executionTime < self::MIN_EXECUTION_TIME && $php->isWebSapi()) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'PHP execution time limit is short'),
                'summary' => Craft::t('microscope', 'Requests are cut off after {observed} seconds.', ['observed' => $executionTime]),
                'observed' => $executionTime . 's',
                'expected' => self::MIN_EXECUTION_TIME . 's+',
                'target' => 'max_execution_time',
                'impact' => Craft::t('microscope', 'Long-running control panel operations — applying project config, running migrations, resaving elements, generating a batch of image transforms — get killed part way through. Craft handles that gracefully in most places, but a project config apply interrupted halfway is genuinely awkward to recover from.'),
                'remediation' => "Raise it for web requests:\n\n```ini\nmax_execution_time = 120\n```\n\nIf a web server sits in front of PHP-FPM, it has its own timeout that has to be raised to match — PHP finishing the work is no help if nginx has already given up on the response:\n\n```nginx\n# nginx\nfastcgi_read_timeout 120s;\n```\n\n```apache\n# Apache with mod_proxy_fcgi\nProxyTimeout 120\n```\n\nFor operations that genuinely take minutes, run them from the console instead, where PHP imposes no time limit by default:\n\n```bash\nphp craft project-config/apply\nphp craft resave/entries\n```",
            ]);
        }

        $inputVars = $php->maxInputVars();

        if ($inputVars !== null && $inputVars < self::MIN_INPUT_VARS) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'max_input_vars is low for the control panel'),
                'summary' => Craft::t('microscope', 'PHP accepts {observed} form fields per request. Craft’s entry editor exceeds that on any entry with a large Matrix field.', [
                    'observed' => number_format($inputVars),
                ]),
                'observed' => number_format($inputVars),
                'expected' => number_format(self::MIN_INPUT_VARS) . '+',
                'target' => 'max_input_vars',
                'impact' => Craft::t('microscope', 'This one fails silently, which is what makes it worth fixing before it bites: PHP discards every field past the limit without an error, so the entry saves successfully and quietly loses content. Editors report it as "Craft deleted my blocks".'),
                'remediation' => "```ini\nmax_input_vars = 5000\n```\n\nRestart PHP-FPM afterwards. Note that `suhosin`, if installed, has its own equivalent (`suhosin.post.max_vars` and `suhosin.request.max_vars`) which must be raised as well.\n\nTo see whether you're anywhere near the limit, count the inputs on your largest entry form in the browser console:\n\n```js\ndocument.querySelectorAll('#main-form [name]').length\n```",
                'docsUrl' => 'https://craftcms.com/knowledge-base/matrix-blocks-disappearing',
            ]);
        }

        $upload = $php->uploadMaxFilesize();
        $post = $php->postMaxSize();

        if ($upload !== null && $upload < self::MIN_UPLOAD) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Upload size limit is small'),
                'summary' => Craft::t('microscope', 'Assets larger than {observed} can’t be uploaded through the control panel.', [
                    'observed' => Bytes::format($upload),
                ]),
                'observed' => Bytes::format($upload),
                'expected' => Bytes::format(self::MIN_UPLOAD) . '+',
                'target' => 'upload_max_filesize',
                'impact' => Craft::t('microscope', 'Modern camera images and any video routinely exceed a few megabytes. The upload fails at the PHP layer before Craft sees it, so the error message is unhelpful.'),
                'remediation' => "Both directives matter, and `post_max_size` must be the larger of the two — it caps the entire request body, uploads included:\n\n```ini\nupload_max_filesize = 64M\npost_max_size = 72M\n```\n\nThe web server has its own limit too:\n\n```nginx\nclient_max_body_size 72m;\n```",
            ]);
        }

        // A post_max_size below upload_max_filesize makes the upload limit a lie.
        if ($upload !== null && $post !== null && $post > 0 && $post < $upload) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'post_max_size is smaller than upload_max_filesize'),
                'summary' => Craft::t('microscope', 'The effective upload limit is {post}, not the {upload} that `upload_max_filesize` advertises.', [
                    'post' => Bytes::format($post),
                    'upload' => Bytes::format($upload),
                ]),
                'observed' => Craft::t('microscope', 'post_max_size {post} < upload_max_filesize {upload}', [
                    'post' => Bytes::format($post),
                    'upload' => Bytes::format($upload),
                ]),
                'expected' => Craft::t('microscope', 'post_max_size larger than upload_max_filesize'),
                'target' => 'post_max_size',
                'impact' => Craft::t('microscope', '`post_max_size` caps the whole request body, so it wins. Uploads between the two values are rejected — and because PHP discards the entire POST when the body is too large, Craft receives an empty request and the failure looks like a CSRF error rather than an upload one.'),
                'remediation' => "Set `post_max_size` above `upload_max_filesize`, leaving room for the rest of the form:\n\n```ini\nupload_max_filesize = 64M\npost_max_size = 72M\n```",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Request limits are sensible'),
                sprintf(
                    '%ss, %s vars, %s uploads',
                    $executionTime ?? 0,
                    number_format($inputVars ?? 0),
                    Bytes::format($upload),
                ),
            );
        }

        return $findings;
    }
}
