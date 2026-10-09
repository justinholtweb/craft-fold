<?php

declare(strict_types=1);

namespace justinholtweb\fold\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\models\LocationGroupSiteSettings;
use justinholtweb\fold\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Location groups — a settings screen, so admin-only.
 */
class GroupsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('fold/groups/_index', [
            'groups' => $plugin->groups->getAllGroups(),
            'canCreate' => $plugin->groups->canCreateGroup(),
            'maxGroups' => Edition::maxGroups($plugin->isPro()),
        ]);
    }

    public function actionEdit(?int $groupId = null, ?LocationGroup $group = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($group === null) {
            if ($groupId !== null) {
                $group = $plugin->groups->getGroupById($groupId);

                if ($group === null) {
                    throw new NotFoundHttpException('Location group not found.');
                }
            } else {
                $group = new LocationGroup();
            }
        }

        $allSites = Craft::$app->getSites()->getAllSites();
        $siteSettings = $group->getSiteSettings();

        // A new group starts enabled on every site, with URLs off. Enabling everywhere is the
        // choice that surprises nobody on a single-site install and is trivially narrowed on a
        // multisite one; the reverse — a group that silently exists nowhere — reads as a bug.
        if ($group->id === null) {
            foreach ($allSites as $site) {
                $siteSettings[$site->id] = new LocationGroupSiteSettings([
                    'siteId' => $site->id,
                    'enabledByDefault' => true,
                ]);
            }
        }

        return $this->renderTemplate('fold/groups/_edit', [
            'group' => $group,
            'isNew' => $group->id === null,
            'allSites' => $allSites,
            'siteSettings' => $siteSettings,
            'title' => $group->id !== null ? $group->name : Craft::t('fold', 'New location group'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $groupId = $request->getBodyParam('groupId');

        $group = $groupId
            ? $plugin->groups->getGroupById((int)$groupId)
            : new LocationGroup();

        if ($group === null) {
            throw new NotFoundHttpException('Location group not found.');
        }

        $group->name = $request->getBodyParam('name');
        $group->handle = $request->getBodyParam('handle');
        $group->color = $request->getBodyParam('color') ?: null;
        $group->marker = $request->getBodyParam('marker') ?: null;
        $group->defaultCountryCode = $request->getBodyParam('defaultCountryCode') ?: null;
        $group->schemaType = trim((string)$request->getBodyParam('schemaType')) ?: null;

        $siteSettings = [];

        foreach ((array)$request->getBodyParam('sites', []) as $siteId => $posted) {
            if (empty($posted['enabled'])) {
                continue;
            }

            $settings = new LocationGroupSiteSettings([
                'siteId' => (int)$siteId,
                'enabledByDefault' => (bool)($posted['enabledByDefault'] ?? true),
                'uriFormat' => ($posted['uriFormat'] ?? '') ?: null,
                'template' => ($posted['template'] ?? '') ?: null,
            ]);

            $siteSettings[(int)$siteId] = $settings;
        }

        $group->setSiteSettings($siteSettings);

        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = \justinholtweb\fold\elements\Location::class;
        $group->setFieldLayout($fieldLayout);

        if (!$plugin->groups->saveGroup($group)) {
            Craft::$app->getSession()->setError(Craft::t('fold', 'Couldn’t save location group.'));
            Craft::$app->getUrlManager()->setRouteParams(['group' => $group]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('fold', 'Location group saved.'));

        return $this->redirectToPostedUrl($group);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $groupId = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->groups->deleteGroupById($groupId);

        return $this->asSuccess();
    }
}
