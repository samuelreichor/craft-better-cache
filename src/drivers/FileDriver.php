<?php

namespace samuelreichor\betterCache\drivers;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\helpers\CachePath;
use samuelreichor\betterCache\models\PageVariant;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class FileDriver implements StorageDriverInterface
{
    public function save(PageVariant $variant, string $html): void
    {
        $file = $this->getFilePath($variant);
        FileHelper::createDirectory(dirname($file));

        // Write next to the target and rename, so the web server never serves a partial file.
        $tempFile = $file . '.' . StringHelper::randomString(8) . '.tmp';
        if (file_put_contents($tempFile, $html) === false) {
            throw new Exception("Could not write $tempFile");
        }
        if (!rename($tempFile, $file)) {
            FileHelper::unlink($tempFile);
            throw new Exception("Could not move $tempFile to $file");
        }
    }

    public function delete(PageVariant $variant): void
    {
        FileHelper::unlink($this->getFilePath($variant));
    }

    public function deleteAll(): void
    {
        $cachePath = BetterCache::getInstance()->getSettings()->cachePath;
        if (!CachePath::isSafe($cachePath)) {
            throw new InvalidConfigException("Refusing to delete the unsafe cache path “{$cachePath}”.");
        }

        FileHelper::removeDirectory(CachePath::resolve($cachePath));
    }

    /**
     * Mirrors the web server lookup: {cachePath}/{host}/{uri}/{query}/index.html
     */
    public function getFilePath(PageVariant $variant): string
    {
        $site = Craft::$app->getSites()->getSiteById($variant->siteId);
        $baseUrl = (string)$site?->getBaseUrl();
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!$host) {
            throw new InvalidConfigException("The base URL of site $variant->siteId has no host.");
        }

        $parts = [
            CachePath::resolve(BetterCache::getInstance()->getSettings()->cachePath),
            strtolower($host),
            trim((string)parse_url($baseUrl, PHP_URL_PATH), '/'),
            $variant->path,
            $variant->query,
            Constants::CACHE_FILE_NAME,
        ];

        return implode('/', array_filter($parts, fn(string $part) => $part !== ''));
    }
}
