<?php

namespace samuelreichor\betterCache;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\InvalidateElementCachesEvent;
use craft\services\Elements;
use craft\web\Request;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use samuelreichor\betterCache\drivers\CraftCacheDriver;
use samuelreichor\betterCache\drivers\FileDriver;
use samuelreichor\betterCache\drivers\StorageDriverInterface;
use samuelreichor\betterCache\models\Settings;
use samuelreichor\betterCache\services\Cacheability;
use samuelreichor\betterCache\services\Capture;
use samuelreichor\betterCache\services\Cdn;
use samuelreichor\betterCache\services\DynamicContent;
use samuelreichor\betterCache\services\Invalidation;
use samuelreichor\betterCache\services\ScheduledChanges;
use samuelreichor\betterCache\services\TagIndex;
use samuelreichor\betterCache\services\Warming;
use samuelreichor\betterCache\variables\BetterCacheVariable;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\queue\Queue;

/**
 * Better Cache plugin
 *
 * @method static BetterCache getInstance()
 * @method Settings getSettings()
 * @property-read Cacheability $cacheability
 * @property-read Capture $capture
 * @property-read Cdn $cdn
 * @property-read DynamicContent $dynamicContent
 * @property-read Invalidation $invalidation
 * @property-read ScheduledChanges $scheduledChanges
 * @property-read TagIndex $tagIndex
 * @property-read Warming $warming
 * @author Samuel Reichör <samuelreichor@gmail.com>
 * @copyright Samuel Reichör
 * @license https://craftcms.github.io/license/ Craft License
 */
class BetterCache extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'cacheability' => Cacheability::class,
                'capture' => Capture::class,
                'cdn' => Cdn::class,
                'dynamicContent' => DynamicContent::class,
                'invalidation' => Invalidation::class,
                'scheduledChanges' => ScheduledChanges::class,
                'tagIndex' => TagIndex::class,
                'warming' => Warming::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            $request = Craft::$app->getRequest();
            if ($request instanceof Request) {
                $this->capture->start($request);
            }
        });
    }

    public function getStorageDriver(): StorageDriverInterface
    {
        return match ($this->getSettings()->storageDriver) {
            Constants::STORAGE_DRIVER_FILE => new FileDriver(),
            Constants::STORAGE_DRIVER_CRAFT_CACHE => new CraftCacheDriver(),
            default => throw new InvalidConfigException("Unsupported storage driver: {$this->getSettings()->storageDriver}"),
        };
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(Constants::HANDLE . '/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('betterCache', BetterCacheVariable::class);
        });

        Event::on(Elements::class, Elements::EVENT_INVALIDATE_CACHES, function(InvalidateElementCachesEvent $event) {
            $this->invalidation->addTags($event->tags);
        });

        // A queue worker's request never ends, so resolve the tags after every job too.
        foreach ([Queue::EVENT_AFTER_EXEC, Queue::EVENT_AFTER_ERROR] as $queueEvent) {
            Event::on(Queue::class, $queueEvent, fn() => $this->invalidation->flush());
        }

        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            if ($event->sender === Craft::$app->getResponse()) {
                $this->capture->finish($event->sender);
            }
        });
    }
}
