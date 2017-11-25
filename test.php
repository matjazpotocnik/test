<?php

class AutoSmush extends FieldtypeImage implements Module, ConfigurableModule {
	/**
	 * Array of messages reported by this module
	 * @var array
	 */
	static $messages = array();

	/**
	 * Array of settings for image-optimizer
	 * @var array
	 */
	protected $optimizeSettings = array();

	/**
	 * Array of all optimizers
	 * @var array
	 */
	protected $optimizers = array();

	/**
	 * Array of additional paths to look for optimizers executable
	 * @var array
	 */
	protected $optimizersExtraPaths = array();

	/**
	 * Array of allowed extensions for images
	 * @var array
	 */
	protected $allowedExtensions = array();

	/**
	 * Array of error codes returned by reSmush.it web service
	 * @var array
	 */
	protected $apiErrorCodes = array();

	/**
	 * Indicator if image needs to be optimized
	 * @var boolean
	 */
	protected $isOptimizeNeeded = false;

	/**
	 * Indicator if image was optimized on upload
	 * @var boolean
	 */
	protected $isOptimizedOnUpload = false;

	/**
	 * This module config data
	 * @var array
	 */
	protected $configData = array();

	/**
	 * PW FileLog object
	 * @var array
	 */
	protected $log;


	/**
	 * Construct and set default configuration
	 *
	 */
	public function __construct() {

		self::$messages = array(
			'start'            => $this->_('Starting...'),
		);

		$this->optimizers = array(
			'jpegtran'  => '',
		);

		// currently only jpegoptim is used for jpegs, I modified OptimizerFactory.php
		// pngs are chained in this order: pngquant, optipng, pngcrush, advpng
		$this->optimizeSettings = array(
			'ignore_errors'     => false, // in production could be set to true
		);

		$this->allowedExtensions = array_map('trim', explode(',', self::API_ALLOWED_EXTENSIONS));

		// http://resmush.it/api
		$this->apiErrorCodes = array(
			'400' => $this->_('no url of image provided'),
		);
	}


	/**
	 * Initialize log file
	 *
	 */
	public function init() {
		$cls = strtolower(__CLASS__);

		// pruneBytes returns error in PW prior to 3.0.13 if file does not exist
		if(!file_exists($this->wire('log')->getFilename($cls))) {
			$this->wire('log')->save($cls, 'log file created', array('showUser' => false, 'showURL' => false));
		}

		$this->log = new FileLog($this->wire('log')->getFilename($cls));
		method_exists($this->log, __CLASS__) ? $this->log->pruneBytes(20000) : $this->log->prune(20000);

		$paths = $this->wire('config')->paths;
		$this->optimizersExtraPaths = array(
			realpath($paths->siteModules . __CLASS__ . '/windows_binaries'),
			realpath($paths->root),
			realpath($paths->templates),
			realpath($paths->assets)
		);
	}

	/**
	 * Hook after ProcessCroppableImage3::executeSave in auto mode
	 * Optimize image on crop when FieldtypeCroppableImage3 is installed
	 *
	 * @param HookEvent $event
	 *
	 */
	public function optimizeOnResizeCI3($event) {

		// get page-id from post, sanitize, validate page and edit permission
		$id = intval($this->input->post->pages_id);
		$page = wire('pages')->get($id);
		if(!$page->id) throw new WireException('Invalid page');
		$editable = $page instanceof RepeaterPage ? $page->getForPage()->editable() : $page->editable();
		if(!$editable) throw new WirePermissionException('Not Editable');

		// get fieldname from post, sanitize and validate
		$field = wire('sanitizer')->fieldName($this->input->post->field);

		// UGLY WORKAROUND HERE TO GET A FIELDNAME WITH UPPERCASE LETTERS
		foreach($page->fields as $f) {
			if(mb_strtolower($f->name) != $field) continue;
			$fieldName = $f->name;
			break;
		}

		$fieldValue = $page->get($fieldName);
		if(!$fieldValue || !$fieldValue instanceof Pagefiles) throw new WireException('Invalid field');
		$field = $fieldValue; unset($fieldValue);

		// get filename from post, sanitize and validate
		$filename = wire('sanitizer')->name($this->input->post->filename);

		// $img is not variation
		$img = $field->get('name=' . $filename);
		if(!$img) throw new WireException('Invalid filename');

		// get suffix from post, sanitize and validate
		$suffix = wire('sanitizer')->name($this->input->post->suffix);
		if(!$suffix || strlen($suffix) == 0) throw new WireException('No suffix');

		// build the file
		$file = basename($img->basename, '.' . $img->ext) . '.-' . strtolower($suffix) . '.' . $img->ext;

		// get the variation
		$myimage = $img->getVariations()->get($file);

		if(!$myimage) throw new WireException('Invalid filename');

		$this->optimize($myimage, false, 'auto');

	}

