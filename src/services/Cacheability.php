<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\elements\User;
use craft\models\Site;
use craft\web\Request;
use craft\web\Response;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\helpers\UriPattern;
use samuelreichor\betterCache\models\PageVariant;
use samuelreichor\betterCache\models\Settings;
use yii\base\Component;

class Cacheability extends Component
{
    /**
     * Returns the Page Variant a request may be served from, or null if it must be rendered.
     */
    public function getPageVariant(Request $request): ?PageVariant
    {
        if (
            !$this->settings()->enabled ||
            $request->getIsConsoleRequest() ||
            !$request->getIsSiteRequest() ||
            !$request->getIsGet() ||
            $request->getIsActionRequest() ||
            $this->hasPreviewOrToken($request) ||
            !empty($request->getQueryParam(Constants::NO_CACHE_PARAM)) ||
            !$this->isAllowedUser(Craft::$app->getUser()->getIdentity()) ||
            !$this->isAllowedUrl($request->getUrl())
        ) {
            return null;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $path = $this->getSitePath($request->getFullPath(), $site);
        if (!$this->isIncludedUri($path, $site)) {
            return null;
        }

        $query = $this->normalizeQuery($request);
        if ($query !== '' && !$this->settings()->cacheQueryStrings) {
            return null;
        }

        return new PageVariant($site->id, $path, $query);
    }

    public function isStorable(Response $response): bool
    {
        if (Craft::$app->getUser()->getIdentity() !== null) {
            return false;
        }

        if (!$response->getIsSuccessful()) {
            return false;
        }

        // Template responses have their own format, so rely on the header the formatters set while preparing.
        if (!str_contains((string)$response->getHeaders()->get('Content-Type'), Constants::HTML_CONTENT_TYPE)) {
            return false;
        }

        $body = stripslashes((string)$response->content);
        foreach (Constants::UNCACHEABLE_BODY_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks presence only: Craft ignores invalid tokens, but such a render must not be stored either.
     */
    private function hasPreviewOrToken(Request $request): bool
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $params = [...Constants::PREVIEW_PARAMS, $generalConfig->tokenParam, $generalConfig->siteToken];
        foreach ($params as $param) {
            if ($request->getQueryParam($param) !== null) {
                return true;
            }
        }

        foreach (Constants::TOKEN_HEADERS as $header) {
            if ($request->getHeaders()->has($header)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedUser(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        if (!Craft::$app->getIsLive() && !$user->can('accessSiteWhenSystemIsOff')) {
            return false;
        }

        return !$user->getPreference('enableDebugToolbarForSite');
    }

    private function isAllowedUrl(string $url): bool
    {
        if (strlen($url) > Constants::MAX_URI_LENGTH) {
            return false;
        }

        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        foreach (explode('/', $path) as $segment) {
            if ($segment !== '' && preg_match('/^(\.|%2e)+$/i', $segment)) {
                return false;
            }
        }

        if ($path === 'index.php' || str_starts_with($path, 'index.php/')) {
            return false;
        }

        $resourcePath = trim((string)parse_url(Craft::getAlias(Craft::$app->getConfig()->getGeneral()->resourceBaseUrl), PHP_URL_PATH), '/');

        return $resourcePath === '' || !str_starts_with($path . '/', $resourcePath . '/');
    }

    /**
     * Strips the site's base path but keeps pagination, unlike Request::getPathInfo().
     */
    private function getSitePath(string $fullPath, Site $site): string
    {
        $basePath = trim((string)parse_url((string)$site->getBaseUrl(), PHP_URL_PATH), '/');
        if ($basePath !== '' && str_starts_with($fullPath . '/', $basePath . '/')) {
            return ltrim(substr($fullPath, strlen($basePath)), '/');
        }

        return $fullPath;
    }

    private function isIncludedUri(string $path, Site $site): bool
    {
        $settings = $this->settings();

        return $this->matchesAny($path, $site, $settings->includedUriPatterns)
            && !$this->matchesAny($path, $site, $settings->excludedUriPatterns);
    }

    /**
     * @param array<string, string[]> $patternsBySite
     */
    private function matchesAny(string $path, Site $site, array $patternsBySite): bool
    {
        $patterns = array_merge(
            $patternsBySite[Constants::ALL_SITES_KEY] ?? [],
            $patternsBySite[$site->handle] ?? [],
        );

        foreach ($patterns as $pattern) {
            if (preg_match(UriPattern::toRegex($pattern), $path)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeQuery(Request $request): string
    {
        $params = $request->getQueryParams();
        $dropped = [...Constants::TRACKING_PARAMS, Craft::$app->getConfig()->getGeneral()->pathParam];
        foreach (array_filter($dropped) as $param) {
            unset($params[$param]);
        }
        ksort($params);

        return http_build_query($params);
    }

    private function settings(): Settings
    {
        return BetterCache::getInstance()->getSettings();
    }
}
