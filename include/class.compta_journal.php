<?php

class Garradin_Compta_Journal
{
    protected function _getCurrentExercice()
    {
        $db = Garradin_DB::getInstance();
        $id = $db->querySingle('SELECT id FROM compta_exercices
            WHERE debut >= date(\'now\') AND fin <= date(\'now\') AND clos = 0 LIMIT 1;');

        if (!$id)
            return null;

        return $id;
    }

    protected function _checkOpenExercice($id)
    {
        if (is_null($id))
            return true;

        $db = Garradin_DB::getInstance();
        $id = $db->querySingle('SELECT id FROM compta_exercices
            WHERE debut >= date(\'now\') AND fin <= date(\'now\') AND clos = 0 LIMIT 1;');

        if ($id)
            return true;

        return false;
    }

    public function getSolde($compte)
    {
        $db = Garradin_DB::getInstance();
        $exercice = $this->_getCurrentExercice();
        $exercice = is_null($exercice) ? 'IS NULL' : '= ' . (int)$exercice;
        $compte = '\'' . $db->escapeString(trim($compte)) . '%\'';

        $query = 'SELECT
            (SELECT SUM(montant) FROM compta_journal
                WHERE compte_credit LIKE '.$compte.' AND id_exercice '.$exercice.')
            - (SELECT SUM(montant) FROM compta_journal
                WHERE compte_debit LIKE '.$compte.' AND id_exercice '.$exercice.');';

        return $db->querySingle($query);
    }

    public function add($data)
    {
        $this->_checkFields($data);

        $db = Garradin_DB::getInstance();

        $data['id_exercice'] = $this->_getCurrentExercice();

        $db->simpleInsert('compta_journal', $data);
        $id = $db->lastInsertRowId();

        return $id;
    }

    public function edit($id, $data)
    {
        $db = Garradin_DB::getInstance();

        // Vérification que l'on peut éditer cette opération
        if (!$this->_checkOpenExercice($db->simpleQuerySingle('SELECT id_exercice FROM compta_journal WHERE id = ?;', false, $id)))
        {
            throw new UserException('Cette opération fait partie d\'un exercice qui a été clos.');
        }

        $this->_checkFields($data);

        $db->simpleUpdate('compta_journal', $data,
            'id = \''.trim($id).'\'');

        return true;
    }

    public function delete($id)
    {
        $db = Garradin_DB::getInstance();

        // FIXME

        return true;
    }

    public function get($id)
    {
        $db = Garradin_DB::getInstance();
        return $db->simpleQuerySingle('SELECT * FROM compta_journal WHERE id = ?;', true, $id);
    }

    protected function _checkFields(&$data)
    {
        $db = Garradin_DB::getInstance();

        if (empty($data['libelle']) || !trim($data['libelle']))
        {
            throw new UserException('Le libellé ne peut rester vide.');
        }

        $data['libelle'] = trim($data['libelle']);

        if (!empty($data['moyen_paiement'])
            && !$db->simpleQuerySingle('SELECT 1 FROM compta_moyens_paiement WHERE code = ?;', false, $data['moyen_paiement']))
        {
            throw new UserException('Moyen de paiement invalide.');
        }

        if (empty($data['moyen_paiement']))
        {
            $data['moyen_paiement'] = null;
            $data['numero_cheque'] = null;
        }
        else
        {
            $data['moyen_paiement'] = strtoupper($data['moyen_paiement']);

            if ($data['moyen_paiement'] != 'CH')
            {
                $data['numero_cheque'] = null;
            }
        }

        $data['montant'] = (float)$data['montant'];

        if ($data['montant'] <= 0)
        {
            throw new UserException('Le montant ne peut être égal ou inférieur à zéro.');
        }

        foreach (array('remarques', 'numero_piece', 'numero_cheque') as $champ)
        {
            if (empty($data[$champ]) || !trim($data[$champ]))
            {
                $data[$champ] = '';
            }
            else
            {
                $data[$champ] = trim($data[$champ]);
            }
        }

        if (empty($data['compte_debit']) ||
            !$db->simpleQuerySingle('SELECT 1 FROM compta_comptes WHERE id = ?;', false, $data['compte_debit']))
        {
            throw new UserException('Compte débité inconnu.');
        }

        if (empty($data['compte_credit']) ||
            !$db->simpleQuerySingle('SELECT 1 FROM compta_comptes WHERE id = ?;', false, $data['compte_credit']))
        {
            throw new UserException('Compte crédité inconnu.');
        }

        $data['compte_credit'] = strtoupper(trim($data['compte_credit']));
        $data['compte_debit'] = strtoupper(trim($data['compte_debit']));

        if (isset($data['id_categorie']))
        {
            if (!$db->simpleQuerySingle('SELECT 1 FROM compta_categories WHERE id = ?;', false, (int)$data['id_categorie']))
            {
                throw new UserException('Catégorie inconnue.');
            }

            $data['id_categorie'] = (int)$data['id_categorie'];
        }
        else
        {
            $data['id_categorie'] = NULL;
        }

        $data['id_auteur'] = (int)$data['id_auteur'];

        return true;
    }
}

?>