	/**
	 * Hook after InputfieldImage::renderItem in manual mode
	 * Add optimize link/button to the image markup
	 *
	 * @param HookEvent $event
	 *
	 */
	public function addOptButton($event) {
		// $event->object = InputfieldFile
		// $event->object->value = Pagefiles
		// $event->arguments[0] or $event->argumentsByName('pagefile') = Pagefile

		$file = $event->argumentsByName('pagefile');

		if(!in_array($file->ext, $this->allowedExtensions)) return; // not an image file

		$id = $file->page->id;
		$url = $this->wire('config')->urls->admin . 'module/edit?name=' . __CLASS__ .
					 "&mode=optimize&id=$id&file=$id,{$file->basename}";
		$title =  $this->_('Optimize image');
		$text = $this->_('Optimize');
		$optimizing = $this->_('Optimizing');
		if($this->isOptimizedOnUpload) $text = $this->_('Optimized on upload');

		if(stripos($event->return, 'InputfieldFileName')) {
			// InputfieldFileName class found, used in PW versions up to 3.0.17
			$link = "<a href='$url' data-optimizing='$optimizing' class='InputfieldImageOptimize' title='$title'>$text</a>";
			if(stripos($event->return, '</p>')) { // insert link right before </p>
				$event->return = str_replace('</p>', $link . '</p>', $event->return);
			}
		} else if(stripos($event->return, 'InputfieldImageButtonCrop')) {
			// new version with button
			// there is also InputfieldImage::renderButtons hook
			$link = "<a href='$url&var=1' title='$title'>$text</a>";
			//if($this->wire('user')->admin_theme == 'AdminThemeUikit') {
			//	$b  = "<button type='button' data-href='$url' data-optimizing='$optimizing' class='InputfieldImageOptimize1 uk-button uk-button-text uk-margin-right'>";
			//} else {
				$b  = "<button type='button' data-href='$url' data-optimizing='$optimizing' class='InputfieldImageOptimize1 ui-button ui-corner-all ui-state-default'>";
			//}
			$b .= "<span class='ui-button-text'><span class='fa fa-leaf'></span><span> $text</span></span></button>";
			if(stripos($event->return, '</small>')) { // insert button right before </small> as the last (third) button, after Crop and Variations buttons
				$event->return = str_replace('</small>', $b . '</small>', $event->return);
			}
		} else {
			$this->log->save('addOptButton: class InputfieldFileName/InputfieldImageButtonCrop not found');
		}

	}


