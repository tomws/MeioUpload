<?php
/**
 * MeioUpload Behavior
 *
 * @package      meio_upload
 * @subpackage   meio_upload.tests.cases.models.behaviors
 * @version      3.0
 * @link         http://github.com/jrbasso/MeioUpload
 * @license      MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
App::import('Core', array('File', 'Folder'));

class MeioUploadBehavior extends ModelBehavior {
/**
 * The default options for the behavior
 *
 * @var array
 * @access protected
 */
	var $_defaultOptions = array(
		'dir' => 'uploads{DS}{ModelName}{DS}{fieldName}',
		'adjustFilename' => 'fix', // ajust the filename. Can be 'fix', false/'none' or 'random'
		'zoomCrop' => false, // Whether to use ZoomCrop or not with PHPThumb
		'thumbsizes' => array(
			// Place any custom thumbsize in model config instead,
		),
		'thumbnailQuality' => 75, // Global Thumbnail Quality
		'useImageMagick' => false,
		'imageMagickPath' => '/usr/bin/convert', // Path to imageMagick on your server
		'fields' => array(
			'dir' => 'dir',
			'filesize' => 'filesize',
			'mimetype' => 'mimetype'
		)
	);

/**
 * Array of all possible images that can be converted to thumbnails
 *
 * @var array
 * @access protected
 */
	var $_imageTypes = array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon');

/**
 * The array that saves the $options for the behavior
 *
 * @var array
 * @access private
 */
	var $_config = array();

/**
 * Array of files to be removed on the afterSave callback
 *
 * @var access
 * @access private
 */
	var $__filesToRemove = array();

/**
 * Setup the behavior.
 * It stores a reference to the model and merge the default options with the options for each field.
 *
 * @param object $model
 * @param array $settings
 * @return void
 * @access public
 */
	function setup(&$model, $settings = array()) {
		$this->_config[$model->alias] = array();
		foreach ($settings as $field => $options) {
			if (!is_array($options)) {
				$field = $options;
				$options = array();
			}

			// Merge given options with defaults
			$options = Set::merge($this->_defaultOptions, $options);

			// Check if given field exists
			if ($model->useTable && !$model->hasField($field)) {
				trigger_error(sprintf(__d('meio_upload', 'MeioUploadBehavior Error: The field "%s" doesn\'t exists in the model "%s".', true), $field, $model->alias), E_USER_WARNING);
			}

			// Verifies if the thumbsizes names is alphanumeric
			foreach ($options['thumbsizes'] as $name => $size) {
				if (!ctype_alnum($name)) {
					trigger_error(__d('meio_upload', 'MeioUploadBehavior Error: The thumbsizes names must be alphanumeric.', true), E_USER_ERROR);
				}
			}

			// Replace tokens of the dir and field, check it doesn't have a DS on the end
			$options['dir'] = rtrim($this->_replaceTokens($model, $options['dir'], $field), DS);
			//$options['dir'] = $this->_normalizePath($options['dir']);

			// Replace tokens in the fields names
			foreach ($options['fields'] as $fieldToken => $fieldName) {
				$options['fields'][$fieldToken] = $this->_replaceTokens($model, $fieldName, $field);
			}

			$this->_config[$model->alias][$field] = $options;
		}
	}

/**
 * Initializes the upload
 *
 * @param object $model
 * @return boolean Whether the upload completed
 * @access public
 */
	function beforeSave(&$model) {
		$result = $this->_uploadFile($model);
		$allOk = true;
		foreach ($result as $fieldName => $return) {
			if ($return !== true) {
				$model->validationErrors[$fieldName] = $return;
				$allOk = false;
			}
		}
		return $allOk;
	}

/**
 * Deletes the files marked to be deleted in the save method.
 * A file can be marked to be deleted if it is overwriten by
 * another or if the user mark it to be deleted.
 *
 * @param object $model
 * @return void
 * @access public
 */
	function afterSave(&$model) {
		$this->_removeListOfFiles();
	}

