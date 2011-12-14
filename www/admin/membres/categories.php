<?php

require_once __DIR__ . '/../_inc.php';

if ($user['droits']['membres'] < Garradin_Membres::DROIT_ADMIN)
{
    throw new UserException("Vous n'avez pas le droit d'accéder à cette page.");
}

require_once GARRADIN_ROOT . '/include/class.membres_categories.php';
$cats = new Garradin_Membres_Categories;

$error = false;

if (!empty($_POST['save']))
{
    if (!utils::CSRF_check('new_cat'))
    {
        $error = 'Une erreur est survenue, merci de renvoyer le formulaire.';
    }
    else
    {
        try {
            $cats->add(array(
                'nom'           =>  utils::post('nom'),
                'montant_cotisation' => (float) utils::post('montant_cotisation'),
            ));

            utils::redirect('/admin/membres/categories.php');
        }
        catch (UserException $e)
        {
            $error = $e->getMessage();
        }
    }
}

$tpl->assign('error', $error);

$tpl->assign('liste', $cats->listComplete());

$tpl->display('admin/membres/categories.tpl');

?>