	/**
	 * Process image optimize via ajax request when optimize link/button is clicked
	 * or in bulk mode
	 *
	 * @param bool $getVariations true when optimizing variation, false if original
	 * @return string|json
	 *
	 */
	public function onclickOptimize($getVariations = false) {

		$err = "<i style='color:red' class='fa fa-times-circle'></i>";
		$input = $this->wire('input');
		$status = array(
			'error'           => null, // various errors
			'error_api'       => null, // erros from reSmush.it
			'percentNew'      => '0', // reduction percentage
			'file'            => '', // image name
			'basedir'         => '', // page id where image is eg. 1234
			'url'             => '#' // full url to the image
		);

		$file = $input->get('file'); // 1234,image.jpg
		$id = (int) $input->get('id'); // could also get id from file var
		$bulk = $input->get('bulk');
		$m = ($bulk == 1) ? 'bulkOptimize: ' : 'onclickOptimize: ';
		// $file = $this->wire('sanitizer')->pageNameUTF8($input->get('file'));

		if($this->wire('config')->demo) {
			$msg = $this->_('Optimization disabled in demo mode!');
			$this->log->save($m . $msg);
			if($bulk == 1) {
				$status['error'] = $msg;
				header('Content-Type: application/json');
				echo json_encode($status);
			} else {
				echo $getVariations ? $err : $msg;
			}
			exit(0);
		}

		$page = $this->wire('pages')->get($id);

		if(!$id || !$file || !$page->id) {
			$msg = 'Invalid data!';
			$this->log->save($m . $msg);
			if($bulk == 1) {
				$status['error'] = 'invalid data';
				header('Content-Type: application/json');
				echo json_encode($status);
			} else {
				echo $getVariations ? $err : $msg;
			}
			exit(0);
		}


		$status['file'] = $this->wire('config')->urls->files . $id . '/' . explode(',', $file)[1]; // fake image name
		$status['basedir'] = $id; // page id where image is eg. 1234

		// this doesn't work with CroppableImage3
		//$img = wire('modules')->get('ProcessPageEditImageSelect')->getPageImage($getVariations);

		// old version
		/*$myimage = null;
		$page = $this->wire('pages')->get($id);
		$file = explode(',', $file)[1];
		$imgs = $this->wire('modules')->get('ProcessPageEditImageSelect')->getImages($page);

		foreach($imgs as $img) {
			if($img->basename == $file) {
				// original found
				$myimage = $img;
				break;
			}
			$myimage = $img->getVariations()->get($file);
			if($myimage) {
				// variation found
				break;
			}
		}*/

		// new version
		$myimage = $this->getPageImage($page, true);

		if(!$myimage) {
			$file = explode(',', $file)[1];
			$msg = ' not found!';
			$this->log->save($m . $file . $msg);
			if($bulk == 1) {
				$status['error'] = 'image not found';
				header('Content-Type: application/json');
				echo json_encode($status);
			} else {
				echo $getVariations ? $err : $msg;
			}
			exit(0);
		}

		$img = $myimage;

		$src_size = (int) @filesize($img->filename);
		if($src_size == 0) {
			// this shouldn't happen but who knows
			if($bulk == 1) {
				$status['error'] = 'zero file size';
				header('Content-Type: application/json');
				echo json_encode($status);
			} else {
				echo 'Zero file size!';
			}
			exit(0);
		}

		if($bulk == 1) {
			$status = $this->optimize($img, true, 'bulk');
			header('Content-Type: application/json');
			echo json_encode($status);
			exit(0);
		} else {
			$status = $this->optimize($img, true, 'manual');
			if(!is_null($status['error'])) {
				$msg = $this->_('Not optimized, check log!');
				// errors are already logged by optimize method
				echo $getVariations ? $err : $msg;
				exit(0);
			}
		}

		@clearstatcache(true, $img->filename);
		$dest_size = @filesize($img->filename);
		$percentNew = 100 - (int) ($dest_size / $src_size * 100);

		if($getVariations) {
			echo wireBytesStr($dest_size);
		} else {
			//printf($this->x_x('Optimized, reduced by %1$d%%'), $percentNew);
			echo $this->_('Optimized, new size:') . ' ' . wireBytesStr($dest_size);
		}

		exit(0);
	}

