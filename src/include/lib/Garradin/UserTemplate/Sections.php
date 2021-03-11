<?php

namespace Garradin\UserTemplate;

use KD2\Brindille_Exception;
use Garradin\DB;
use Garradin\Entities\Web\Page;
use Garradin\Web\Web;
use Garradin\Files\Files;
use Garradin\Entities\Files\File;

use const Garradin\WWW_URL;

class Sections
{
	const SECTIONS_LIST = [
		'categories',
		'articles',
		'pages',
		'images',
		'breadcrumbs',
		'documents',
		'files',
		'sql',
	];

	static protected $_cache = [];

	static protected function cache(string $id, callable $callback)
	{
		if (!array_key_exists($id, self::$_cache)) {
			self::$_cache[$id] = $callback();
		}

		return self::$_cache[$id];
	}

	static public function breadcrumbs(array $params, UserTemplate $tpl, int $line): \Generator
	{
		if (!isset($params['path'])) {
			throw new Brindille_Exception('"path" parameter is mandatory and is missing');
		}

		$paths = [];
		$path = '';

		foreach (explode('/', $params['path']) as $part) {
			$path = trim($path . '/' . $part, '/');
			$paths[$path] = null;
		}

		$db = DB::getInstance();
		$sql = sprintf('SELECT path, title FROM web_pages WHERE %s ORDER BY path ASC;', $db->where('path', array_keys($paths)));

		$result = $db->preparedQuery($sql);

		while ($row = $result->fetchArray(\SQLITE3_ASSOC))
		{
			$row['url'] = WWW_URL . $row['path'];
			yield $row;
		}
	}

	static public function categories(array $params, UserTemplate $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['where'] .= ' AND w.type = ' . Page::TYPE_CATEGORY;
		return self::pages($params, $tpl, $line);
	}

	static public function articles(array $params, UserTemplate $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['where'] .= ' AND w.type = ' . Page::TYPE_PAGE;
		return self::pages($params, $tpl, $line);
	}

	static public function pages(array $params, UserTemplate $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['select'] = 'w.*';
		$params['tables'] = 'web_pages w';
		$params['where'] .= ' AND status = :status';
		$params[':status'] = Page::STATUS_ONLINE;

		if (isset($params['search'])) {
			$params['tables'] .= ' INNER JOIN files_search s USING (path)';
			$params['select'] .= ', rank(matchinfo(s), 0, 1.0, 1.0) AS points';
			$params['where'] .= ' AND s MATCH :search';

			if (!isset($params['order'])) {
				$params['order'] = 'points DESC';
			}

			$params[':search'] = $params['search'];
			unset($params['search']);
		}

		if (isset($params['uri'])) {
			$params['where'] .= ' AND w.uri = :uri';
			$params['limit'] = 1;
			$params[':uri'] = $params['uri'];
			unset($params['uri']);
		}

		if (isset($params['path'])) {
			$params['where'] .= ' AND w.path = :path';
			$params['limit'] = 1;
			$params[':path'] = $params['path'];
			unset($params['path']);
		}

		if (array_key_exists('parent', $params)) {
			if (null === $params['parent']) {
				$params['where'] .= ' AND w.parent IS NULL';
			}
			else {
				$params['where'] .= ' AND w.parent = :parent';
				$params[':parent'] = $params['parent'];
			}

			unset($params['parent']);
		}

		if (isset($params['future'])) {
			if (!$params['future']) {
				$params['where'] .= ' AND w.published <= datetime(\'now\', \'localtime\')';
			}

			unset($params['future']);
		}

		//var_dump($params); exit;

		foreach (self::sql($params, $tpl, $line) as $row) {
			$data = $row;
			unset($data['points']);

			$page = new Page;
			$page->exists(true);
			$page->load($data);

			$row = array_merge($row, $page->asTemplateArray());

			yield $row;
		}
	}

	static public function images(array $params, UserTemplate $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['where'] .= ' AND f.image = 1';
		return self::files($params, $tpl, $line);
	}

	static public function documents(array $params, UserTemplate $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		$params['where'] .= ' AND f.image = 0';
		return self::files($params, $tpl, $line);
	}

