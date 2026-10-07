<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\ElementHelper;
use samuelreichor\betterCache\Constants;
use WeakMap;
use yii\base\Component;

class ElementChanges extends Component
{
    /**
     * @var WeakMap<ElementInterface, array{string, array<int, bool>}> status and site statuses before the save
     */
    private WeakMap $originals;

    public function init(): void
    {
        parent::init();
        $this->originals = new WeakMap();
    }

    public function beforeSave(ElementInterface $element): void
    {
        if ($element->id === null || $element->getIsDraft() || $element->getIsRevision()) {
            return;
        }

        $original = Craft::$app->getElements()->getElementById($element->id, $element::class, $element->siteId);
        if ($original !== null) {
            $this->originals[$element] = [(string)$original->getStatus(), ElementHelper::siteStatusesForElement($original)];
        }
    }

    public function afterSave(ElementInterface $element): void
    {
        unset($this->originals[$element]);
    }

    /**
     * Drafts and revisions never appear on a Cached Page. Otherwise only elements in the middle of their own
     * save are judged: Craft also invalidates owners of nested elements, and those carry no change information.
     */
    public function canSkipInvalidation(?ElementInterface $element): bool
    {
        if ($element === null) {
            return false;
        }
        if (ElementHelper::isDraftOrRevision($element)) {
            return true;
        }
        if (!isset($this->originals[$element])) {
            return false;
        }

        [$status, $siteStatuses] = $this->originals[$element];
        if ((string)$element->getStatus() !== $status || ElementHelper::siteStatusesForElement($element) != $siteStatuses) {
            return false;
        }

        return !in_array($status, Constants::REFRESHABLE_STATUSES, true) || $this->isUnchanged($element);
    }

    /**
     * Element types without Craft's change tracking always count as changed, since their dirty state is incomplete.
     */
    private function isUnchanged(ElementInterface $element): bool
    {
        if ($element->duplicateOf !== null) {
            return !$element->duplicateOf->getModifiedAttributes() && !$element->duplicateOf->getModifiedFields();
        }

        return ElementHelper::shouldTrackChanges($element) && !$element->getDirtyAttributes() && !$element->getDirtyFields();
    }
}