	/**
	 * Create a list of images to be optimized and echo them in JSON format.
	 * Called from this module settings in bulk mode, on button click
	 *
	 */
	public function bulkOptimize() {

		// check if engine is selected
		if(!isset($this->configData['optBulkEngine'])) {
			$this->log->save('No engine selected (bulk).');
			$status = array(
				'error' => 'No engine selected.',
				'numImages' => 0
			);
			header('Content-Type: application/json');
			echo json_encode($status);
			exit(0);
		}

		$processOriginals  = (isset($this->configData['optBulkAction']) && in_array('optimize_originals', $this->configData['optBulkAction']));
		$processVariations = (isset($this->configData['optBulkAction']) && in_array('optimize_variations', $this->configData['optBulkAction']));

		// get all fields of type FieldtypeImage or FieldtypeCroppableImage3
		$selector = 'type=FieldtypeImage';
		if(wire('modules')->isInstalled('FieldtypeCroppableImage3')) $selector .= '|FieldtypeCroppableImage3';
		$imageFields = wire('fields')->find($selector);
		/*
		// returns all Fields that extend FieldtypeImage, but without FieldtypeFile
		$img = new Field();
		$img->type = $modules->get('FieldtypeImage');
		$fieldtypes = $img->type->getCompatibleFieldtypes($img)->getItems();
		unset($fieldtypes['FieldtypeFile']);
		$selector = 'type=' . implode('|',array_keys($fieldtypes));
		*/

		// get total number of pages with images
		$numPagesWithImages = 0;
		foreach ($imageFields as $f) $numPagesWithImages += wire('pages')->count("$f>0, include=all");

		$allImages = array();
		$limit = 1;
		$start = abs((int) wire('input')->get('start'));
		if($start >= $numPagesWithImages) $start = $numPagesWithImages;
		$baseurl = 'edit?name=' . __CLASS__ . '&mode=optimize&bulk=1';

		// get all images from pages that have image fields
		// this is slow for large number of variations on the page, since getVariations() use filesystem and not DB
		/*
		foreach ($imageFields as $f) {
			foreach (wire('pages')->find("$f>0, include=all, start=$start, limit=$limit") as $p) {
				$images = $p->getUnformatted($f->name);
				$id = $p->id;
				foreach ($images as $i) {
					//if($processOriginals) $allImages[] = $baseurl . "&id=$id&file=$id,{$i->basename}";
					//if($processVariations) foreach ($i->getVariations() as $v) $allImages[] = $baseurl . "&id=$id&file=$id,{$v->basename}";
					if($processOriginals) $allImages[] = "$id,{$i->basename}";
					if($processVariations) foreach ($i->getVariations() as $v) $allImages[] = "$id,{$v->basename}";
				}
			}
		}
		*/

		foreach ($imageFields as $f) {
			foreach (wire('pages')->find("$f>0, include=all, start=$start, limit=$limit") as $p) {
				$images = $p->getUnformatted($f->name);
				$id = $p->id;
				$filesArray = false;

				foreach ($images as $i) {
					if($processOriginals) $allImages[] = "$id,{$i->basename}";

					if($processVariations) {

						// create array of files in pagefiles folder eg. /site/assest/files/1234/
						if($filesArray === false) {
							$filesArray = array_diff(@scandir($i->pagefiles->path), array('.', '..', $i->basename)); // array_diff removes ., .. and self
						}

						// iterate over array of files and check if file is variation of current image
						//$variations = array();
						foreach($filesArray as $file) {
							//if($this->isVariation($i->basename, $file)) $variations[] = "$id,$file";
							if($this->isVariation($i->basename, $file)) $allImages[] = "$id,$file";
						}
						//$allImages = array_merge($allImages, $variations);

						// remove files that are variations from array of files to reduce the size of array, for better performance
						//$filesArray = array_diff($filesArray, $variations);
					}

					// remove original from array of files
					//$filesArray = array_diff($filesArray, array($i->basename));
				}
			}
		}

		$totalImages = count($allImages);
		$a = array();
		if($start < $numPagesWithImages) {
			$a["counter"] =	sprintf($this->_('Processing page %1$d out of %2$d - {%3$d}%% complete'), // {} is placeholder, must be present
											($start+1), $numPagesWithImages, (int) (($start) / $numPagesWithImages * 100));
		} else {
			$a["counter"] =	sprintf($this->_('All done - {100}%% complete'));
		}
		$a["numBatches"] = $numPagesWithImages;
		$a["numImages"] = $totalImages;
		$a["images"] = $allImages;
		header('Content-Type: application/json');
		echo json_encode($a);
		exit(0);

	}


