<?php

namespace samuelreichor\betterCache\controllers;

use Craft;
use craft\web\Controller;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\helpers\SiteRows;
use yii\web\Response;

class SettingsController extends Controller
{
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $settings = $this->request->getBodyParam('settings', []);
        foreach (Constants::SITE_PATTERN_SETTINGS as $name) {
            // An editable table without rows posts an empty string.
            $settings[$name] = SiteRows::toPatterns(($settings[$name] ?? null) ?: []);
        }
        $settings['purger'] = ($settings['purger'] ?? '') ?: null;

        $plugin = BetterCache::getInstance();
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            return $this->asFailure(Craft::t('app', 'Couldn’t save plugin settings.'), routeParams: ['plugin' => $plugin]);
        }

        return $this->asSuccess(Craft::t('app', 'Plugin settings saved.'));
    }
}
