<?php

namespace samuelreichor\betterCache;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\DefineValueEvent;
use craft\events\DeleteSiteEvent;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Elements;
use craft\services\Sites;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\Request;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use samuelreichor\betterCache\drivers\CraftCacheDriver;
use samuelreichor\betterCache\drivers\FileDriver;
use samuelreichor\betterCache\drivers\StorageDriverInterface;
use samuelreichor\betterCache\helpers\EntrySidebar;
use samuelreichor\betterCache\models\Settings;
use samuelreichor\betterCache\services\Cacheability;
use samuelreichor\betterCache\services\Capture;
use samuelreichor\betterCache\services\Cdn;
use samuelreichor\betterCache\services\DynamicContent;
use samuelreichor\betterCache\services\ElementChanges;
use samuelreichor\betterCache\services\Invalidation;
use samuelreichor\betterCache\services\ScheduledChanges;
use samuelreichor\betterCache\services\TagIndex;
use samuelreichor\betterCache\services\Warming;
use samuelreichor\betterCache\utilities\BetterCacheUtility;
use samuelreichor\betterCache\variables\BetterCacheVariable;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\queue\Queue;

/**
 * Better Cache plugin
 *
 * @method static BetterCache getInstance()
 * @method Settings getSettings()
 * @property-read Cacheability $cacheability
 * @property-read Capture $capture
 * @property-read ElementChanges $elementChanges
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

    /**
     * Set through `pluginConfigs` in `config/app.php`, like Blitz.
     */
    public Queue|array|string $queue = 'queue';

    public static function config(): array
    {
        return [
            'components' => [
                'cacheability' => Cacheability::class,
                'capture' => Capture::class,
                'cdn' => Cdn::class,
                'elementChanges' => ElementChanges::class,
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

    public function getQueue(): Queue
    {
        return Instance::ensure($this->queue, Queue::class);
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
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BetterCacheUtility::class;
        });

        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_CACHE_OPTIONS, function(RegisterCacheOptionsEvent $event) {
            $event->options[] = [
                'key' => Constants::HANDLE,
                'label' => Craft::t(Constants::HANDLE, 'Better Cache pages'),
                'action' => fn() => $this->invalidation->clearAll(),
            ];
        });

        Event::on(Sites::class, Sites::EVENT_BEFORE_APPLY_SITE_DELETE, function(DeleteSiteEvent $event) {
            $this->invalidation->clearSite($event->site->id);
        });

        Event::on(Entry::class, Entry::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            $event->html .= EntrySidebar::html($event->sender);
        });

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('betterCache', BetterCacheVariable::class);
        });

        Event::on(ElementQuery::class, ElementQuery::EVENT_DEFINE_CACHE_TAGS, function(DefineValueEvent $event) {
            $this->capture->defineQueryTags($event);
        });

        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function(ElementEvent $event) {
            if ($this->getSettings()->enabled) {
                $this->elementChanges->beforeSave($event->element);
            }
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $this->elementChanges->afterSave($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_INVALIDATE_CACHES, function(InvalidateElementCachesEvent $event) {
            if (
                !$this->getSettings()->enabled ||
                in_array(Constants::ORDER_TAG . '::*', $event->tags, true) ||
                $this->elementChanges->canSkipInvalidation($event->element)
            ) {
                return;
            }
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
