<?php
namespace Paheko;

use Paheko\Accounting\Transactions;
use Paheko\Services\Services_User;
use Paheko\Users\Categories;
use Paheko\Users\Users;

use Paheko\UserTemplate\Modules;

require_once __DIR__ . '/_inc.php';

if (qg('number')) {
	$user = Users::getFromNumber(qg('number'));
}
else {
	$user = Users::get((int) qg('id'));
}

if (!$user) {
	throw new UserException("Ce membre n'existe pas.");
}

$list_category = isset($_GET['list_category']) && strlen($_GET['list_category']) ? intval($_GET['list_category']) : null;

if ($list_category !== null) {
	$form->runIf('goto', function () use ($user, $list_category, $session) {
		$list = Users::listByCategory((int) $list_category, $session);
		$list->toggleNavigation(true);
		$list->iterateUntilCondition('_user_id', $user->id);

		if (f('goto') === 'next') {
			$row = $list->next() ?? $list->first();
		}
		else {
			$row = $list->prev() ?? $list->last();
		}

		$url = sprintf('?id=%d&list_category=%d', $row->_user_id, $list_category);
		Utils::redirect($url);
	});
}

$category = $user->category();
$csrf_key = 'user_' . $user->id();

$form->runIf('login_as', function () use ($user, $category, $session) {
	if (!$session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN)) {
		throw new \RuntimeException('Security alert: attempt to login as a different user, but does not hold the right to do so.');
	}

	$logged_user = $session->getUser();

	// Cannot login as same category, cannot login in an admin category
	if ($user->id_category == $logged_user->id_category || $category->perm_config >= $session::ACCESS_ADMIN) {
		throw new UserException('Accès interdit');
	}

	$session->logout();
	$session->forceLogin($user->id);
	Log::add(Log::LOGIN_AS, ['admin' => $logged_user->name()]);

}, $csrf_key, '!?login_as=1');

$services = Services_User::listDistinctForUser($user->id);

$variables = [];

if ($session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ)) {
	$variables['transactions_linked'] = Transactions::countForUser($user->id);
	$variables['transactions_created'] = Transactions::countForCreator($user->id);
}

$parent_name = $user->getParentName();
$children = $user->listChildren();
$siblings = $user->listSiblings();

$variables += compact('list_category', 'services', 'user', 'category', 'children', 'siblings', 'parent_name', 'csrf_key');

$tpl->assign($variables);
$tpl->assign('snippets', Modules::snippetsAsString(Modules::SNIPPET_USER, $variables));

$tpl->display('users/details.tpl');
