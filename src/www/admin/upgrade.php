<?php

namespace Garradin;

use Garradin\Membres\Session;

const UPGRADE_PROCESS = true;

require_once __DIR__ . '/../../include/init.php';

$config = Config::getInstance();

$v = $config->getVersion();

if (version_compare($v, garradin_version(), '>='))
{
    throw new UserException("Pas de mise à jour à faire.");
}

// versions pré-0.7.0: démerdez-vous !
if (!$v || version_compare($v, '0.7.0', '<'))
{
    throw new UserException("Votre version de Garradin est trop ancienne pour être mise à jour. Mettez à jour vers Garradin 0.8.5 avant de faire la mise à jour vers cette version.");
}

Install::checkAndCreateDirectories();

if (Static_Cache::exists('upgrade'))
{
    $path = Static_Cache::getPath('upgrade');
    throw new UserException('Une mise à jour est déjà en cours.'
        . PHP_EOL . 'Si celle-ci a échouée et que vous voulez ré-essayer, supprimez le fichier suivant:'
        . PHP_EOL . $path);
}

// Voir si l'utilisateur est loggé, on le fait ici pour le cas où
// il y aurait déjà eu des entêtes envoyés au navigateur plus bas
$session = new Session;
$user_is_logged = $session->isLogged(true);

Static_Cache::store('upgrade', 'Mise à jour en cours.');

$db = DB::getInstance();
$redirect = true;

// Créer une sauvegarde automatique
(new Sauvegarde)->create('pre-upgrade-' . garradin_version());

echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, target-densitydpi=device-dpi" />
    <link rel="stylesheet" type="text/css" href="static/admin.css" media="all" />
    <script type="text/javascript" src="static/scripts/loader.js"></script>
    <title>Mise à jour</title>
</head>
<body>
<header class="header">
    <nav class="menu"></nav>
    <h1>Mise à jour de Garradin '.$config->getVersion().' vers la version '.garradin_version().'...</h1>
</header>
<main>
<div id="loader" class="loader" style="margin: 2em 0; height: 50px;"></div>
<script>
animatedLoader(document.getElementById("loader"), 5);
</script>';

flush();


if (version_compare($v, '0.7.0', '<'))
{
    $db->exec('PRAGMA foreign_keys = OFF; BEGIN;');

    // Mise à jour base de données
    $db->exec(file_get_contents(ROOT . '/include/data/0.7.0.sql'));

    // Changement de syntaxe du Wiki vers SkrivML
    $wiki = new Wiki;
    $res = $db->get('SELECT id_page, contenu, revision, chiffrement FROM wiki_revisions GROUP BY id_page ORDER BY revision DESC;');

    foreach ($res as $row)
    {
        // Ne pas convertir le contenu chiffré, de toute évidence
        if ($row->chiffrement)
            continue;

        $content = $row->contenu;
        $content = Utils::HTMLToSkriv($content);
        $content = Utils::SpipToSkriv($content);

        if ($content != $row->contenu)
        {
            $wiki->editRevision($row->id_page, $row->revision, [
                'id_auteur'     =>  null,
                'contenu'       =>  $content,
                'modification'  =>  'Mise à jour 0.7.0 (transformation SPIP vers SkrivML)',
            ]);
        }
    }

    $db->exec('END;');
}

if (version_compare($v, '0.7.2', '<'))
{
    $db->exec('PRAGMA foreign_keys = OFF; BEGIN;');

    // Mise à jour base de données
    $db->exec(file_get_contents(ROOT . '/include/data/0.7.2.sql'));

    $db->exec('END;');
}

if (version_compare($v, '0.8.0-beta4', '<'))
{
    // Inscription de l'appid
    $db->exec('PRAGMA application_id = ' . DB::APPID . ';');

    // Changement de la taille de pagesize
    // Cecit devrait améliorer les performances de la DB
    $db->exec('PRAGMA page_size = 4096;');

    // Application du changement de taille de page
    $db->exec('VACUUM;');

    // Désactivation des foreign keys AVANT le début de la transaction
    $db->exec('PRAGMA foreign_keys = OFF;');

    $db->begin();

    $db->import(ROOT . '/include/data/0.8.0.sql');

    $db->commit();

    $config = Config::getInstance();

    // Ajout champ numéro de membre
    $champs = (array) $config->get('champs_membres')->getAll();
    $presets = Membres\Champs::importPresets();

    // Ajout du numéro au début
    $champs = array_merge(['numero' => $presets['numero']], $champs);
    (new Membres\Champs($champs))->save();

    // Si l'ID était l'identificant, utilisons le numéro de membre à la place
    if ($config->get('champ_identifiant') == 'id')
    {
        $config->set('champ_identifiant', 'numero');
        $config->save();
    }

    // Nettoyage de la base de données
    $db->exec('VACUUM;');

    // Mise à jour plan comptable: ajout comptes encaissement
    $comptes = new Compta\Comptes;
    $comptes->importPlan();
}

if (version_compare($v, '0.8.3', '<'))
{
    // Désactivation des foreign keys AVANT le début de la transaction
    $db->exec('PRAGMA foreign_keys = OFF;');

    $db->begin();

    $db->import(ROOT . '/include/data/0.8.3.sql');

    $db->commit();
}

if (version_compare($v, '0.8.4', '<'))
{
    $db->begin();

    $db->import(ROOT . '/include/data/0.8.4.sql');

    $db->commit();
}

if (version_compare($v, '0.9.0', '<'))
{
    $db->exec('PRAGMA foreign_keys = OFF;');
    $db->begin();

    $db->import(ROOT . '/include/data/0.9.0.sql');

    $db->commit();

    $config->set('desactiver_site', false);
    $config->save();
}

Utils::clearCaches();

$config->setVersion(garradin_version());

Static_Cache::remove('upgrade');

// Réinstaller les plugins système si nécessaire
Plugin::checkAndInstallSystemPlugins();

// Mettre à jour les plugins si nécessaire
foreach (Plugin::listInstalled() as $id=>$infos)
{
    $plugin = new Plugin($id);

    if ($plugin->needUpgrade())
    {
        $plugin->upgrade();
    }

    unset($plugin);
}

// Forcer à rafraîchir les données de la session si elle existe
if ($user_is_logged)
{
    $session->refresh();
}

echo '<h2>Mise à jour terminée.</h2>
<p><a href="'.ADMIN_URL.'">Retour</a></p>';

if ($redirect)
{
    echo '
    <script type="text/javascript">
    window.setTimeout(function () { 
        window.location.href = "'.ADMIN_URL.'"; 
        stopAnimatedLoader();
    }, 1000);
    </script>';
}

echo '
</main>
</body>
</html>';