	/**
	 * Optimize image
	 *
	 * @param Pageimage $img Pageimage object
	 * @param boolean $force true, when you want to force optimize the image
	 * @param string $mode 'auto', 'manual' or 'bulk'
	 * @return array
	 *
	 */
	public function optimize($img, $force = false, $mode = 'auto') {
		// todo: test with $config->pagefileExtendedPaths = true

		//$demo = false;
		$demo = $this->wire('config')->demo;

		$status = array(
			'error'           => null, // various errors
			'error_api'       => null, // errors from reSmush.it
			'percentNew'      => '0', // reduction percentage
			'file'            => $img->basename, // image name
			'basedir'         => basename(dirname($img->filename)), // page id where image is eg. 1234
			'url'             => $img->httpUrl // full url to the image
		);

		// force is only used in optimizeOnUpload
		if(!$force && !$this->isOptimizeNeeded) return false; // todo: return array?

		if(!in_array($img->ext, $this->allowedExtensions)) {
			$error = '($mode): Error optimizing ' . $img->filename . ': unsupported extension';
			$this->log->save($error);
			$status['error'] = 'unsupported extension';
			return $status;
		}

		$percentNew = 0;
		$opt = $src_size = $dest_size = $q = '';
		$mode1 = ucfirst(strtolower($mode));
		if(isset($this->configData["opt{$mode1}Quality"])) $q = $this->configData["opt{$mode1}Quality"];

		array_push($this->optimizeSettings['jpegoptim_options'], '-m' . $q);
		$this->optimizeSettings['jpegoptim_options'] = array_unique($this->optimizeSettings['jpegoptim_options']);

		if(isset($this->configData["opt{$mode1}Engine"]) && $this->configData["opt{$mode1}Engine"] == 'resmushit') {
			// use resmush.it web service
			$opt = "reSmush.it ($mode): ";

			if($img->filesize >= self::API_SIZELIMIT) {
				$error = 'Error optimizing ' . $img->filename . ', file larger then ' . self::API_SIZELIMIT . ' bytes';
				$this->log->save($opt . $error);
				$status['error'] = 'file to large';
				return $status;
			}

			// upload image using curl
			/*
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, self::WEBSERVICE . '&qlty=' . $q);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
			curl_setopt($ch, CURLOPT_TIMEOUT, self::CONNECTION_TIMEOUT);
			curl_setopt($ch, CURLOPT_POST, true);
			if(version_compare(PHP_VERSION, '5.5') >= 0) {
					$postfields = array ('files' => new CURLFile($img->filename, 'image/' . $img->ext, $img->basename));
					curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
			} else {
					$postfields = array ('files' => '@'.$img->filename);
			}
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
			$data = curl_exec($ch);
			if($data === false || curl_errno($ch)) {
				$error = 'Error optimizing ' . $img->filename . ': cURL error: ' . curl_error($ch);
				$this->log->save($error);
				$status['error'] = curl_error($ch);
				return $status;
			}
			curl_close($ch);
			*/

			// upload image using WireHttp class
			$http = new WireHttp();
			$http->setTimeout(self::CONNECTION_TIMEOUT); // important!!! default is 4.5 sec and that is to low
			$eol = "\r\n";
			$content = '';
			$boundary = strtolower(md5(time()));
			$content .= '--' . $boundary . $eol;
			$content .= 'Content-Disposition: form-data; name="files"; filename="' . $img->basename . '"' . $eol;
			$content .= 'Content-Type: image/' . $img->ext . $eol . $eol; // two eol's!!!!!
			$content .= file_get_contents($img->filename) . $eol;
			$content .= '--' . $boundary . '--' . $eol;
			$http->setHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);
			$data = $http->post(self::WEBSERVICE . '&qlty=' . $q, $content);
			if(is_bool($data)) {
				$error = 'Error optimizing ' . $img->filename . ', ';
				$error1 = $data === true ? 'request timeout' : $http->getHttpCode(true);
				$this->log->save($opt . $error . $error1 . ' (possible request timeout)');
				$status['error'] = $error1;
				return $status;
			}

			$response = json_decode($data);

			if($response === null) {
				$error = 'Error optimizing ' . $img->filename . ', returned data is empty';
				$this->log->save($opt . $error);
				$status['error'] = 'returned data is empty';
				return $status;
			}

			if(isset($response->error)) {
				$error = isset($this->apiErrorCodes[$response->error]) ? $this->apiErrorCodes[$response->error] : $response->error;
				$this->log->save($opt . 'Error optimizing ' . $img->filename . ', ' . $error);
				$status['error'] = $error;
				$status['error_api'] = $error;
				return $status;
			}

			$dest_size = $response->dest_size;
			$src_size = $response->src_size;

			// write to file only if optimized image is smaller
			if($dest_size < (int) ((100 - self::JPG_QUALITY_THRESHOLD) / 100 * $src_size)) {

				$http = new WireHttp();
				$http->setTimeout(self::CONNECTION_TIMEOUT);
				try {
					if(!$demo) $http->download($response->dest, $img->filename);//, array('useMethod' => 'fopen'));
					//$percentNew = 100 - (int) ($response->dest_size / $response->src_size * 100);
					$percentNew = (int) $response->percent;
				} catch(Exception $e) {
					$error = 'Error retreiving ' . $response->dest . ', ' . $e->getMessage();
					$this->log->save($opt . $error);
					$status['error'] = $e->getMessage();
					return $status;
				}
			}
		}