/**
 * Mark to delete files that the registers will be deleted
 *
 * @param object $model
 * @return boolean Always true
 * @access public
 */
	function beforeDelete(&$model) {
		$model->read(null, $model->id);
		if (isset($model->data)) {
			foreach ($this->_config[$model->alias] as $field => $options) {
				$this->_setFileToRemove($model, $field);
			}
		}
		return true;
	}

/**
 * Deletes the files marked to be deleted in the delete method.
 * A file can be marked to be deleted if it is overwriten by
 * another or if the user mark it to be deleted.
 *
 * @param object $model
 * @return void
 * @access public
 */
	function afterDelete(&$model) {
		$this->_removeListOfFiles();
	}

/**
 * Test the consistency of upload before validate anything
 *
 * @param object $model
 * @return boolean
 * @access public
 */
	function beforeValidate(&$model) {
		return $this->_checkConsistency($model);
	}

/**
 * Change the destination directory at runtime
 *
 * @return void
 * @param string $field Name of the upload field, eg filename
 * @param string $dir The new destination directory
 * @access public
 */
	function changeDir(&$model, $field, $dir){
		$dir = $this->_normalizePath($dir);
		$this->_config[$model->alias][$field]['dir'] = rtrim($dir, DS);
	}

/**
 * Validator: Checks if the file isn't bigger then the max file size option.
 *
 * @param object $model
 * @param array $data
 * @param mixed $maxSize
 * @param mixed $extra
 * @return boolean
 * @access public
 */
	function uploadMaxSize(&$model, $data, $maxSize = 2097152, $extra = null) {
		if (!$extra) {
			$maxSize = 2097152;
		}
		$maxSize = $this->_sizeToBytes($maxSize);
		foreach ($data as $fieldName => $field) {
			if (!isset($field['size'])) {
				continue;
			}
			if ($field['size'] > $maxSize) {
				return false;
			}
		}
		return true;
	}

/**
 * Validator: Checks if the file is of an allowed mime-type.
 *
 * @param object $model
 * @param array $data
 * @param mixed $mimeAllowed
 * @param mixed $extra
 * @return boolean
 * @access public
 */
	function uploadMimeType(&$model, $data, $mimeAllowed, $extra = null) {
		if (!$extra) {
			$mimeAllowed = array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon');
		}
		if (!is_array($mimeAllowed)) {
			$mimeAllowed = array($mimeAllowed);
		}
		foreach ($data as $fieldName => $field) {
			if (!in_array($this->_getMimeType($field['tmp_name'], $field['type']), $mimeAllowed)) {
				return false;
			}
		}
		return true;
	}

/**
 * Validator: Checks if the file has an allowed extension.
 *
 * @param object $model
 * @param array $data
 * @param mixed $extAllowed
 * @param mixed $extra
 * @return boolean
 * @access public
 */
	function uploadExtension(&$model, $data, $extAllowed, $extra = null) {
		if (!$extra) {
			$extAllowed = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'ico');
		}
		if (!is_array($extAllowed)) {
			$extAllowed = array($extAllowed);
		}
		foreach ($data as $fieldName => $field) {
			if (!in_array(pathinfo($field['name'], PATHINFO_EXTENSION), $extAllowed)) {
				return false;
			}
		}
		return true;
	}

/**
 * Validator: Checks if the min width is allowed
 *
 * @param object $model
 * @param array $data
 * @param mixed $size
 * @return boolean
 * @access public
 */
	function uploadMinWidth(&$model, $data, $size) {
		return $this->_uploadCheckSize($data, $size, 'minWidth');
	}

/**
 * Validator: Checks if the max width is allowed
 *
 * @param object $model
 * @param array $data
 * @param mixed $size
 * @return boolean
 * @access public
 */
	function uploadMaxWidth(&$model, $data, $size) {
		return $this->_uploadCheckSize($data, $size, 'maxWidth');
	}

