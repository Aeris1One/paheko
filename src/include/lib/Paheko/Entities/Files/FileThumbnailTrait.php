<?php

namespace Paheko\Entities\Files;

use KD2\Graphics\Image;
use KD2\ZipReader;
use KD2\HTML\Markdown;
use KD2\ErrorManager;

use Paheko\Static_Cache;
use Paheko\UserException;
use Paheko\Utils;
use Paheko\Web\Cache as Web_Cache;

use const Paheko\{DOCUMENT_THUMBNAIL_COMMANDS, WOPI_DISCOVERY_URL, CACHE_ROOT, WWW_URL, BASE_URL};

trait FileThumbnailTrait
{
	static protected array $_opendocument_extensions = ['odt', 'ods', 'odp', 'odg'];

	protected function deleteThumbnails(): void
	{
		if (!$this->image && !$this->hasThumbnail()) {
			return;
		}

		if (!isset($this->md5)) {
			return;
		}

		// clean up thumbnails
		foreach (self::ALLOWED_THUMB_SIZES as $size => $operations) {
			Static_Cache::remove(sprintf(self::THUMB_CACHE_ID, $this->md5, $size));

			$uri = $this->thumb_uri($size, false);

			if ($uri) {
				Web_Cache::delete($uri);
			}
		}

		if (!$this->image && $this->hasThumbnail()) {
			Static_Cache::remove(sprintf(self::THUMB_CACHE_ID, $this->md5, 'document'));
		}
	}

	public function asImageObject(): ?Image
	{
		if (!$this->image) {
			$path = $this->createDocumentThumbnail();

			if (!$path) {
				throw new \RuntimeException('Cannot get image object as document thumbnail does not exist');
			}
		}
		else {
			$path = $this->getLocalFilePath();
			$pointer = $path !== null ? null : $this->getReadOnlyPointer();
		}

		if ($path) {
			$i = new Image($path);
		}
		elseif ($pointer) {
			$i = Image::createFromPointer($pointer, null, true);
		}
		else {
			return null;
		}

		return $i;
	}

	public function thumb_uri($size = null, bool $with_hash = true): ?string
	{
		// Don't try to generate thumbnails for large files (> 25 MB), except if it's a video
		if ($this->size > 1024*1024*25 && substr($this->mime ?? '', 0, 6) !== 'video/') {
			return null;
		}

		$ext = $this->extension();

		if ($this->image) {
			$ext = 'webp';
		}
		elseif ($ext === 'md' || $ext === 'txt') {
			$ext = 'svg';
		}
		// We expect opendocument files to have an embedded thumbnail
		elseif (in_array($ext, self::$_opendocument_extensions)) {
			$ext = 'webp';
		}
		elseif (null !== $this->getDocumentThumbnailCommand()) {
			$ext = 'webp';
		}
		else {
			return null;
		}

		if (is_int($size)) {
			$size .= 'px';
		}

		$size = isset(self::ALLOWED_THUMB_SIZES[$size]) ? $size : key(self::ALLOWED_THUMB_SIZES);
		$uri = sprintf('%s.%s.%s', $this->uri(), $size, $ext);

		if ($with_hash) {
			$uri .= '?h=' . substr($this->etag(), 0, 10);
		}

		return $uri;
	}

	public function thumb_url($size = null, bool $with_hash = true): ?string
	{
		$uri = $this->thumb_uri($size, $with_hash);

		if (!$uri) {
			return $uri;
		}

		$base = in_array($this->context(), [self::CONTEXT_WEB, self::CONTEXT_MODULES, self::CONTEXT_CONFIG]) ? WWW_URL : BASE_URL;
		return $base . $uri;
	}

	public function hasThumbnail(): bool
	{
		return $this->thumb_url() !== null;
	}

