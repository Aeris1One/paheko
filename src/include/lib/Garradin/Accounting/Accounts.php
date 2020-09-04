<?php

namespace Garradin\Accounting;

use Garradin\Entities\Accounting\Account;
use Garradin\Utils;
use Garradin\DB;
use KD2\DB\EntityManager;

class Accounts
{
	protected $chart_id;
	protected $em;

	public function __construct(int $chart_id)
	{
		$this->chart_id = $chart_id;
		$this->em = EntityManager::getInstance(Account::class);
	}

	/**
	 * Return common accounting accounts from current chart
	 * (will not return analytical and volunteering accounts)
	 */
	public function listCommonTypes(): array
	{
		return $this->em->all('SELECT * FROM @TABLE WHERE id_chart = ? AND type != 0 AND type NOT IN (?, ?) ORDER BY code COLLATE NOCASE;',
			$this->chart_id, Account::TYPE_ANALYTICAL, Account::TYPE_VOLUNTEERING);
	}

	/**
	 * Return all accounts from current chart
	 */
	public function listAll(): array
	{
		return $this->em->all('SELECT * FROM @TABLE WHERE id_chart = ? ORDER BY code COLLATE NOCASE;',
			$this->chart_id);
	}

	/**
	 * Return only analytical accounts
	 */
	public function listAnalytical(): array
	{
		return $this->em->all('SELECT * FROM @TABLE WHERE id_chart = ? AND type = ? ORDER BY code COLLATE NOCASE;',
			$this->chart_id, Account::TYPE_ANALYTICAL);
	}

	/**
	 * Return only analytical accounts
	 */
	public function listVolunteering(): array
	{
		return $this->em->all('SELECT * FROM @TABLE WHERE id_chart = ? AND type = ? ORDER BY code COLLATE NOCASE;',
			$this->chart_id, Account::TYPE_VOLUNTEERING);
	}

	/**
	 * List common accounts, grouped by type
	 * @return array
	 */
	public function listCommonGrouped(): array
	{
		$out = [];
		$query = $this->em->iterate('SELECT * FROM @TABLE WHERE id_chart = ? AND type != 0 AND type_parent = 0 ORDER BY type, code COLLATE NOCASE;',
			$this->chart_id);

		foreach ($query as $row) {
			$name = Account::TYPES_NAMES[$row->type];

			if (!isset($out[$name])) {
				$out[$name] = [];
			}

			$out[$name][] = $row;
		}

		return $out;
	}

	public function getTypesParents(): array
	{
		return $this->em->DB->getAssoc($this->em->formatQuery('SELECT type, code FROM @TABLE WHERE type_parent = 1 ORDER BY type;'));
	}
}