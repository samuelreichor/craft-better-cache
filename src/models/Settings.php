<?php

namespace samuelreichor\betterCache\models;

use Craft;
use craft\base\Model;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\helpers\CachePath;
use samuelreichor\betterCache\helpers\UriPattern;

/**
 * Better Cache settings
 */
class Settings extends Model
{
    public bool $enabled = false;

    public string $storageDriver = Constants::STORAGE_DRIVER_FILE;

    public string $refreshStrategy = Constants::REFRESH_STRATEGY_CLEAR;

    /**
     * Regex patterns keyed by site handle, `*` applies to all sites.
     *
     * @var array<string, string[]>
     */
    public array $includedUriPatterns = [];

    /**
     * @var array<string, string[]>
     */
    public array $excludedUriPatterns = [];

    /**
     * Regex patterns for query param names, keyed by site handle like the URI patterns. Each included
     * param makes its own Page Variant. A param that is neither included nor excluded makes the request
     * uncacheable, so a template that reads it cannot put its output into the shared page.
     *
     * @var array<string, string[]>
     */
    public array $includedQueryParams = [];

    /**
     * Ignored as if they were not there.
     *
     * @var array<string, string[]>
     */
    public array $excludedQueryParams = [Constants::ALL_SITES_KEY => ['gclid', 'fbclid', 'utm_.*']];

    public int $warmConcurrency = 3;

    public string $cachePath = '@webroot/cache/' . Constants::HANDLE;

    public int $cdnMaxAge = Constants::DEFAULT_CDN_MAX_AGE;

    /**
     * Empty to send no tag header.
     */
    public string $cacheTagHeader = Constants::DEFAULT_CACHE_TAG_HEADER;

    /**
     * Class name of a PurgerInterface implementation, null for no CDN.
     */
    public ?string $purger = null;

    protected function defineRules(): array
    {
        return [
            ['enabled', 'boolean'],
            ['storageDriver', 'in', 'range' => Constants::STORAGE_DRIVERS],
            ['refreshStrategy', 'in', 'range' => Constants::REFRESH_STRATEGIES],
            [['includedUriPatterns', 'excludedUriPatterns', 'includedQueryParams', 'excludedQueryParams'], 'validateUriPatterns'],
            ['warmConcurrency', 'integer', 'min' => 1],
            ['cdnMaxAge', 'integer', 'min' => 0],
            ['cachePath', 'required'],
            ['cachePath', 'validateCachePath'],
        ];
    }

    public function validateCachePath(string $attribute): void
    {
        if (!CachePath::isSafe($this->$attribute)) {
            $this->addError($attribute, Craft::t(Constants::HANDLE, 'The cache path must not be the webroot, the project root, one of Craft’s own directories or a parent of them.'));
        }
    }

    public function validateUriPatterns(string $attribute): void
    {
        foreach ($this->$attribute as $siteHandle => $patterns) {
            if (!is_array($patterns)) {
                $this->addError($attribute, Craft::t(Constants::HANDLE, 'Patterns for site “{site}” must be an array.', ['site' => $siteHandle]));
                continue;
            }
            foreach ($patterns as $pattern) {
                if (!is_string($pattern) || !UriPattern::isValid($pattern)) {
                    $this->addError($attribute, Craft::t(Constants::HANDLE, 'Invalid pattern for site “{site}”.', ['site' => $siteHandle]));
                }
            }
        }
    }
}
