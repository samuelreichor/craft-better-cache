<?php

namespace samuelreichor\betterCache\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class DynamicController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        $this->response->getHeaders()->set(Constants::CACHE_CONTROL_HEADER, Constants::NO_STORE_CACHE_CONTROL);

        return parent::beforeAction($action);
    }

    public function actionCsrf(): Response
    {
        $this->requireAcceptsJson();

        return $this->asJson([
            'name' => $this->request->csrfParam,
            'token' => $this->request->getCsrfToken(),
        ]);
    }

    public function actionInclude(): Response
    {
        $payload = (string)$this->request->getRequiredQueryParam(Constants::INCLUDE_PAYLOAD_PARAM);
        $include = BetterCache::getInstance()->dynamicContent->resolveIncludePayload($payload);
        if ($include === null) {
            throw new BadRequestHttpException('Invalid include payload.');
        }

        $site = Craft::$app->getSites()->getSiteById($include['siteId']);
        if ($site !== null) {
            Craft::$app->getSites()->setCurrentSite($site);
        }

        return $this->renderTemplate($include['template'], $include['variables'], View::TEMPLATE_MODE_SITE);
    }
}