/**
 * Validator: Checks if the min height is allowed
 *
 * @param object $model
 * @param array $data
 * @param mixed $size
 * @return boolean
 * @access public
 */
	function uploadMinHeight(&$model, $data, $size) {
		return $this->_uploadCheckSize($data, $size, 'minHeight');
	}

/**
 * Validator: Checks if the max height is allowed
 *
 * @param object $model
 * @param array $data
 * @param mixed $size
 * @return boolean
 * @access public
 */
	function uploadMaxHeight(&$model, $data, $size) {
		return $this->_uploadCheckSize($data, $size, 'maxHeight');
	}


/**
 * Validator: Checks if is allowed to not submit a file
 *
 * @param object $model
 * @param array $data
 * @param boolean $allow
 * @return boolean
 * @access public
 */
	function uploadAllowEmpty (&$model, $data, $allow = false) {
	  $data = reset($data);
	  return ($data['error'] == UPLOAD_ERR_NO_FILE && $allow) || ($data['error'] != UPLOAD_ERR_NO_FILE);
	}

/**
 * Check generic to size of image
 *
 * @param array $data
 * @param integer $size Size in pixels
 * @param string $type Values: maxWidth, minWidth, maxHeight, minHeight
 * @return boolean
 * @access protected
 */
	function _uploadCheckSize(&$data, $size, $type) {
		if (!is_int($size) && !is_numeric($size)) {
			return false;
		}
		foreach ($data as $fieldName => $field) {
			list($imgWidth, $imgHeight) = getimagesize($field['tmp_name']);
			$imgType = 'img' . substr($type, 3);
			if (substr($type, 0, 3) === 'min') {
				if ($$imgType < $size) {
					return false;
				}
			} else {
				if ($$imgType > $size) {
					return false;
				}
			}
		}
		return true;
	}

/**
 * Check if upload is ok
 *
 * @param object $model
 * @return boolean
 * @access public
 */
	function _checkConsistency(&$model) {
		foreach ($this->_config[$model->alias] as $fieldName => $options) {
			if (!isset($model->data[$model->alias][$fieldName])) {
				continue;
			}
			$field = $model->data[$model->alias][$fieldName];
			if (!is_array($field)) {
				continue;
			}
			if ((!isset($field['error']) || ($field['error'] !== UPLOAD_ERR_OK && $field['error'] !== UPLOAD_ERR_NO_FILE)) && !isset($field['remove'])) {
				return false;
			}
		}
		return true;
	}

