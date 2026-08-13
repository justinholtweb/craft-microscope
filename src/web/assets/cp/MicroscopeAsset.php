<?php

namespace justinholtweb\microscope\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Microscope's control panel styles and behaviour.
 *
 * Note for anyone editing `dist/`: Craft publishes plugin resources to `web/cpresources` and
 * serves them from there, so a change to these files won't appear until the published copy is
 * cleared — `php craft clear-caches/cp-resources`.
 */
class MicroscopeAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $css = [
        'microscope.css',
    ];

    public $js = [
        'microscope.js',
    ];
}
