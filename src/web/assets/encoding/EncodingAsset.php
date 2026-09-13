<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\web\assets\encoding;

use craft\web\AssetBundle;

/**
 * Browser SMS encoding calculator.
 *
 * @since 5.16.0
 */
final class EncodingAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@lindemannrock/smsmanager/web/assets/encoding/dist';
        $this->js = ['encoding.js'];

        parent::init();
    }
}