/**
 * Uploads the files
 *
 * @param object $model
 * @return array
 * @access protected
 */
	function _uploadFile(&$model) {
		$data =& $model->data;
		$return = array();
		foreach ($this->_config[$model->alias] as $fieldName => $options) {

			//Create the appropriate directory and thumbnails directories.
			$this->_createFolders($options['dir'], array_keys($options['thumbsizes']));

			if (!empty($data[$model->alias][$fieldName]['remove'])) {
				if (!empty($data[$model->alias][$model->primaryKey])) {
					$this->_setFileToRemove($model, $fieldName);
				}
				$this->_cleanFields($model, $fieldName);
				$return[$fieldName] = true;
				continue;
			}
			// If no file was selected we do not need to proceed
			if (empty($data[$model->alias][$fieldName]['name'])) {
				unset($data[$model->alias][$fieldName]);
				$return[$fieldName] = true;
				continue;
			}

			// If no file has been upload, then unset the field to avoid overwriting existant file
			if (!isset($data[$model->alias][$fieldName]) || !is_array($data[$model->alias][$fieldName]) || empty($data[$model->alias][$fieldName]['name'])) {
				if (!empty($data[$model->alias][$model->primaryKey])) {
					unset($data[$model->alias][$fieldName]);
				} else {
					$data[$model->alias][$fieldName] = null;
				}
			}

			$this->_adjustName($model, $fieldName);
			$saveAs = $options['dir'] . DS . $model->data[$model->alias][$fieldName]['name'];

			// Attempt to move uploaded file
			$copyResults = $this->_copyFileFromTemp($data[$model->alias][$fieldName]['tmp_name'], $saveAs);
			if ($copyResults !== true) {
				$return[$fieldName] = $copyResults;
				continue;
			}

			// If the file is an image, try to make the thumbnails
			$mimeType = $this->_getMimeType($data[$model->alias][$fieldName]['tmp_name'], $data[$model->alias][$fieldName]['type']);
			if (!empty($options['thumbsizes']) && !empty($options['allowedExt']) && in_array($mimeType, $this->_imageTypes)) {
				list(, $ext) = $this->_splitFilenameAndExt($data[$model->alias][$fieldName]['name']);
				$this->_createThumbnails($model, $fieldName, $saveAs, $ext, $options);
			}

			// Update model data
			if (!empty($options['fields']['dir'])) {
				$data[$model->alias][$options['fields']['dir']] = $options['dir'];
			}
			if (!empty($options['fields']['mimetype'])) {
				$data[$model->alias][$options['fields']['mimetype']] = $mimeType;
			}
			if (!empty($options['fields']['filesize'])) {
				$data[$model->alias][$options['fields']['filesize']] = $data[$model->alias][$fieldName]['size'];
			}
			$data[$model->alias][$fieldName] = $data[$model->alias][$fieldName]['name'];

			$return[$fieldName] = true;
			continue;
		}
		return $return;
	}

/**
 * Create all the thumbnails
 *
 * @param object $model
 * @param string $fieldName
 * @param string $saveAs
 * @param string $ext
 * @param array $options
 * @return void
 * @access protected
 */
	function _createThumbnails(&$model, $fieldName, $saveAs, $ext, $options) {
		foreach ($options['thumbsizes'] as $key => $value) {
			// Generate the name for the thumbnail
			$thumbSaveAs = $options['dir'] . DS . 'thumb' . DS . $key . DS . $model->data[$model->alias][$fieldName]['name'];

			$checkItems = array('width', 'height', 'thumbnailQuality', 'zoomCrop');
			$params = array();
			foreach ($checkItems as $property) {
				if (isset($value[$property])) {
					$params[$property] = $value[$property];
				}
			}
			$this->_createThumbnail($model, $saveAs, $thumbSaveAs, $fieldName, $params);
		}
	}

/**
 * Function to create Thumbnail images
 *
 * @param object $model
 * @param string $source Source file name (without path)
 * @param string $target Target file name (without path)
 * @param string $fieldName Path to source and destination (no trailing DS)
 * @param array $params
 * @return void
 * @access protected
 */
	function _createThumbnail(&$model, $source, $target, $fieldName, $params = array()) {
		$params = array_merge(
			array(
				'thumbnailQuality' => $this->_config[$model->alias][$fieldName]['thumbnailQuality'],
				'zoomCrop' => false
			),
			$params);

		// Import phpThumb class
		App::import('Vendor', 'phpthumb', array('file' => 'phpThumb' . DS . 'phpthumb.class.php'));

		// Configuring thumbnail settings
		$phpThumb = new phpthumb;
		$phpThumb->setSourceFilename($source);

		$w = isset($params['width']);
		$h = isset($params['height']);
		if ($w && $h) {
			$phpThumb->w = $params['width'];
			$phpThumb->h = $params['height'];
		} elseif ($w && !$h) {
			$phpThumb->w = $params['width'];
		} elseif ($h && !$w) {
			$phpThumb->h = $params['height'];
		} else {
			trigger_error(__d('meio_upload', 'Width and Height of thumbs not specified.', true), E_USER_WARNING);
			return;
		}

		$phpThumb->setParameter('zc', $params['zoomCrop']);
		$phpThumb->q = $params['thumbnailQuality'];

		list(, $phpThumb->config_output_format) = explode('.', $source, 2);
		$phpThumb->config_prefer_imagemagick = $this->_config[$model->alias][$fieldName]['useImageMagick'];
		$phpThumb->config_imagemagick_path = $this->_config[$model->alias][$fieldName]['imageMagickPath'];

		// Setting whether to die upon error
		$phpThumb->config_error_die_on_error = true;
		// Creating thumbnail
		if ($phpThumb->GenerateThumbnail()) {
			if (!$phpThumb->RenderToFile($target)) {
				trigger_error(sprintf(__d('meio_upload', 'Could not render image to: %s', true), $target), E_USER_WARNING);
			}
		}
	}

