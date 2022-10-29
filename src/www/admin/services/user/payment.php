<?php
namespace Garradin;

use Garradin\Services\Services_User;
use Garradin\Accounting\Accounts;
use Garradin\Accounting\Projects;
use Garradin\Accounting\Years;
use Garradin\Entities\Accounting\Account;
use Garradin\Entities\Accounting\Transaction;

require_once __DIR__ . '/../_inc.php';

$session->requireAccess($session::SECTION_USERS, $session::ACCESS_WRITE);

$su = Services_User::get((int)qg('id'));

if (!$su) {
	throw new UserException("Cette inscription n'existe pas");
}

$fee = $su->fee();

if (!$fee->id_year) {
	throw new UserException('Cette inscription n\'est pas liée à un tarif relié à la comptabilité, il n\'est pas possible de saisir un règlement.');
}

$user_name = (new Membres)->getNom($su->id_user);

$csrf_key = 'service_pay';

$form->runIf(f('save') || f('save_and_add_payment'), function () use ($su, $session) {
	$su->addPayment($session->getUser()->id);

	if ($su->paid != (bool) f('paid')) {
		$su->paid = (bool) f('paid');
		$su->save();
	}
}, $csrf_key, '!services/user/?id=' . $su->id_user);

$t = new Transaction;
$t->type = $t::TYPE_REVENUE;
$types_details = $t->getTypesDetails();

$account_targets = $types_details[Transaction::TYPE_REVENUE]->accounts[1]->targets_string;

$tpl->assign('projects', Projects::listAssocWithEmpty());

$tpl->assign(compact('csrf_key', 'account_targets', 'user_name', 'su', 'fee'));

$tpl->display('services/user/payment.tpl');
