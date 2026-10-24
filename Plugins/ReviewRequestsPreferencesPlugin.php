<?php

namespace Okay\Modules\Sviat\ReviewRequests\Plugins;

use Okay\Core\Design;
use Okay\Core\Router;
use Okay\Core\Security\CustomerCsrfToken;
use Okay\Core\SmartyPlugins\Func;
use Okay\Modules\Sviat\ReviewRequests\Controllers\ReviewRequestController;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestPreferences;

/** Блок у кабінеті: {sviat_review_requests_preferences user=$user}. */
class ReviewRequestsPreferencesPlugin extends Func
{
    protected $tag = 'sviat_review_requests_preferences';

    private const string TEMPLATE = 'Okay/Modules/Sviat/ReviewRequests/design/html/user_preferences.tpl';

    private Design $design;
    private ReviewRequestPreferences $preferences;
    private string $rootDir;

    public function __construct(Design $design, ReviewRequestPreferences $preferences, $rootDir)
    {
        $this->design = $design;
        $this->preferences = $preferences;
        $this->rootDir = rtrim((string) $rootDir, '/') . '/';
    }

    public function run($params)
    {
        $user = $params['user'] ?? null;
        $state = is_object($user) && !empty($user->id) ? $this->preferences->stateFor($user) : null;
        if ($state === null) {
            return '';
        }

        $saved = !empty($_SESSION[ReviewRequestController::PREFERENCES_FLASH]);
        unset($_SESSION[ReviewRequestController::PREFERENCES_FLASH]);

        $this->design->assign('rr_pref', $state);
        $this->design->assign('rr_pref_saved', $saved);
        $this->design->assign('rr_pref_url', Router::generateUrl('Sviat_ReviewRequests_preferences', [], true));
        $this->design->assign('rr_pref_csrf', CustomerCsrfToken::get());

        return $this->design->fetch($this->rootDir . self::TEMPLATE);
    }
}