/**
 * Replaces some tokens. {ModelName} to the underscore version of the model name
 * {fieldName} to the field name, {DS}. / or \ to DS constant value.
 *
 * @param object $model
 * @param string $string
 * @param string $fieldName
 * @return string
 * @access protected
 */
	function _replaceTokens(&$model, $string, $fieldName) {
		return str_replace(
			array('{ModelName}', '{fieldName}', '{DS}'),//, '/', '\\'),
			array(Inflector::underscore($model->name), $fieldName, DS),//, DS, DS),
			$string
		);
	}

/**
 * Removes the bad characters from the $filename. It updates the $model->data.
 *
 * @param object $model
 * @param string $fieldName
 * @param boolean $checkFile
 * @return void
 * @access protected
 */
	function _adjustName(&$model, $fieldName, $checkFile = true) {
		// updates the filename removing the keywords thumb and default name for the field.
		switch ($this->_config[$model->alias][$fieldName]['adjustFilename']) {
			case 'fix':
				list ($filename, $ext) = $this->_splitFilenameAndExt($model->data[$model->alias][$fieldName]['name']);
				$filename = Inflector::slug($filename);
				$i = 0;
				$newFilename = $filename;
				if ($checkFile) {
					while (file_exists($this->_config[$model->alias][$fieldName]['dir'] . DS . $newFilename . '.' . $ext)) {
						$newFilename = $filename . '-' . $i++;
					}
				}
				$model->data[$model->alias][$fieldName]['name'] = $newFilename . '.' . $ext;
				break;
			case 'random':
				list (, $ext) = $this->_splitFilenameAndExt($model->data[$model->alias][$fieldName]['name']);
				$model->data[$model->alias][$fieldName]['name'] = uniqid('meio_', true) . '.' . $ext;
				break;
		}
	}

/**
 * Splits a filename in two parts: the name and the extension. Returns an array with it respectively.
 *
 * @param string $filename
 * @return array
 * @access protected
 */
	function _splitFilenameAndExt($filename) {
		extract(pathinfo($filename));
		if (!isset($filename)) {
			$filename = substr($basename, 0, -1 - count($extension)); // Remove extension and .
		}
		return array($filename, $extension);
	}

/**
 * Convert a size value to bytes. For example: 2 MB to 2097152.
 *
 * @param string $size
 * @return int
 * @access protected
 */
	function _sizeToBytes($size) {
		if (is_numeric($size) || ctype_digit($size)) {
			return (int)$size;
		}
		if (!preg_match('/^(\d+) ?(kb|mb|gb|tb)$/i', $size, $matches)) {
			trigger_error(__d('meio_upload', 'MeioUploadBehavior Error: The maxSize option format is invalid. Using 2 MB (default).', true), E_USER_WARNING);
			return 2097152;
		}
		switch (strtolower($matches[2])) {
			case 'kb':
				return $matches[1] * 1024;
			case 'mb':
				return $matches[1] * 1048576;
			case 'gb':
				return $matches[1] * 1073741824;
			case 'tb':
				return $matches[1] * 1099511627776;
		}
		return 2097152;
	}

