<?php

declare(strict_types=1);

namespace justinholtweb\fold\models;

use Craft;
use craft\base\Model;
use craft\models\Site;
use craft\validators\SiteIdValidator;
use craft\validators\UriFormatValidator;

/**
 * What a location group does on one site.
 *
 * The same shape Craft gives sections, and for the same reason: a group that exists on the US
 * site and not the Canadian one, with its own URI format, is the difference between a multisite
 * locator and a single-site locator that has been copied twice.
 */
class LocationGroupSiteSettings extends Model
{
    public ?int $id = null;
    public ?int $groupId = null;
    public ?int $siteId = null;

    /** Whether the group is available on this site at all. */
    public bool $enabled = true;

    /** Whether a new location in this group starts enabled here. */
    public bool $enabledByDefault = true;

    /** Set both, or neither: a URI with no template is a 404 with extra steps. */
    public ?string $uriFormat = null;
    public ?string $template = null;

    public ?string $uid = null;

    private ?LocationGroup $_group = null;

    public function getGroup(): ?LocationGroup
    {
        return $this->_group;
    }

    public function setGroup(?LocationGroup $group): void
    {
        $this->_group = $group;
    }

    public function getSite(): ?Site
    {
        return $this->siteId !== null ? Craft::$app->getSites()->getSiteById($this->siteId) : null;
    }

    /** Whether locations in this group get their own page on this site. */
    public function getHasUrls(): bool
    {
        return (bool)$this->uriFormat;
    }

    public function rules(): array
    {
        return [
            [['siteId'], SiteIdValidator::class],
            [['uriFormat'], UriFormatValidator::class],
            [['template'], 'string', 'max' => 500],
            [['template'], 'required', 'when' => fn(self $model) => (bool)$model->uriFormat],
            [['siteId'], 'required'],
        ];
    }

    public function getConfig(): array
    {
        return [
            'enabledByDefault' => $this->enabledByDefault,
            'uriFormat' => $this->uriFormat ?: null,
            'template' => $this->template ?: null,
        ];
    }
}