	static public function files(array $params, UserTemplate $tpl, int $line): \Generator
	{
		if (!array_key_exists('where', $params)) {
			$params['where'] = '';
		}

		if (empty($params['parent'])) {
			throw new Brindille_Exception('La section "files" doit obligatoirement comporter un paramètre "parent"');
		}

		$parent = $params['parent'];

		// Fetch page
		$page = self::cache('page_' . md5($parent), function () use ($parent) {
			return Web::getByURI($parent);
		});

		if (!$page) {
			return;
		}

		$params['select'] = 'f.*';
		$params['tables'] = 'files f';
		$params['where'] .= ' AND f.path = :path AND f.name != :p_name AND f.type = ' . File::TYPE_FILE;
		$params[':path'] = dirname($page->filepath());
		$params[':p_name'] = basename($page->filepath());
		unset($params['parent']);

		// Generate a temporary table containing the list of files included in the text
		if (!empty($params['except_in_text'])) {
			// Don't regenerate that table for each section called in the page,
			// we assume the content and list of files will not change between sections
			self::cache('page_files_text_' . $parent, function () use ($page) {
				$db = DB::getInstance();
				$db->begin();

				// Put files mentioned in the text in a temporary table
				$db->exec('CREATE TEMP TABLE IF NOT EXISTS files_tmp_in_text (page_id, name);');

				foreach (Page::findTaggedAttachments($page->content) as $name) {
					$db->insert('files_tmp_in_text', ['page_id' => $page->id(), 'name' => $name]);
				}

				$db->commit();
			});

			$params['where'] .= sprintf(' AND f.name NOT IN (SELECT name FROM files_tmp_in_text WHERE page_id = %d)', $page->id());
		}

		if (empty($params['order'])) {
			$params['order'] = 'name';
		}

		if ($params['order'] == 'name') {
			$params['order'] .= ' COLLATE NOCASE';
		}

		foreach (self::sql($params, $tpl, $line) as $row) {
			$file = new File;
			$file->exists(true);
			$file->load($row);
			$row['url'] = $file->url();
			$row['download_url'] = $file->url(true);
			$row['thumb_url'] = $file->thumb_url();
			$row['small_url'] = $file->thumb_url(File::THUMB_SIZE_SMALL);
			yield $row;
		}
	}

	static public function sql(array $params, UserTemplate $tpl, int $line): \Generator
	{
		static $defaults = [
			'select' => '*',
			'order' => '1',
			'begin' => 0,
			'limit' => 1000,
			'where' => '',
		];

		if (!isset($params['tables'])) {
			throw new Brindille_Exception('Missing parameter "tables"');
		}

		foreach ($defaults as $key => $default_value) {
			if (!isset($params[$key])) {
				$params[$key] = $default_value;
			}
		}

		// Allow for count=true, count=1 and also count="DISTINCT user_id" count="id"
		if (isset($params['count'])) {
			$params['select'] = sprintf('COUNT(%s) AS count', $params['count'] == 1 ? '*' : $params['count']);
		}

		$sql = sprintf('SELECT %s FROM %s WHERE 1 %s %s ORDER BY %s LIMIT %d,%d;',
			$params['select'],
			$params['tables'],
			$params['where'] ?? '',
			isset($params['group']) ? 'GROUP BY ' . $params['group'] : '',
			$params['order'],
			$params['begin'],
			$params['limit']
		);

		$db = DB::getInstance();

		try {
			$statement = $db->protectSelect(null, $sql);

			$args = [];

			foreach ($params as $key => $value) {
				if (substr($key, 0, 1) == ':') {
					$args[$key] = $value;
				}
			}

			foreach ($args as $key => $value) {
				$statement->bindValue($key, $value, $db->getArgType($value));
			}

			if (!empty($params['debug'])) {
				echo sprintf('<pre style="padding: 5px; background: yellow;">%s</pre>', htmlspecialchars($statement->getSQL(true)));
			}

			$result = $statement->execute();
		}
		catch (\Exception $e) {
			throw new Brindille_Exception(sprintf("Erreur SQL à la ligne %d : %s\nRequête exécutée : %s", $line, $db->lastErrorMsg(), $sql));
		}

		while ($row = $result->fetchArray(\SQLITE3_ASSOC))
		{
			yield $row;
		}
	}
}