	protected function getDocumentThumbnailCommand(): ?string
	{
		if (!DOCUMENT_THUMBNAIL_COMMANDS || !is_array(DOCUMENT_THUMBNAIL_COMMANDS)) {
			return null;
		}

		static $libreoffice_extensions = ['doc', 'docx', 'ods', 'xls', 'xlsx', 'odp', 'odt', 'ppt', 'pptx', 'odg'];
		static $mupdf_extensions = ['pdf', 'xps', 'cbz', 'epub', 'svg'];
		static $collabora_extensions = ['doc', 'docx', 'ods', 'xls', 'xlsx', 'odp', 'odt', 'ppt', 'pptx', 'odg', 'pdf', 'svg'];

		$ext = $this->extension();

		if (in_array('mupdf', DOCUMENT_THUMBNAIL_COMMANDS) && in_array($ext, $mupdf_extensions)) {
			return 'mupdf';
		}
		elseif (in_array('unoconvert', DOCUMENT_THUMBNAIL_COMMANDS) && in_array($ext, $libreoffice_extensions)) {
			return 'unoconvert';
		}
		elseif (in_array('collabora', DOCUMENT_THUMBNAIL_COMMANDS)
			&& class_exists('CurlFile')
			&& in_array($ext, $collabora_extensions)
			&& $this->getWopiURL()) {
			return 'collabora';
		}
		// Generate video thumbnails, for up to 1GB in size
		elseif (in_array('ffmpeg', DOCUMENT_THUMBNAIL_COMMANDS)
			&& $this->mime
			&& substr($this->mime, 0, 6) === 'video/'
			&& $this->size < 1024*1024*1024) {
			return 'ffmpeg';
		}

		return null;
	}

	/**
	 * Extract PNG thumbnail from odt/ods/odp/odg ZIP archives.
	 * This is the most efficient way to get a thumbnail.
	 */
	protected function extractOpenDocumentThumbnail(string $destination): bool
	{
		$zip = new ZipReader;

		// We are not going to extract the archive, so it does not matter
		$zip->enableSecurityCheck(false);

		$pointer = $this->getReadOnlyPointer();

		try {
			if ($pointer) {
				$zip->setPointer($pointer);
			}
			else {
				$zip->open($this->getLocalFilePath());
			}

			$i = 0;
			$found = false;

			foreach ($zip->iterate() as $path => $entry) {
				// There should not be more than 100 files in an opendocument archive, surely?
				if (++$i > 100) {
					break;
				}

				// We only care about the thumbnail
				if ($path !== 'Thumbnails/thumbnail.png') {
					continue;
				}

				// Thumbnail is larger than 500KB, abort, it's probably too weird
				if ($entry['size'] > 1024*500) {
					break;
				}

				$zip->extract($entry, $destination);
				$found = true;
				break;
			}
		}
		catch (\RuntimeException $e) {
			// Invalid archive
			$found = false;
		}

		unset($zip);

		if ($pointer) {
			fclose($pointer);
		}

		return $found;
	}

