<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\helpers\FileHelper;
use craft\models\Site;
use craft\web\Request;
use craft\web\Response;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\models\Settings;
use yii\db\Transaction;
use yii\queue\PushEvent;
use yii\queue\Queue;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    private Transaction $transaction;
    private Settings $originalSettings;
    private Site $originalSite;
    private ?string $originalRequestMethod;
    private ?string $tempCachePath = null;

    protected function setUp(): void
    {
        $this->transaction = Craft::$app->getDb()->beginTransaction();
        $this->originalSettings = clone $this->settings();
        $this->originalSite = Craft::$app->getSites()->getCurrentSite();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->tempCachePath !== null) {
            FileHelper::removeDirectory($this->tempCachePath);
        }
        BetterCache::getInstance()->setSettings($this->originalSettings->toArray());
        Craft::$app->getSites()->setCurrentSite($this->originalSite);
        if ($this->originalRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
        $this->transaction->rollBack();
    }

    protected function settings(): Settings
    {
        return BetterCache::getInstance()->getSettings();
    }

    protected function useTempCachePath(): string
    {
        $this->tempCachePath = Craft::$app->getPath()->getTempPath() . '/better-cache-test';
        $this->settings()->cachePath = $this->tempCachePath;

        return $this->tempCachePath;
    }

    protected function siteWithUrl(): Site
    {
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (parse_url((string)$site->getBaseUrl(), PHP_URL_HOST)) {
                return $site;
            }
        }

        $this->markTestSkipped('No site with an absolute base URL.');
    }

    /**
     * @param array<string, string> $headers
     */
    protected function request(string $url, array $headers = [], string $method = 'GET'): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        /** @var Request $request */
        $request = Craft::createObject([
            'class' => Request::class,
            'isConsoleRequest' => false,
            'hostInfo' => 'https://example.test',
            'scriptUrl' => '/index.php',
            'scriptFile' => Craft::getAlias('@webroot/index.php'),
            'url' => $url,
            'pathInfo' => ltrim((string)parse_url($url, PHP_URL_PATH), '/'),
            'queryParams' => $query,
        ]);
        foreach ($headers as $name => $value) {
            $request->getHeaders()->set($name, $value);
        }

        return $request;
    }

    protected function htmlResponse(string $body = '<html><body>Hi</body></html>', int $statusCode = 200): Response
    {
        $response = new Response();
        $response->content = $body;
        $response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
        $response->setStatusCode($statusCode);

        return $response;
    }

    /**
     * @return array<mixed> the jobs pushed while running $callback
     */
    protected function capturePushedJobs(callable $callback): array
    {
        $jobs = [];
        $handler = function(PushEvent $event) use (&$jobs) {
            $jobs[] = $event->job;
        };
        Craft::$app->getQueue()->on(Queue::EVENT_AFTER_PUSH, $handler);
        try {
            $callback();
        } finally {
            Craft::$app->getQueue()->off(Queue::EVENT_AFTER_PUSH, $handler);
        }

        return $jobs;
    }
}
