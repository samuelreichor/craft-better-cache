<?php

namespace samuelreichor\betterCache\helpers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\Html;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;

final class EntrySidebar
{
    public static function html(Entry $entry): string
    {
        $url = $entry->getCanonical()->getUrl();
        $user = Craft::$app->getUser()->getIdentity();
        if ($url === null || $user === null || !$user->can(Constants::UTILITY_PERMISSION)) {
            return '';
        }

        $plugin = BetterCache::getInstance();
        $variant = $plugin->cacheability->getPageVariantForUrl($url);
        $state = $variant !== null ? $plugin->tagIndex->findPageState($variant) : null;
        $notCached = Craft::t(Constants::HANDLE, 'Not cached');
        $status = match (true) {
            $state === null => $notCached,
            $state['stale'] => Craft::t(Constants::HANDLE, 'Stale, re-rendering'),
            default => Craft::t(Constants::HANDLE, 'Cached'),
        };

        $content = Cp::fieldHtml(Html::tag('span', $status, [Constants::SIDEBAR_STATUS_ATTRIBUTE => true]), [
            'label' => Craft::t(Constants::HANDLE, 'Status'),
        ]);
        if ($state !== null) {
            $content .= Html::tag('div', Html::tag('div', Html::button(Craft::t(Constants::HANDLE, 'Clear cached page'), [
                'class' => 'btn small',
                Constants::SIDEBAR_URL_ATTRIBUTE => $url,
            ]), ['class' => 'input']), ['class' => 'field']);
            self::registerScript($notCached);
        }

        return Html::beginTag('fieldset') .
            Html::tag('legend', Craft::t(Constants::HANDLE, 'Better Cache'), ['class' => 'h6']) .
            Html::tag('div', $content, ['class' => 'meta']) .
            Html::endTag('fieldset');
    }

    private static function registerScript(string $clearedStatus): void
    {
        Craft::$app->getView()->registerJs(Script::invoke(Constants::SIDEBAR_SCRIPT, [
            'action' => Constants::UTILITY_ACTIONS['clearUrl'],
            'urlAttribute' => Constants::SIDEBAR_URL_ATTRIBUTE,
            'statusAttribute' => Constants::SIDEBAR_STATUS_ATTRIBUTE,
            'clearedStatus' => $clearedStatus,
        ]));
    }
}