	/**
	 * Create a document thumbnail using external commands or Collabora Online API
	 */
	protected function createDocumentThumbnail(): ?string
	{
		$cache_id = sprintf(self::THUMB_CACHE_ID, $this->md5, 'document');
		$destination = Static_Cache::getPath($cache_id);

		if (in_array($this->extension(), self::$_opendocument_extensions) && $this->extractOpenDocumentThumbnail($destination)) {
			return $destination;
		}

		$command = $this->getDocumentThumbnailCommand();

		if (!$command) {
			return null;
		}

		if (file_exists($destination) && filesize($destination)) {
			return $destination;
		}

		$local_path = $this->getLocalFilePath();
		$tmpfile = null;

		if (!$local_path) {
			$p = $this->getReadOnlyPointer();

			if (!$p) {
				throw new \LogicException('The file cannot be found in storage, unable to create thumbnail: ' . $this->path);
			}

			$tmpfile = tempnam(CACHE_ROOT, 'thumb-');
			$fp = fopen($tmpfile, 'wb');

			while (!feof($p)) {
				fwrite($fp, fread($p, 8192));
			}

			fclose($p);
			fclose($fp);
			unset($p, $fp);
		}

		try {
			if ($command === 'collabora') {
				$url = parse_url(WOPI_DISCOVERY_URL);
				$url = sprintf('%s://%s:%s/lool/convert-to', $url['scheme'], $url['host'], $url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80));

				// see https://vmiklos.hu/blog/pdf-convert-to.html
				// but does not seem to be working right now (limited to PDF export?)
				/*
				$options = [
					'PageRange' => ['type' => 'string', 'value' => '1'],
					'PixelWidth' => ['type' => 'int', 'value' => 10],
					'PixelHeight' => ['type' => 'int', 'value' => 10],
				];
				*/

				$curl = \curl_init($url);
				curl_setopt($curl, CURLOPT_POST, 1);
				curl_setopt($curl, CURLOPT_POSTFIELDS, [
					'format' => 'png',
					//'options' => json_encode($options),
					'file' => new \CURLFile($tmpfile ?? $local_path, $this->mime, $this->name),
				]);

				$fp = fopen($destination, 'wb');
				curl_setopt($curl, CURLOPT_FILE, $fp);

				curl_exec($curl);
				$info = curl_getinfo($curl);

				if ($error = curl_error($curl)) {
					Utils::safe_unlink($destination);
					throw new \RuntimeException(sprintf('cURL error on "%s": %s', $url, $error));
				}

				curl_close($curl);
				fclose($fp);
				unset($curl);

				if (($code = $info['http_code']) != 200) {
					Utils::safe_unlink($destination);
					throw new \RuntimeException('Cannot fetch thumbnail from Collabora: code ' . $code . "\n" . json_encode($info));
				}
			}
			else {
				if ($command === 'mupdf') {
					// The single '1' at the end is to tell only to render the first page
					$cmd = sprintf('mutool draw -F png -o %s -w 500 -h 500 -r 72 %s 1 2>&1',
						Utils::escapeshellarg($destination),
						Utils::escapeshellarg($tmpfile ?? $local_path)
					);
				}
				elseif ($command === 'unoconvert') {
					// --filter-options PixelWidth=500 --filter-options PixelHeight=500
					// see https://github.com/unoconv/unoserver/issues/85
					// see https://github.com/unoconv/unoserver/issues/86
					$cmd = sprintf('unoconvert --convert-to png %s %s 2>&1',
						Utils::escapeshellarg($tmpfile ?? $local_path),
						Utils::escapeshellarg($destination)
					);
				}
				elseif ($command === 'ffmpeg') {
					$cmd = sprintf('ffmpeg -ss 00:00:02 -i %s -frames:v 1 -f image2 -c png -vf scale="min(900\, iw)":-1 %s 2>&1',
						Utils::escapeshellarg($tmpfile ?? $local_path),
						Utils::escapeshellarg($destination)
					);
				}

				$output = '';
				$code = Utils::exec($cmd, 5, null, function($data) use (&$output) { $output .= $data; });

				// Don't trust code as it can return != 0 even if generation was OK

				if (!file_exists($destination) || filesize($destination) < 10) {
					Utils::safe_unlink($destination);
					throw new \RuntimeException($command . ' execution failed with code: ' . $code . "\n" . $output);
				}
			}
		}
		finally {
			if ($tmpfile) {
				Utils::safe_unlink($tmpfile);
			}
		}

