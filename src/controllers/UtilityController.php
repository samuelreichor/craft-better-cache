<?php

namespace samuelreichor\betterCache\controllers;

use Craft;
use craft\web\Controller;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use yii\web\Response;

class UtilityController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . Constants::UTILITY_ID);

        return parent::beforeAction($action);
    }

    public function actionInvalidate(): ?Response
    {
        $count = BetterCache::getInstance()->invalidation->invalidateAll();
        $this->setSuccessFlash(Craft::t(Constants::HANDLE, Constants::MESSAGE_PAGES_INVALIDATED, ['count' => $count]));

        return $this->redirectToPostedUrl();
    }

    public function actionClear(): ?Response
    {
        BetterCache::getInstance()->invalidation->clearAll();
        $this->setSuccessFlash(Craft::t(Constants::HANDLE, Constants::MESSAGE_CACHE_CLEARED));

        return $this->redirectToPostedUrl();
    }

    public function actionClearUrl(): ?Response
    {
        $url = $this->request->getRequiredBodyParam('url');
        if (!BetterCache::getInstance()->invalidation->clearUrl($url)) {
            $this->setFailFlash(Craft::t(Constants::HANDLE, Constants::MESSAGE_NO_SITE_FOR_URL, ['url' => $url]));
            return null;
        }
        $this->setSuccessFlash(Craft::t(Constants::HANDLE, Constants::MESSAGE_URL_CLEARED, ['url' => $url]));

        return $this->redirectToPostedUrl();
    }

    public function actionWarm(): ?Response
    {
        BetterCache::getInstance()->warming->warm();
        $this->setSuccessFlash(Craft::t(Constants::HANDLE, Constants::MESSAGE_WARMING_QUEUED));

        return $this->redirectToPostedUrl();
    }
}