		else if(isset($this->configData["opt{$mode1}Engine"]) && $this->configData["opt{$mode1}Engine"] == 'localtools') {
			// use local (server) tools
			$opt = "ServerTools ($mode): ";

			$src_size = filesize($img->filename);
			$factory = new \ImageOptimizer\OptimizerFactory($this->optimizeSettings);
			$optimizer = $factory->get();
			//$optimizer = $factory->get('jpegoptim');

			try {
				// optimizer will throw exceptions if none of the optimizers in chain is not found
				if(!$demo) $optimizer->optimize($img->filename);  // optimized file overwrites original!
			} catch (Exception $e) {
				$error = $e->getMessage();
				$this->log->save($opt . 'Error optimizing ' . $img->filename . ', ' . $error);
				$status['error'] = $error;
				return $status;
			}

			clearstatcache(true, $img->filename);
			$dest_size = filesize($img->filename);
			$percentNew = 100 - (int) ($dest_size / $src_size * 100);

		} else {
			// no engine selected
			$opt = "No engine selected ($mode). ";
			$src_size = filesize($img->filename);
			$this->log->save($opt . $img->filename . ', source ' . $src_size . ' bytes');
			return $opt; // todo: return false, array, json?
		}

		// image is optimized
		$this->log->save($opt . $img->filename . ', source ' . $src_size . ' bytes, destination ' . $dest_size . ' bytes, reduction ' . $percentNew . '%');
		$status['percentNew'] = $percentNew . "";
		return $status;

	}

	/**
	 * Get message text
	 *
	 * @param string $key
	 * @return string
	 *
	 */
	private function getMessage($key = '') {
		return isset(self::$messages[$key]) ? self::$messages[$key] : '';
	}


	/**
	 * Checks for existance of optimizer executables
	 *
	 */
	private function checkOptimizers() {
		foreach($this->optimizers as $optimizer => $path) {
			$finder = new Symfony\Component\Process\ExecutableFinder();
			$exec = $finder->find($optimizer, '', $this->optimizersExtraPaths);
			$this->optimizers[$optimizer] = $exec;
		}
	}

	/**
	 * Find paths to serach for optimizer executables
	 *
	 * @return string path
	 *
	 */
	private function findPaths() {
		if(ini_get('open_basedir')) {
			$searchPath = explode(PATH_SEPARATOR, ini_get('open_basedir'));
			$dirs = array();
			foreach ($searchPath as $path) {
				// Silencing against https://bugs.php.net/69240
				if(@is_dir($path)) $dirs[] = $path;
				else {
					if(basename($path) == $name && is_executable($path)) return $path;
				}
			}
		} else {
			$dirs = array_merge(
				explode(PATH_SEPARATOR, getenv('PATH') ?: getenv('Path')),
				$this->optimizersExtraPaths
			);
			return implode(PATH_SEPARATOR . ' ', array_filter($dirs));
		}
	}


	/**
	 * Return the Pageimage object from page
	 * This is modified version of the method in ProcessPageEditImageSelect.module to account for
	 * variations made by CroppableImage3
	 *
	 * @param Page $page page that contains images
	 * @param bool $getVariation Returns the variation specified in the URL. Otherwise returns original (default).
	 * @return Pageimage|null
	 *
	 */
	public function getPageimage(Page $page, $getVariation = false) {

		//$images = $this->getImages($this->page);
		$images = $this->getImages($page); //MP
		$file = basename($this->input->get->file);
		$variationFilename = '';

		if(strpos($file, ',') === false) {
			// prepend ID if it's not there, needed for ajax in-editor resize
			$originalFilename = $file;
			$file = $page->id . ',' . $file;
		} else {
			// already has a "123," at beginning
			list($unused, $originalFilename) = explode(',', $file);
		}

		$originalFilename = $this->wire('sanitizer')->filename($originalFilename, false, 1024);

		// if requested file does not match one of our allowed extensions, abort
		//if(!preg_match('/\.(' . $this->extensions . ')$/iD', $file, $matches)) throw new WireException("Unknown image file");
		$extensions = 'jpg|jpeg|gif|png|svg'; //MP
		//$extensions = self::API_ALLOWED_EXTENSIONS; //MP
		//if(!preg_match('/\.(' . $extensions . ')$/iD', $file, $matches)) return null;
		if(!preg_match('/\.(' . $extensions . ')$/iD', $file, $matches)) return null; //MP

		// get the original, non resized version, if present
		// format:            w x h    crop       -suffix
		//if(preg_match('/(\.(\d+)x(\d+)([a-z0-9]*)(-[-_.a-z0-9]+)?)\.' . $matches[1] . '$/', $file, $matches)) {
		if(preg_match('/(\.(\d?)x?(\d?)([a-z0-9]*)(-[-_.a-z0-9]+)?)\.' . $matches[1] . '$/', $file, $matches)) { //MP
			// filename referenced in $_GET['file'] IS a variation
			// Follows format: original.600x400-suffix1-suffix2.ext
			// Follows format: original.-suffix1-suffix2.ext for CroppableImage3 //MP
			$this->editWidth = (int) $matches[2];
			$this->editHeight = (int) $matches[3];
			$variationFilename = $originalFilename;
			$originalFilename = str_replace($matches[1], '', $originalFilename); // remove dimensions and optional suffix
		} else {
			// filename referenced in $_GET['file'] is NOT a variation
			$getVariation = false;
		}

		// update $file as sanitized version and with original filename only
		$file = "{$page->id},$originalFilename";

		// if requested file is not one that we have, abort
		//if(!array_key_exists($file, $images)) throw new WireException("Invalid image file: $file");
		if(!array_key_exists($file, $images)) return null; //MP

		// return original
		if(!$getVariation) return $images[$file];

		// get variation
		$original = $images[$file];
		$variationPathname = $original->pagefiles->path() . $variationFilename;
		$pageimage = null;
		if(is_file($variationPathname)) $pageimage = $this->wire(new Pageimage($original->pagefiles, $variationPathname));
		//if(!$pageimage) throw new WireException("Unrecognized variation file: $file");
		if(!$pageimage) return null; //MP

		return $pageimage;
	}

	/**
	 * Get all Pageimage objects on page
	 * This is modified version of the method in ProcessPageEditImageSelect.module
	 *
	 * @param Page $page
	 * @param array|WireArray $fields
	 * @param int $level Recursion level (internal use)
	 * @return Pageimage array
	 *
	 */
	public function getImages(Page $page, $fields = array(), $level = 0) {

		$allImages = array();
		if(!$page->id) return $allImages;

		$numImages = 0;
		$numImageFields = 0;
		
		if(empty($fields)) $fields = $page->fields;

		foreach($fields as $field) {

			if($field->type instanceof FieldtypeRepeater) {
			//if(wireInstanceOf($field->type, 'FieldtypeRepeater')) { //MP only available in PW 3.0.73
				// get images that are possibly in a repeater
				$repeaterValue = $page->get($field->name);
				if($repeaterValue instanceof Page) $repeaterValue = array($repeaterValue); //MP support for FieldtypeFieldsetPage
				if($repeaterValue) foreach($repeaterValue as $p) {
					$images = $this->getImages($p, $p->fields, $level+1);
					if(!count($images)) continue;
					$allImages = array_merge($allImages, $images);
					$numImages += count($images);
					$numImageFields++;
				}
				continue;
			}

			if(!$field->type instanceof FieldtypeImage) continue;
			$numImageFields++;
			$images = $page->getUnformatted($field->name);
			if(!count($images)) continue;

			foreach($images as $image) {
				$numImages++;
				$key = $page->id . ',' . $image->basename;  // page_id,basename for repeater support
				$allImages[$key] = $image;
			}
		}

		return $allImages;
	}

}