		return $destination;
	}

	/**
	 * Create a SVG thumbnail of a text/markdown file
	 * It's easy, we just transform it to HTML and embed the HTML in the SVG!
	 */
	protected function createSVGThumbnail(array $operations, string $destination): void
	{
		$width = 150;

		foreach ($operations as $operation) {
			if ($operation[0] === 'resize') {
				$width = (int) $operation[1];
				break;
			}
		}

		$text = substr($this->fetch(), 0, 1200);
		$text = Markdown::instance()->text($text);

		$out = '<svg version="1.1" viewBox="0 0 240 320" xmlns="http://www.w3.org/2000/svg" width="' . $width . '">
			<style>
			body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; padding: 1px; }
			table { border-collapse: collapse; width: 100% }
			table thead { background: #ddd }
			table td, table th { border: 2px solid #999; padding: .2em }
			ul, ol { padding-left: 1.5em }
			h1, h2, h3, h4, h5, h6, ul, ol, table, p { margin: .5em 0 }
			</style>
			<foreignObject x="0" y="0" width="1200" height="1200">
				<body xmlns="http://www.w3.org/1999/xhtml">' . $text . '</body>
			</foreignObject>
		</svg>';

		file_put_contents($destination, $out);
	}

	protected function createThumbnail(string $size, string $destination): void
	{
		$operations = self::ALLOWED_THUMB_SIZES[$size];

		if ($this->extension() === 'md' || $this->extension() === 'txt') {
			$this->createSVGThumbnail($operations, $destination);
			return;
		}

		$i = $this->asImageObject();

		if (!$i) {
			return;
		}

		// Always autorotate first
		$i->autoRotate();

		$allowed_operations = ['resize', 'cropResize', 'flip', 'rotate', 'crop', 'trim'];

		if (!$this->image) {
			array_unshift($operations, ['trim']);
		}

		foreach ($operations as $operation) {
			$arguments = array_slice($operation, 1);
			$operation = $operation[0];

			if (!in_array($operation, $allowed_operations)) {
				throw new \InvalidArgumentException('Opération invalide: ' . $operation);
			}

			$i->$operation(...$arguments);
		}

		$format = null;

		if ($i->format() !== 'gif') {
			$format = ['webp', null];
		}

		$i->save($destination, $format);
	}

	/**
	 * Envoie une miniature à la taille indiquée au client HTTP
	 */
	public function serveThumbnail(string $size = null): void
	{
		if (!$this->hasThumbnail()) {
			throw new UserException('Il n\'est pas possible de fournir une miniature pour ce fichier.', 404);
		}

		if (!array_key_exists($size, self::ALLOWED_THUMB_SIZES)) {
			throw new UserException('Cette taille de miniature n\'est pas autorisée.');
		}

		$cache_id = sprintf(self::THUMB_CACHE_ID, $this->md5, $size);
		$destination = Static_Cache::getPath($cache_id);

		if (!Static_Cache::exists($cache_id)) {
			try {
				$this->createThumbnail($size, $destination);
			}
			catch (\RuntimeException $e) {
				ErrorManager::reportExceptionSilent($e);
				header('Content-Type: image/svg+xml', true);
				echo $this->getMissingThumbnail($size);
				return;
			}
		}

		$ext = $this->extension();

		if ($ext === 'md' || $ext === 'txt') {
			$type = 'image/svg+xml';
		}
		else {
			// We can lie here, it might be something else, it does not matter
			$type = 'image/webp';
		}

		header('Content-Type: ' . $type, true);
		$this->_serve($destination, false);

		if (in_array($this->context(), [self::CONTEXT_WEB, self::CONTEXT_CONFIG])) {
			$uri = $this->thumb_uri($size, false);
			Web_Cache::link($uri, $destination);
		}
	}

	protected function getMissingThumbnail(string $size): string
	{
		$w = preg_replace('/[^\d]+/', '', $size) ?: 150;
		$h = intval($w / (2/3));
		return sprintf('<svg width="%d" height="%d" version="1.1" viewBox="0 0 210 297" xmlns="http://www.w3.org/2000/svg">
			<g stroke="#808080">
				<g fill="none" stroke-width="8">
					<path d="m27.44 40.23 155.1 0.1495"/>
					<path d="m27.44 76.33 155.1 0.1495"/>
					<path d="m27.44 110.2 155.1 0.1495"/>
					<path d="m27.44 148.2 155.1 0.1495"/>
					<path d="m27.44 182.2 155.1 0.1495"/>
					<path d="m87.08 268.9 94.9 0.1505"/>
					<path d="m87.35 225 94.9 0.1505"/>
				</g>
				<g fill="#808080" stop-color="#000000" stroke-width="71.02" style="text-orientation:upright" aria-label="?">
					<path d="m49.23 255.1q-0.04893-1.762-0.04893-2.642 0-5.187 1.468-8.954 1.076-2.838 3.474-5.725 1.762-2.104 6.312-6.116 4.599-4.061 5.97-6.459 1.37-2.398 1.37-5.236 0-5.138-4.012-9.003-4.012-3.914-9.835-3.914-5.627 0-9.395 3.523t-4.942 11.01l-9.052-1.076q1.223-10.03 7.242-15.36 6.067-5.333 16-5.333 10.52 0 16.78 5.725t6.263 13.85q0 4.697-2.202 8.661-2.202 3.963-8.612 9.639-4.306 3.817-5.627 5.627-1.321 1.81-1.957 4.159-0.6361 2.349-0.734 7.633zm-0.5382 17.66v-10.03h10.03v10.03z" stroke="none"/>
				</g>
			</g>
		</svg>', $w, $h);
	}
}
