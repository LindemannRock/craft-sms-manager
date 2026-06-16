<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\widgets;

use Craft;
use craft\db\Query;
use lindemannrock\smsmanager\records\SmsLogRecord;

/**
 * Shared site and language filters for SMS dashboard widgets.
 *
 * @since 5.14.0
 */
trait SiteLanguageFilterTrait
{
    /**
     * @var string Selected site ID, or "all" for editable sites plus global rows
     */
    public string $siteId = 'all';

    /**
     * @var string Selected language code, or "all"
     */
    public string $language = 'all';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function siteOptions(): array
    {
        $options = [
            ['value' => 'all', 'label' => Craft::t('lindemannrock-base', 'All Sites')],
        ];

        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            $options[] = [
                'value' => (string) $site->id,
                'label' => $site->name,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function languageOptions(): array
    {
        $languages = [];
        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            $languages[strtolower(explode('-', (string) $site->language)[0])] = true;
        }

        $storedLanguages = (new Query())
            ->select(['language'])
            ->distinct()
            ->from(SmsLogRecord::tableName())
            ->where(['not', ['language' => null]])
            ->andWhere(['not', ['language' => '']])
            ->column();

        foreach ($storedLanguages as $language) {
            $languages[strtolower((string) $language)] = true;
        }

        unset($languages['']);
        ksort($languages);

        $options = [
            ['value' => 'all', 'label' => Craft::t('sms-manager', 'All Languages')],
        ];

        foreach (array_keys($languages) as $language) {
            $options[] = [
                'value' => $language,
                'label' => strtoupper($language),
            ];
        }

        return $options;
    }

    protected function applySiteFilter(Query $query): void
    {
        if ($this->siteId !== 'all') {
            $query->andWhere(['siteId' => (int) $this->siteId]);
            return;
        }

        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        if ($editableSiteIds === []) {
            $query->andWhere(['siteId' => null]);
            return;
        }

        $query->andWhere(['or', ['siteId' => $editableSiteIds], ['siteId' => null]]);
    }

    protected function applyLanguageFilter(Query $query): void
    {
        if ($this->language !== 'all') {
            $query->andWhere(['language' => $this->language]);
        }
    }
}
