<?php

namespace samuelreichor\betterCache\jobs;

use Craft;
use craft\queue\BaseJob;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;
use Throwable;

class RenderPagesJob extends BaseJob
{
    /**
     * Pages keyed by URL; the page ID is null for pages not in the Tag Index yet.
     *
     * @var array<string, array{int|null, PageVariant}>
     */
    public array $pages = [];

    public function execute($queue): void
    {
        $plugin = BetterCache::getInstance();
        $urls = array_keys($this->pages);
        $total = count($urls);
        $done = 0;

        $requests = function() use ($urls) {
            foreach ($urls as $url) {
                yield new Request('GET', $url, [Constants::BYPASS_HEADER => '1']);
            }
        };

        $pool = new Pool(Craft::createGuzzleClient([
            'allow_redirects' => false,
            'timeout' => Constants::RENDER_TIMEOUT,
        ]), $requests(), [
            'concurrency' => $plugin->getSettings()->warmConcurrency,
            'fulfilled' => function($response, int $index) use ($queue, $urls, $total, &$done) {
                // A redirect is not stored, so the old page would otherwise stay forever.
                if ($response->getStatusCode() >= 300) {
                    Craft::warning("Rendering {$urls[$index]} redirected with status {$response->getStatusCode()}", Constants::HANDLE);
                    $this->clear($urls[$index]);
                }
                $this->setProgress($queue, ++$done / $total);
            },
            'rejected' => function(Throwable $reason, int $index) use ($queue, $urls, $total, &$done) {
                $status = $reason instanceof RequestException ? $reason->getResponse()?->getStatusCode() : null;
                // A page that is gone (4xx) must not be served anymore. Server errors keep it until the next try.
                if ($status !== null && $status < 500) {
                    $this->clear($urls[$index]);
                }
                Craft::warning("Could not render {$urls[$index]}: {$reason->getMessage()}", Constants::HANDLE);
                $this->setProgress($queue, ++$done / $total);
            },
        ]);
        $pool->promise()->wait();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t(Constants::HANDLE, 'Rendering cached pages');
    }

    private function clear(string $url): void
    {
        [$pageId, $variant] = $this->pages[$url];
        if ($pageId !== null) {
            BetterCache::getInstance()->invalidation->clear([$pageId => $variant]);
        }
    }
}