/**
 * Creates thumbnail folders if they do not already exist
 *
 * @param string $dir Path to uploads
 * @param array $thumbsizes List of names of thumbnail type
 * @return void
 * @access protected
 */
	function _createFolders($dir, $thumbsizes) {
		$folder = new Folder();

		$folder->create($dir); // If thumbsizes is empty
		foreach ($thumbsizes as $thumbName) {
			$folder->create($dir . DS . 'thumb' . DS . $thumbName);
		}
	}

/**
 * Copies file from temporary directory to final destination
 *
 * @param string $tmpName full path to temporary file
 * @param string $saveAs full path to move the file to
 * @return mixed true is successful, error message if not
 * @access protected
 */
	function _copyFileFromTemp($tmpName, $saveAs) {
		if (!is_uploaded_file($tmpName)) {
			return false;
		}
		if (!move_uploaded_file($tmpName, $saveAs)) {
			return __d('meio_upload', 'Problems in the copy of the file.', true);
		}
		return true;
	}

/**
 * Set a file to be removed
 *
 * @parma object $model
 * @param string $fieldName
 * @return void
 * @access protected
 */
	function _setFileToRemove(&$model, $fieldName) {
		$filename = $model->field($fieldName);
		if (!empty($filename)) {
			$this->__filesToRemove[] = array(
				'field' => $fieldName,
				'dir' => $this->_config[$model->alias][$fieldName]['dir'],
				'name' => $filename
			);
			foreach($this->_config[$model->alias][$fieldName]['thumbsizes'] as $key => $sizes){
				$this->__filesToRemove[] = array(
					'field' => $fieldName,
					'dir' => $this->_config[$model->alias][$fieldName]['dir'] . DS . 'thumb' . DS . $key,
					'name' => $filename
				);
			}
		}
	}

/**
 * Clean fields from $data
 *
 * @param object $model name of the Model
 * @param string $fieldName name of field that holds a reference to the file
 * @return void
 * @access protected
 */
	function _cleanFields(&$model, $fieldName) {
		$model->data[$model->alias][$fieldName] = '';

		$options = $this->_config[$model->alias][$fieldName]['fields'];
		if (!empty($options['dir'])) {
			$model->data[$model->alias][$options['dir']] = '';
		}
		if (!empty($options['filesize'])) {
			$model->data[$model->alias][$options['filesize']] = '';
		}
		if (!empty($options['mimetype'])) {
			$model->data[$model->alias][$options['mimetype']] = '';
		}
	}

/**
 * Remove invalid files
 *
 * @return void
 * @access protected
 */
	function _removeListOfFiles() {
		foreach ($this->__filesToRemove as $info) {
			$info['dir'] = $this->_normalizePath($info['dir']);
			$file =& new File($info['dir'] . DS . $info['name']);
			$file->delete();
		}
	}

/**
 * Transform in realpath
 *
 * @param string $dir
 * @return string
 * @access protected
 */
	function _normalizePath($dir) {
		if ($dir[0] !== '/' && $dir[0] !== '\\' && !preg_match('/^[a-z]:/i', $dir)) { // Relative path
			$dir = WWW_ROOT . $dir;
		}
		return $dir;
	}

/**
 * Get the mime type of file
 *
 * @param string $file
 * @param string $mimeType
 * @return string
 * @access protected
 */
	function _getMimeType($file, $mimeType = 'application/octet-stream') {
		if (!is_readable($file)) {
			return $mimeType;
		}
		if ($mimeType !== 'application/octet-stream') {
			return $mimeType;
		}
		if (function_exists('finfo_file')) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mime = finfo_file($finfo, $file);
			if (!empty($mime)) {
				return $mime;
			}
		}
		if (function_exists('mime_content_type')) {
			return mime_content_type($file);
		}
		if (function_exists('getimagesize')) {
			$info = @getimagesize($file);
			if (!empty($info['mime'])) {
				return $info['mime'];
			}
		}
		return $mimeType;
	}

}
