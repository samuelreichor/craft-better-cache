<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
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

    /**
     * @var WeakMap<ElementInterface, int[]>
     */
    private WeakMap $siteIdsBefore;

    public function init(): void
    {
        parent::init();
        $this->originals = new WeakMap();
        $this->siteIdsBefore = new WeakMap();
    }

    public function beforeSave(ElementInterface $element): void
    {
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return;
        }

        $this->siteIdsBefore[$element] = $element->id === null ? [] : $this->siteIds($element->id);
        if ($element->id === null) {
            return;
        }

        $original = Craft::$app->getElements()->getElementById($element->id, $element::class, $element->siteId);
        if ($original !== null) {
            $this->originals[$element] = [(string)$original->getStatus(), ElementHelper::siteStatusesForElement($original)];
        }
    }

    public function afterSave(ElementInterface $element): void
    {
        unset($this->originals[$element], $this->siteIdsBefore[$element]);
    }

    /**
     * Sites the element was on before its save or is on now. Null outside of a save, where the sites it left are unknown.
     *
     * @return int[]|null
     */
    public function getAffectedSiteIds(?ElementInterface $element): ?array
    {
        if ($element?->id === null || !isset($this->siteIdsBefore[$element])) {
            return null;
        }

        return array_values(array_unique([...$this->siteIdsBefore[$element], ...$this->siteIds($element->id)]));
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
     * @return int[]
     */
    private function siteIds(int $elementId): array
    {
        return array_map('intval', (new Query())
            ->select('siteId')
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $elementId])
            ->column());
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
