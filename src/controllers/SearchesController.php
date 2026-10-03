<?php

declare(strict_types=1);

namespace justinholtweb\fold\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use DateTime;
use justinholtweb\fold\Plugin;
use yii\web\Response;

/**
 * The search log report (Pro).
 *
 * The interesting half is not what people found — it is what they did not. A cluster of
 * zero-result searches around a town you do not trade in is the most actionable thing a store
 * locator ever learns, and it is invisible in any other analytics tool.
 */
class SearchesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_SEARCHES);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $since = new DateTime('-90 days');

        $recent = (new Query())
            ->select(['term', 'lat', 'lng', 'radius', 'unit', 'resultCount', 'nearestDistance', 'dateCreated'])
            ->from(['{{%fold_searches}}'])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(100)
            ->all();

        return $this->renderTemplate('fold/searches/_index', [
            'isPro' => $plugin->isPro(),
            'logging' => $plugin->getSettings()->logSearches,
            'empty' => $plugin->search->getEmptySearches(50, $since),
            'recent' => $recent,
            'since' => $since,
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        Craft::$app->getDb()->createCommand()->delete('{{%fold_searches}}')->execute();
        Craft::$app->getSession()->setNotice(Craft::t('fold', 'Search log cleared.'));

        return $this->redirect('fold/searches');
    }
}
