<?php
/**
 * This class has some static methods for utility functions that can be used
 * before the class is instantiated.
 *
 */
// We need this for the xPDOTransport class constants
require_once MODX_CORE_PATH.'xpdo/transport/xpdotransport.class.php';

class Repoman {

	public $modx;
	
	public $config = array();
	// Used when tracking build attributes and fromDeepArray
    public $breadcrumb = array();
    // Used to provide transparency
    public static $queue = array();
	public $readme_filenames = array('README.md','readme.md');

    public static $cache_opts = array();
    
    public $prepped = false;
    
    const CACHE_DIR = 'repoman';
    const CONFIG_FILE = 'config.php';
        
	/**
	 *
	 * @param object MODX reference
	 */
	public function __construct($modx,$config=array()) {
		$this->modx = &$modx;
		$this->config = $config;
		self::$cache_opts = array(xPDO::OPT_CACHE_KEY => self::CACHE_DIR);
	}

    /**
     * Add packages to MODX's radar so we can use their objects.
     *
     * @param array $args
     */
    private static function _addPkgs($args) {
        global $modx;
        
        $pkg = (isset($args['packages'])) ? $args['packages'] : false;
        if ($pkg && is_array($pkg)) {
            foreach ($pkg as $p) {
                $modx->addPackage($p[0],$p[1],$p[2]);   
            }
        }
        elseif ($pkg) {
            $parts = explode(':',$pkg);
            if (isset($parts[2])) {
                $modx->addPackage($parts[0],$parts[1],$parts[2]);     
            }
            elseif(isset($parts[1])) {
                $modx->addPackage($parts[0],$parts[1]);
            }
            else {
                $modx->addPackage($parts[0],MODX_CORE_PATH.'components/'.$parts[0].'/model/');
            }
        }
    }
    
    /**
     * Make sure build attributes have been defined for the current breadcrumb.
     * 
     * @param array $atts
     * @param string $classname (for messaging)
     * @return void or throws error
     */
    private function _check_build_attributes($atts,$classname) {
        
        if ($this->get('overwrite')) return;
        
        foreach ($this->breadcrumb as $i => $alias) {
            if (isset($atts[xPDOTransport::RELATED_OBJECT_ATTRIBUTES][$alias])) {
                $atts = $atts[xPDOTransport::RELATED_OBJECT_ATTRIBUTES][$alias]; 
                // Do something?
            }
            else {
                throw new Exception('Build attributes not set for '.$classname.'-->'.implode('-->',$this->breadcrumb));
            }
        }
    }

	/**
	 * Create/Update the namespace
	 * @param string $pkg_root_dir to the repo
	 */
	private function _create_namespace($pkg_root_dir) {
        $this->modx->log(modX::LOG_LEVEL_DEBUG, "Creating namespace: ".$this->get('namespace'));
        
        $name = $this->get('namespace');
        if (empty($name)) {
            throw new Exception('namespace parameter cannot be empty.');
        }
        if (preg_match('/[^a-z0-9_\-]/', $this->get('namespace'))) {
            throw new Exception('Invalid namespace :'.$this->get('namespace'));
        }

		$N = $this->modx->getObject('modNamespace',$this->get('namespace'));
		if (!$N) {
			$N = $this->modx->newObject('modNamespace');
			$N->set('name', $this->get('namespace'));
		}
		$N->set('path', $this->get_src_dir($pkg_root_dir));
		$N->set('assets_path',$this->get_assets_dir($pkg_root_dir));
		
		Repoman::$queue['modNamespace'][] = $this->get('namespace');

        if (!$this->get('dry_run')) {
    		$N->save();
    		// Prepare Cache folder for tracking object creation
    		self::$cache_opts = array(xPDO::OPT_CACHE_KEY => self::CACHE_DIR.'/'.$this->get('namespace'));
    		$data = $this->get_criteria('modNamespace',$N->toArray());
            $this->modx->cacheManager->set('modNamespace/'.$N->get('name'), $data, 0, Repoman::$cache_opts);
    		$this->modx->log(modX::LOG_LEVEL_INFO, "Namespace created/updated: ".$this->get('namespace'));
        }
	}
    
	/**
	 * For creating Repoman's system settings (not for user created settings)
	 *
	 *     pkg_name.assets_path
	 *     pkg_name.assets_url
	 *     pkg_name.core_path
	 *
	 * @param string $name
	 */
	private function _create_setting($namespace, $key, $value) {

        if (empty($namespace)) {
            throw new Exception('namespace parameter cannot be empty.');
        }
	
		$Setting = $this->modx->getObject('modSystemSetting', array('key'=>$key));

		if (!$Setting) {
            $this->modx->log(modX::LOG_LEVEL_INFO, "Creating new System Setting: $key");
			$Setting = $this->modx->newObject('modSystemSetting');	
		}

		$Setting->set('key', $key);
		$Setting->set('value', $value);
		$Setting->set('xtype', 'textfield');
		$Setting->set('namespace', $namespace);
		$Setting->set('area', 'default');
		
		Repoman::$queue['modSystemSetting'][] = $key;		
		if (!$this->get('dry_run')) {
            $Setting->save();
    		$data = $this->get_criteria('modSystemSetting', $Setting->toArray());
    		$this->modx->log(modX::LOG_LEVEL_INFO, "System Setting created/updated: $key");
        }
	}

    /**
     * Set up the local MODX instance for normal repoman action: 
     * create namespace and expected System Settings for the package.
     *
     */
    private function _prep($pkg_root_dir) {
        if ($this->prepped) {
            return;
        }
        
        $this->modx->log(modX::LOG_LEVEL_DEBUG, "Prep: creating namespace and system settings.");
        
        $this->_create_namespace($pkg_root_dir);
       
        // Settings
        $rel_path = preg_replace('/^'.preg_quote(MODX_BASE_PATH,'/').'/','', $pkg_root_dir); // convert path to url
        $assets_url = MODX_BASE_URL.$rel_path .'assets/';
        $this->_create_setting($this->get('namespace'), $this->get('namespace').'.assets_url', $assets_url);
        $this->_create_setting($this->get('namespace'), $this->get('namespace').'.assets_path', $pkg_root_dir.'assets/');
        $this->_create_setting($this->get('namespace'), $this->get('namespace').'.core_path', $pkg_root_dir .'core/');      
        
        $this->prepped = true;
    }
    
	/**
	 * Get an array of element objects for the given $objecttype
	 *
	 * @param string $objecttype
     * @param string $pkg_root_dir path to local package root (w trailing slash)
	 * @return array of objects of type $objecttype
	 */
	private function _get_elements($objecttype,$pkg_root_dir) {
        require_once dirname(__FILE__).'/repoman_parser.class.php';
        require_once dirname(__FILE__).'/objecttypes/'.strtolower($objecttype).'_parser.class.php';        
        $classname = $objecttype.'_parser';
        $Parser = new $classname($this);
        return $Parser->gather($pkg_root_dir);
	}
		
    //------------------------------------------------------------------------------
    //! Static
    //------------------------------------------------------------------------------
	/**
	 * Verify a directory, converting for any OS variants and convert
	 * any relative paths to absolute . 
	 *
	 * @param string $path path (or relative path) to package
	 * @return string full path with trailing slash
	 */
	public static function get_dir($path) {
            $realpath = strtr(realpath($path), '\\', '/');
            if (!file_exists($realpath)){
                throw new Exception('Directory does not exist: '.$path);
            }
            elseif(!is_dir($realpath)) {
                throw new Exception('Path is not a directory: '.$realpath);
            }
        
            return rtrim($realpath,'/') .'/';
	}

	/**
	 * Assistence function for examining MODX objects and their relations.
	 * _pkg (string) colon-separated string defining the arguments for addPackage() -- 
     *      package_name, model_path, and optionally table_prefix  
     *      e.g. `tiles:[[++core_path]]components/tiles/model/:tiles_` or 
     *      If only the package name is supplied, the path is assumed to be "[[++core_path]]components/$package_name/model/"
     *
	 * Optional options:
     *      aggregates : if set, only aggregate relationships will be shown.
     *      composites : if set, only composite relationships will be shown.
     *      pkg : colon-separated input for loading a package via addPackage.
     *
	 * @param array $args
	 * @return string message
	 */
	public static function graph($classname, $args) {

        global $modx; // why global?  Just because...
             
        $aggregates = (isset($args['aggregates'])) ? $args['aggregates'] : false;
        $composites = (isset($args['composites'])) ? $args['composites'] : false;

        self::_addPkgs($args);

        //Load up configs packages
        if ($dir = $modx->getOption('repoman.dir')) {
            $dir = self::get_dir($dir);
            foreach (scandir($dir) as $file) {
                if ('.' === $file) continue;
                if ('..' === $file) continue;
                if (is_dir($dir.$file)) {
                    $attributes = self::load_config($dir.$file.'/');
                    self::_addPkgs($attributes);
                }
            }
        }
        
        if (empty($classname)) {
            $out = "\n-------------------------\n";
            $out .= "All Available Classes\n";
            $out .= "-------------------------\n";
            foreach ($modx->classMap as $parentclass => $childclasses) {
                            
                $out .= "\n".$parentclass."\n".str_repeat('-', strlen($parentclass))."\n"; 
                foreach ($childclasses as $c) {
                    $out .= "    ".$c."\n";
                }
            }
            return $out;
        }
        
        if (empty($classname)) {
            throw new Exception('classname is required.');
        }

        $array = $modx->getFields($classname);
        
        // Default
        $related = array_merge($modx->getAggregates($classname), $modx->getComposites($classname));

        if ($aggregates) {
            $related = $modx->getAggregates($classname);
        }
        elseif ($composites) {
            $related = $modx->getComposites($classname);
        }

        foreach ($related as $alias => $def) {
            $array[$alias] = $def;    
        }
        
        $out = print_r($array,true); 
        
        // Try to make the result pretty. TODO: make it have correct syntax!!!
        $out = str_replace(array('Array','[',']',')'), array('array',"'","'",'),'), $out);
        
        return $out;
	}

	/** 
	 * Get configuration for a given package path.
	 * This reads the config.php (if present), and merges it with global config
	 * settings.
	 *
     * @param string $pkg_root_dir path to local package root (w trailing slash)
     * @param array $overrides any run-time overrides
	 * @return array
	 */
	public static function load_config($pkg_root_dir, $overrides=array()) {
	
        $global = include dirname(__FILE__).'/global.config.php';
        $config = array();
        if (file_exists($pkg_root_dir.self::CONFIG_FILE)) {
            $config = include $pkg_root_dir.self::CONFIG_FILE;
            if (!is_array($config)) {    
                $config = array();
            }
            if (isset($config['package_name']) && !isset($config['category'])) {
                $config['category'] = $config['package_name'];
            }
        }
        
        // This nukes any deeply nested structure, e.g. build_attributes
        $out = array_merge($global, $config, $overrides);
        
        $out['build_attributes'] = $global['build_attributes'];
        if (isset($config['build_attributes']) && is_array($config['build_attributes'])) {
            foreach ($config['build_attributes'] as $classname => $def) {
                $out['build_attributes'][$classname] = $def;
            }
        }
        return $out;
	}
	
	/**
	 * Parse command line arguments
	 *
	 * @param array $args
	 * @return array
	 */
	public static function parse_args($args) {
        $overrides = array();
        foreach($args as $a) {
            if (substr($a,0,2) == '--') {
                if ($equals_sign = strpos($a,'=',2)) {
                    $key = substr($a, 2, $equals_sign-2);
                    $val = substr($a, $equals_sign+1);
                    $overrides[$key] = $val;
                }
                else {
                    $flag = substr($a, 2);
                    $overrides[$flag] = true;
                }
            }
        }	
        return $overrides;
	}
		
	/** 
	 * Recursively remove a directory and all its subdirectories and files.
	 * See http://www.php.net/manual/en/function.rmdir.php
	 * @param string $dir full path name (with or without trailing slash)
	 */
    public static function rrmdir($dir) {
        foreach(glob($dir . '/*') as $file) {
            if(is_dir($file)) {
                Repoman::rrmdir($file);
            }
            else {
                unlink($file);
            }
        }
        return rmdir($dir);
    }
    
	/**
	 * Shows manual page for a given $function.
	 *
	 * @param string $function
	 * @return string
	 */
	public static function rtfm($function) {
        $doc = dirname(dirname(dirname(__FILE__))).'/docs/'.basename($function).'.txt';
        if (file_exists($doc)) {
            return file_get_contents($doc) . "\n\n";
        }
        return "No manual page found.\n";
	}
       	
	//------------------------------------------------------------------------------
	//! Public
	//------------------------------------------------------------------------------
	
    /** 
     * Unified build script: build a MODX transport package from files contained
     * inside $pkg_root_dir
     *
     * @param string $pkg_root_dir path to local package root (w trailing slash)
     */
    public function build($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);
        
        $this->config['is_build'] = true; // TODO
        $this->config['force_static'] = false; // TODO
        
        $required = array('package_name','namespace','version','release');
        foreach($required as $k) {
            if (!$this->get($k)) {
                throw new Exception('Missing required configuration parameter: '.$k);
            }
        }
        
        $this->modx->log(modX::LOG_LEVEL_INFO, 'Beginning build of package "'.$this->get('package_name').'"');
        
        $this->modx->loadClass('transport.modPackageBuilder', '', false, true);
        $builder = new modPackageBuilder($this->modx);
        $builder->createPackage($this->get('package_name'), $this->get('version'), $this->get('release'));
        $builder->registerNamespace($this->get('namespace'), false, true, '{core_path}components/' . $this->get('namespace').'/');
        
        // Tests (Validators): this is run BEFORE your package code is in place
        // so you cannot include package files from your validator! They won't exist when the code is run.
        $validator_file = $this->get_src_dir($pkg_root_dir).$this->get('validators_dir').'/install.php';
        if (file_exists($validator_file)) {
            $this->modx->log(modX::LOG_LEVEL_INFO, 'Packaging validator '.$validator_file);
            $config = $this->config;
            $config['source'] = $validator_file;
            $validator_attributes = array(
                'vehicle_class' => 'xPDOScriptVehicle',
                'source' => $validator_file,
                xPDOTransport::ABORT_INSTALL_ON_VEHICLE_FAIL => $this->get('abort_install_on_fail')
            );
            $vehicle = $builder->createVehicle($config,$validator_attributes);
            $builder->putVehicle($vehicle);
        }
        else {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, 'No validator detected at '.$validator_file);
        }
        
        $Category = $this->modx->newObject('modCategory');
        $Category->set('category', $this->get('category'));

        // Import Elements
        $chunks = self::_get_elements('modChunk',$pkg_root_dir);
        $plugins = self::_get_elements('modPlugin',$pkg_root_dir);
        $snippets = self::_get_elements('modSnippet',$pkg_root_dir);
        $templates = self::_get_elements('modTemplate',$pkg_root_dir);
        //$tvs = self::_get_elements('modTemplateVar',$pkg_root_dir);

        if ($chunks) $Category->addMany($chunks);
        if ($plugins) $Category->addMany($plugins);
        if ($snippets) $Category->addMany($snippets);
        if ($templates) $Category->addMany($templates);
        //if ($tvs) $Category->addMany($tvs);

        // TODO: skip this if there are no elements
        //if (empty($chunks) && empty($plugins) && empty($snippets) && empty($templates) && empty($tvs)) {
        $build_attributes = array();
        $build_attributes = $this->get_build_attributes($Category,'modCategory');
        $this->modx->log(modX::LOG_LEVEL_DEBUG, 'Build attributes for '. $Category->_class. "\n".print_r($build_attributes,true));
        $vehicle = $builder->createVehicle($Category, $build_attributes);
        //}
        //$builder->putVehicle($vehicle);


        // Files...: TODO: these need their own builder
        // Assets
        $dir = $this->get_assets_dir($pkg_root_dir);
        if (file_exists($dir) && is_dir($dir)) {
            $this->modx->log(modX::LOG_LEVEL_INFO, 'Packing assets from '.$dir);
            $vehicle->resolve('file', array(
                'source' => rtrim($dir,'/'),
                'target' => "return MODX_ASSETS_PATH . 'components/';",
            ));
        }        
        // Core
        $dir = $this->get_src_dir($pkg_root_dir);
        if (file_exists($dir) && is_dir($dir)) {
            $this->modx->log(modX::LOG_LEVEL_INFO, 'Packing core files from '.$dir);
            $vehicle->resolve('file', array(
                'source' => rtrim($dir,'/'),
                'target' => "return MODX_CORE_PATH . 'components/';",
            ));
        }

        $builder->putVehicle($vehicle);
        
        // Migrations: we attach our all-purpose resolver to handle migrations
        $config = $this->config;
        $config['source'] = dirname(__FILE__).'/resolver.php';
        $attributes = array('vehicle_class' => 'xPDOScriptVehicle');        
        $vehicle = $builder->createVehicle($config,$attributes);
        $builder->putVehicle($vehicle);

        // Add Version Setting
        $repoman_version_build_attributes = array(
        	xPDOTransport::UNIQUE_KEY => 'key',
        	xPDOTransport::PRESERVE_KEYS => true,
        	xPDOTransport::UPDATE_OBJECT => true, // Tricky: we need to update the value here
        );
        $VersionSetting = $this->modx->newObject('modSystemSetting');
        $VersionSetting->set('key', $this->get('namespace').'.version');
        $VersionSetting->set('value', $this->get('version'));
        $VersionSetting->set('xtype', 'textfield');
        $VersionSetting->set('namespace', $this->get('namespace'));
        $VersionSetting->set('area', $this->get('namespace').':default');
        $vehicle = $builder->createVehicle($VersionSetting, $repoman_version_build_attributes);
        $builder->putVehicle($vehicle);
        
        
        // Optionally Load Seed data
        $dirs = $this->get_seed_dirs($pkg_root_dir);
        foreach ($dirs as $d) {
        $objects = $this->crawl_dir($d);
            foreach ($objects as $classname => $info) {
                foreach ($info as $k => $Obj) {
                    $build_attributes = $this->get_build_attributes($Obj,$classname);
                    $this->modx->log(modX::LOG_LEVEL_DEBUG, $classname. ' created');
                    $vehicle = $builder->createVehicle($Obj, $build_attributes);
                    $builder->putVehicle($vehicle);
                }
            }
        }
        
        // Package Attributes (Documents)
        $dir = $this->get_docs_dir($pkg_root_dir);
        // defaults
        $docs = array(
            'readme'=>'This package was built using Repoman (https://github.com/craftsmancoding/repoman/)',
            'changelog'=>'No change log defined.',
            'license'=> file_get_contents(dirname(dirname(dirname(__FILE__))).'/docs/license.txt'),
        );        
        if (file_exists($dir) && is_dir($dir)) {
            $files = array();
            $build_docs = $this->get('build_docs');
            if (!empty($build_docs) && is_array($build_docs)) {
                foreach ($build_docs as $d) {
                    $files[] = $dir . $d;
                }
            }
            else {            
                $files = glob($dir.'*.{html,txt}',GLOB_BRACE);
            }
            
            foreach($files as $f) {
                $stub = basename($f,'.txt');
                $stub = basename($stub,'.html');
                $docs[$stub] = file_get_contents($f);
                if (strtolower($stub) == 'readme') {
                    $docs['readme'] = $docs['readme'] ."\n\n"
                        .'This package was built using Repoman (https://github.com/craftsmancoding/repoman/)';
                }
                $this->modx->log(modX::LOG_LEVEL_INFO, "Adding doc $stub from $f");
            }            
        }
        else {
            $this->modx->log(modX::LOG_LEVEL_INFO, 'No documents found in '.$dir);
        }
        $builder->setPackageAttributes($docs);        
        // Zip up the package
        $builder->pack();

        $zip = $this->get('namespace').'-'.$this->get('version').'-'.$this->get('release').'.transport.zip';
        $this->modx->log(modX::LOG_LEVEL_INFO, 'Build complete: '. MODX_CORE_PATH.'packages/'.$zip);
    }
    
	/**
	 * Iterate over the specified $dir to load up either PHP or JSON arrays representing objects,
	 * then return an array of the corresponding objects.  The classname of the objects must be 
	 * inherent in the filename.  Filenames may have the following format:
	 *
	 *     classname[.identifier].(php|json)
	 *
	 * For example, modSystemSetting.php contains a MODX System Setting, or modUser.1.json contains
	 * a user. 
	 *	 
	 * @param string $dir
	 * @return array of objects : keys for the classname
	 */
	public function crawl_dir($dir) {
        
        if (!file_exists($dir) || !is_dir($dir)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,'Could not crawl directory. Directory does not exist: '.$dir);
            return array();
        }
        $this->modx->log(modX::LOG_LEVEL_INFO,'Crawling directory for objects '.$dir);

        $objects = array();
        $files = glob($dir.'/*{.php,.json}',GLOB_BRACE);

        foreach($files as $f) {
            
            preg_match('/^(\w+)(.?\w+)?\.(\w+)$/', basename($f), $matches);
            if (!isset($matches[3])) throw new Exception('Invalid filename '.$f);
            
            $classname = $matches[1];
            $ext = $matches[3];            
            $this->modx->log(modX::LOG_LEVEL_INFO,'Processing object(s) in '.basename($f));
            $fields = $this->modx->getFields($classname);
            if (empty($fields)) throw new Exception('Unrecognized object classname: '.$classname);

            $is_json = (strtolower($ext) == 'php')? false : true;
            
            $data = $this->load_data($f, $is_json);
            
            $i = 0;
            $attributes = $this->get('build_attributes');
            if (!isset($attributes[$classname])) {
                throw new Exception('Build attributes not defined for '.$classname);
            }
            foreach ($data as $objectdata) {
                // Does the object already exist?
                if (!$this->get('is_build')) {
                    $Object = $this->modx->getObject($classname, $this->get_criteria($classname,$objectdata));
                    if ($Object && !$attributes[$classname][xPDOTransport::UPDATE_OBJECT] && !$this->get('overwrite')) {
                        $this->modx->log(modX::LOG_LEVEL_INFO,'Skipping... Update Object not allowed without overwrite: '.$classname);
                        continue;
                    }
                }

                $this->breadcrumb = array();
                $objects[$classname][] = $this->fromDeepArray($classname,$objectdata,true,true,true,0);
                $this->_check_build_attributes($attributes[$classname], $classname);

            }
	   }
	   return $objects;
	}
    
    /**
     * Extract objects (Settings, Snippets, Pages et al) from MODX and store them in the
     * repository as either object or seed data.
     *
     * --classname 
     * --where
     * --overwrite
     * --package : package_name, model_path, and optionally table_prefix  
     *      e.g. `tiles:[[++core_path]]components/tiles/model/:tiles_` or 
     *      If only the package name is supplied, the path is assumed to be "[[++core_path]]components/$package_name/model/"
     *
     * @param string $pkg_root_dir path to local package root (w trailing slash)     
     */
    public function export($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);
        $classname = $this->get('classname');
        $where = $this->get('where');
        $target = $this->get('target');
        $graph = $this->get('graph');
        $limit = (int) $this->get('limit'); // how many records per file?
        if (!$limit) {
            $limit = 1; // no div by zero
        }
        
        if (empty($classname)) {
            throw new Exception('Parameter "classname" is required.');
        }

        $is_element = false;
        $Parser = null;
        if (in_array($classname, $this->get('export_elements'))) {
            require_once dirname(__FILE__).'/repoman_parser.class.php';
            require_once dirname(__FILE__).'/objecttypes/'.strtolower($classname).'_parser.class.php';
            $is_element = true;
            $element_class = strtolower($classname).'_parser';
            $Parser = new $element_class($this);
        }

        $where = json_decode($where, true);

        self::_addPkgs($this->config);
        
        $criteria = $this->modx->newQuery($classname);
        if (!empty($where)) {
            $criteria->where($where);
        }
        $result_cnt = $this->modx->getCount($classname,$criteria);
        $this->modx->log(modX::LOG_LEVEL_INFO,'Results found: '. $result_cnt);
        
        $results = array();
        $related = array();
        if ($graph) {
            $results = $this->modx->getCollectionGraph($classname,$graph,$criteria);
            $related = json_decode($graph,true);
        }
        else {
            $results = $this->modx->getCollection($classname,$criteria);
        }
        if ($this->get('debug')) {
            $criteria->prepare();
            $out = "----------------------------------\n";
            $out .= "Export Debugging Info\n";
            $out .= "----------------------------------\n\n";
            $out .= "Raw Where filters:\n".print_r($where,true)."\n";
            $out .= "Raw SQL Query:\n";
            $out .= $criteria->toSQL();
            $out .= "\n\nResults found : {$result_cnt}\n\n";
            return $out;
        }
        // Seed data or element?
        if (!$is_element) {

            if (!$target) {
                throw new Exception('Target directory must be specified.');
            }
            elseif(!is_scalar($target)) {
                throw new Exception('Target directory cannot be an array.');
            }
            elseif (preg_match('/[^a-zA-Z0-9_\-]/', $target)) {
                throw new Exception('Name of target directory can contain only letters and numbers.');
            }            
            $dir = $this->get_src_dir($pkg_root_dir).$this->get('seeds_dir').'/'.$this->get('target');
            
            if (!file_exists($dir)) {
                if (false === mkdir($dir, $this->get('dir_mode'), true)) {
                    throw new Exception('Could not create directory '.$dir);
                }
                else {
                    $this->modx->log(modX::LOG_LEVEL_INFO,'Created directory '.$dir);
                }
            }
            elseif (!is_dir($dir)) {
                throw new Exception('Target directory is not a directory: '.$dir);
            }
        }

        if ($results) {
            $i = 1;
            $j = 1;
            $pack = array();
            foreach ($results as $r) {
                if ($is_element) {
                    $Parser->create($pkg_root_dir,$r,$graph);
                }
                else {
                    $array = $r->toArray('',false,false,$graph);
                    $pack[] = $array;
                    // Write to file
                    if (!($i % $limit) || $i == $result_cnt) { 
                        $filename = $dir .'/'.$classname.'.'.$j.'.json';            
                        if (file_exists($filename) && !$this->get('overwrite')) {
                            throw new Exception('Overwrite not permitted '.$filename);
                        }
                        
                        // if (false === file_put_contents($filename, json_encode($pack, JSON_PRETTY_PRINT))) {
                        if (false === file_put_contents($filename, json_encode($pack))) {
                            throw new Exception('Could not write to file '.$filename);
                        }
                        else {
                            $this->modx->log(modX::LOG_LEVEL_INFO,'Created object file at '. $filename);        
                        }
                        $pack = array(); // reset
                        $j++;
                    }
                    $i++;
                }
            }   
        }
        else {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'No matching results found for '.$classname);
        }
        
    }

    /**
     * Compares 2 files for equality.
     *
     * @param string $path1
     * @param string $path2
     * @return boolean true if equal
     */
    public function files_equal($path1, $path2) {
        if (!file_exists($path1)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'File does not exist '.$path1);
            return false;
        }
        if (!file_exists($path2)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'File does not exist '.$path2);
            return false;
        }

        if (filesize($path1) == filesize($path2) && md5_file($path1) == md5_file($path2)) {
            return true;
        }

        $this->modx->log(modX::LOG_LEVEL_DEBUG, 'Files are not equal: '.$path1. ' '.$path2);

        return false;
    }
        
    /**
     * Return an object based on the $objectdata array.
     *
     * Our take-off from xPDO's fromArray() function, but one that can import whatever toArray() 
     * spits out.  It's not a method on the object, however, so we have to do some dancing here
     * to determine whether we are creating a new objects or using existing ones.
     *
     * @param string $classname
     * @param array $objectdata
     * @param boolen $set_pk sets primary keys
     * @param boolean $rawvalues e.g. for modUser, you'd enter the password plaintext and it gets hashed. 
     *      Set to true if you want to store the literal hash.
     * @param integer $breadcrumb_i tracks depth of breadcrumb
     * @return object
     */
    function fromDeepArray($classname, $objectdata, $set_pks=false,$rawvalues=false,$breadcrumb_i=0) {
        $this->modx->log(modX::LOG_LEVEL_DEBUG, 'fromDeepArray begin setting '.$classname. ' (set_pks: '.$set_pks.' rawvalues: '.$rawvalues."):\n".print_r($objectdata,true));
        
        // Find existing object or make a new one
        if ($this->get('is_build')) {
            $Object = $this->modx->newObject($classname);
        }
        else {
            $Object = $this->modx->getObject($classname, $this->get_criteria($classname,$objectdata));
            if (!$Object) {
                $Object = $this->modx->newObject($classname);                
                $this->modx->log(modX::LOG_LEVEL_DEBUG, 'Creating new object for '.$classname);
            }
            else {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, 'Using existing object for '.$classname);
            }
        }
        // The sincere hope is that we can rely on this glorious function...
        $Object->fromArray($objectdata,'',$set_pks,$rawvalues);
        // ...and not this alternative:
        //foreach ($objectdata as $k =>$v) {
        //    $Object->set($k,$v);
        //}

        $related = array_merge($this->modx->getAggregates($classname), $this->modx->getComposites($classname));

        foreach ($related as $alias => $def) {
            // Is there any data provided for related objects?
            if (isset($objectdata[$alias])) {
                $rel_data = $objectdata[$alias];
                $def = $related[$alias];
                
                if (!is_array($rel_data)) {
                    $this->modx->log(modX::LOG_LEVEL_WARN, 'Data in '.$classname.'['.$alias.'] not an array.');
                    continue;
                }

                $this->breadcrumb[$breadcrumb_i] = $alias;

                if ($def['cardinality'] == 'one') {
                    $one = $this->fromDeepArray($def['class'],$rel_data,$set_pks,$rawvalues,$breadcrumb_i+1);
                    $Object->addOne($one);
                }
                else {
                    if (!isset($rel_data[0])) {
                        $rel_data = array($rel_data);
                    }
                    $many = array();
                    foreach ($rel_data as $r) {
                        $many[] = $this->fromDeepArray($def['class'],$r,$set_pks,$rawvalues,$breadcrumb_i+1);   
                    }
                    $Object->addMany($many);
                }
                
            }
        }
        $this->modx->log(modX::LOG_LEVEL_DEBUG, 'fromDeepArray completed setting '.$classname. "\n".print_r($Object->toArray(),true));
        return $Object;
    }    
	/**
	 * Our config getter
	 * @param string $key
	 * @return mixed
	 */
	public function get($key) {
	   return (isset($this->config[$key])) ? $this->config[$key] : null;
	}

    /**
     * When building packages, these attributes govern how objects are updated
     * when the package is installed.  One difficulty here is that one instance 
     * of an object may have many related objects (and thus require deeply nested
     * build attributes), whereas another object instance may have no related objects.
     * So this function traces out all of an object's relations and grows the build
     * attributes accordingly.
     *
     * @param object $Obj
     * @param string $classname
     *
     * @return array 
     */
    public function get_build_attributes($Obj,$classname) {

        $attributes = $this->get('build_attributes');
        if (!isset($attributes[$classname])) {
            throw new Exception('Build attributes not defined for class '.$classname);
        }

        // The attributes for the base
        $out = $attributes[$classname];
        return $out; 
        // BUG: dynamic detection is not working... TODO: fix the wormhole. Let the user specify this manually too.
        // see _check_build_attributes.
        // Any related objects?
/*
        $related = array_merge($this->modx->getAggregates($classname), $this->modx->getComposites($classname));

        foreach ($related as $alias => $def) {            
            if (!empty($Obj->$alias)) {
                // WTF?  Not sure why the Resources alias comes overloaded with info
                // if unchecked, this will bomb out the memory usage
                if ($classname == 'modTemplate' && $alias == 'Resources') {
                    continue;
                }
                if (in_array($alias, array('LexiconEntries'))) {
                    continue;
                }
                $out[xPDOTransport::RELATED_OBJECTS] = true;
                $rel_class = $def['class'];
                if ($def['cardinality'] == 'one') {
                    $relObj = $Obj->getOne($alias);
                }
                else {
                    $relObjs = $Obj->getMany($alias);
                    $relObj = array_shift($relObjs);
                }
                $out[xPDOTransport::RELATED_OBJECT_ATTRIBUTES][$rel_class] = $this->get_build_attributes($relObj,$def['class']);
            }
        }
        return $out;
*/
    }
    
    /**
     * Generate an array that can be passed as filter criteria to getObject so that we 
     * can identify and load existing objects. In practice, we don't always use the primary 
     * key to load an object (because we are defining objects abstractly and the primary key
     * is a feature of the database where it gets installed) so for each classname, we need 
     * a field (or fields) to consider when searching for existing records.  E.g. for 
     * modSnippet or modChunk, we look only at the name, but for modResource we might look 
     * at both context & uri.
     *
     * @param string $classname
     * @param array $attributes data for a single object representing $classname
     * @return array
     */
    public function get_criteria($classname, $attributes) {
        $build_attributes = $this->get('build_attributes');
        if (!isset($build_attributes[$classname][xPDOTransport::UNIQUE_KEY])) {
            throw new Exception('Build attributes xPDOTransport::UNIQUE_KEY not defined for class '.$classname);
        }
        $fields = (array) $build_attributes[$classname][xPDOTransport::UNIQUE_KEY];
        $criteria = array();
        foreach ($fields as $f) {
            if (isset($attributes[$f]) && !empty($attributes[$f])) {
                $criteria[$f] = $attributes[$f];
            }
        }
        return $criteria;
    }
    		
	/**
	 * Get the readme file from a repo
	 *
	 * @param string $pkg_root_dir full path to file, without trailing slash
	 * @return string (contents of README.md file) or false if not found
	 */
	public function get_readme($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);
		foreach ($this->readme_filenames as $f) {
			$readme = $pkg_root_dir.'/'.$f;
			if (file_exists($readme)) {
				return file_get_contents($readme);
			}
		}
		return false;
	}

    /**
     * Get a list of all seed directories
     *
     * @param string $pkg_root_dir path to local package root (w trailing slash)          
     * @return array
     */
    public function get_seed_dirs($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);
        $dirs = array();
        $seeds_dir = $this->get_src_dir($pkg_root_dir).$this->get('seeds_dir');
        if ($seed = $this->get('seed')) {
            if (!is_array($seed)) {
                $seed = explode(',',$seed);
            }
            foreach ($seed as $s) {
                $dirs[] = $seeds_dir.'/'.$s;
            }                
        }
        if (empty($dirs)) {
            $this->modx->log(modX::LOG_LEVEL_INFO, 'No seed directories defined.');
        }
        else {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, 'Seed directories set: '.print_r($dirs,true));
        }
        return $dirs;
    }
    /** 
     * Import pkg elements (Snippets,Chunks,Plugins,Templates) into MODX from the filesystem. 
     * They will be marked as static elements.
     *
     * @param string $pkg_root_dir path to local package root (w trailing slash)     
     */
    public function import($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);
        $this->_prep($pkg_root_dir);
     
        // The gratis Category
        $Category = $this->modx->getObject('modCategory', array('category'=>$this->get('category')));
        if (!$Category) {
            $this->modx->log(modX::LOG_LEVEL_INFO, "Creating new category: ".$this->get('category'));
            $Category = $this->modx->newObject('modCategory');
            $Category->set('category', $this->get('category'));
        }
        else {
            $this->modx->log(modX::LOG_LEVEL_INFO, "Using existing category: ".$this->get('category'));        
        }

        // Import Elements
        $chunks = self::_get_elements('modChunk',$pkg_root_dir);
        $plugins = self::_get_elements('modPlugin',$pkg_root_dir);
        $snippets = self::_get_elements('modSnippet',$pkg_root_dir);
        $templates = self::_get_elements('modTemplate',$pkg_root_dir);
        //$tvs = self::_get_elements('modTemplateVar',$pkg_root_dir);
        
        if ($chunks) $Category->addMany($chunks);
        if ($plugins) $Category->addMany($plugins);
        if ($snippets) $Category->addMany($snippets);
        if ($templates) $Category->addMany($templates);
        //if ($tvs) $Category->addMany($tvs);
        
        if (!$this->get('dry_run') && $Category->save()) {
            $data = $this->get_criteria('modCategory', $Category->toArray());
    		$this->modx->cacheManager->set('modCategory/'.$this->get('category'), $data, 0, self::$cache_opts);
            $this->modx->log(modX::LOG_LEVEL_INFO, "Category created/updated: ".$this->get('category'));
        }
         
        if ($this->get('dry_run')) {
            $msg = "\n==================================\n";
            $msg .= "    Dry Run Enqueued Elements:\n";
            $msg .= "===================================\n";
            foreach (Repoman::$queue as $classname => $list) {
                $msg .= "\n".$classname."\n".str_repeat('-', strlen($classname))."\n"; 
                foreach ($list as $l) {
                    $msg .= "    ".$l."\n";
                }
            }
            $this->modx->log(modX::LOG_LEVEL_INFO, $msg);		
        }
    }

    /**
     * Get the dir containing the goods.  In redundant MODX parlance, this is 
     * usually core/components/<namespace>/  (the default).
     * For better compatibility with composer, this is configurable.
     *
     * @param string $pkg_root_dir
     * @return string dir with trailing slash
     */
    public function get_src_dir($pkg_root_dir) {
        if ($this->get('src_dir')) {
            return $pkg_root_dir . $this->get('src_dir');
        }
        return $pkg_root_dir .'core/components/'.$this->get('namespace').'/';
    }

    /**
     * Get the dir containing the assets.  In redundant MODX parlance, this is 
     * usually assets/components/<namespace>/  (the default).
     * For better compatibility with composer, this is configurable.
     *
     * @param string $pkg_root_dir
     * @return string dir with trailing slash
     */
    public function get_assets_dir($pkg_root_dir) {
        if ($this->get('assets_dir')) {
            return $pkg_root_dir . $this->get('assets_dir');
        }
        return $pkg_root_dir .'assets/components/'.$this->get('namespace').'/';
    }    	
    /**
     * Get the dir containing the assets.  In redundant MODX parlance, this is 
     * usually assets/components/<namespace>/  (the default).
     * For better compatibility with composer, this is configurable.
     *
     * @param string $pkg_root_dir
     * @return string dir with trailing slash
     */
    public function get_docs_dir($pkg_root_dir) {
        if ($this->get('docs_dir')) {
            return $pkg_root_dir . $this->get('docs_dir');
        }
        return $pkg_root_dir.'core/components/'.$this->get('namespace').'/docs/';
    }    	
	        
    /**
     * Install all elements and run migrations
     *
     * @param string $pkg_root_dir path to local package root (w trailing slash)
     */
    public function install($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);
        self::import($pkg_root_dir);
        self::migrate($pkg_root_dir);
        self::seed($pkg_root_dir);
        $this->modx->cacheManager->refresh();
    }

    /** 
     * Given a filename, return the array of records stored in the file.
     *
     * @param string $file (full path)
     * @param boolean $json if true, the file contains json data so it will be decoded
     * @return array
     */
    public function load_data($file, $json=false) {
        if (!file_exists($file)) {
            throw new Exception('Loading data failed. File does not exist: '. $file);
        }

        $this->modx->log(modX::LOG_LEVEL_DEBUG,'Processing object(s) in '.$file . ' (json: '.$json);                                
            
        if ($json) {
            $data = json_decode(file_get_contents($file),true);
        }
        else {
            // check file syntax
            $out = exec(escapeshellcmd("php -l $file"));
            if (preg_match('/^Errors parsing/', $out)) {
                throw new Exception($out);
            }
            $data = include $file;
        }        
        
        if (!is_array($data)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,'Data in '.$file.' not an array.');
            return array();
        }
        if (!isset($data[0])) {
            $data = array($data);
        }
        
        return $data;
    }


    /**
     * Run database migrations: 
     *      - create/remove custom database tables.
     *      - create objects from any seed data
     *
     * @param string $pkg_root_dir path to local package root (w trailing slash)    
     */
    public function migrate($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);
        $this->_prep($pkg_root_dir);
        
        global $modx;
        // For compatibility
        $object = $this->config;
        // TODO: check for modx_transport_packages -- SELECT * FROM modx_transport_packages WHERE package_name = xxx
        // if this has been installed via a package, then skip??
        $migrations_path = $this->get_src_dir($pkg_root_dir).$this->get('migrations_dir');
        
        if (!file_exists($migrations_path) || !is_dir($migrations_path)) {
            $this->modx->log(modX::LOG_LEVEL_INFO, "No migrations detected at ".$migrations_path);
            return;
        }

        if (file_exists($migrations_path.'/uninstall.php')) {
            $this->modx->log(modX::LOG_LEVEL_INFO, "Running migrations uninstall.php");
            include $migrations_path.'/uninstall.php';
        }

        if (file_exists($migrations_path.'/install.php')) {
            $this->modx->log(modX::LOG_LEVEL_INFO, "Running migrations install.php");
            include $migrations_path.'/install.php';
        }
        // Loop over remaining migrations
        $files = glob($migrations_path.'/*.php');
        foreach($files as $f) {
            $base = basename($f);
            if (in_array($base, array('install.php','uninstall.php'))) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, 'Skipping '.$base);
                continue;
            }
            $this->modx->log(modX::LOG_LEVEL_INFO, 'Running migration '.basename($f));
            include $f;
        }
        
    }

    /**
     * Load up seed data into the local modx install. Not used by the build method.
     *
     * @param string $pkg_root_dir path to local package root (w trailing slash)    
     */
    public function seed($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);
        self::_addPkgs($this->config);
        $dirs = $this->get_seed_dirs($pkg_root_dir);
        foreach ($dirs as $d) {
        $objects = $this->crawl_dir($d);
            foreach ($objects as $classname => $info) {
                foreach ($info as $k => $Obj) {
                    if (!$Obj->save()) {
                        $this->modx->log(modX::LOG_LEVEL_ERROR, 'Error saving object '. $classname);
                    }
                    else {
                        $this->modx->log(modX::LOG_LEVEL_DEBUG, 'Saved object '.$classname);
                    }
                }
            }
        }
    }
    
    /**
     * Dev tool for parsing XML schema.  xyz.mysql.schema.xml maps to the model/xyz/ directory.
     *
     * Configuration options:
     *
     *  --action write|parse
     *  --model 
     *  --table_prefix
     *  --overwrite
     */
    public function schema($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);   
        self::_addPkgs($this->config);
        // $this->_prep($pkg_root_dir); // populate the system settings not req'd
        
        $action = strtolower($this->get('action')); // write|parse|both
        $model = trim(strtolower($this->get('model')),'/'); // name of the schema and the subdir
        $table_prefix = $this->get('table_prefix');
        $restrict_prefix = $this->get('restrict_prefix');
        $overwrite = strtolower($this->get('overwrite'));
        $dir_mode = $this->get('dir_mode');

        if ($overwrite && $overwrite!='force') {
            $overwrite = 'polite';
        }
        
        if ($action != 'write' && $action != 'parse') throw new Exception('Invalid action. Action must be either "write" or "parse"');
        if (empty($model)) {
            $model = $this->get->config('namespace');
            $this->modx->log(modX::LOG_LEVEL_INFO, 'Model parameter not set. Falling back to namespace as model name.');
        }
        if (preg_match('/^[^a-z0-9_\-]/i',$model)) {
            throw new Exception('Invalid model. Model name can only contain alphanumeric characters.');
        }
        
        $now = time();
        $schema_file = $this->get_src_dir($pkg_root_dir).'model/schema/'.$model.'.mysql.schema.xml';
        $model_dir = $this->get_src_dir($pkg_root_dir).'model/';
        
        $manager = $this->modx->getManager();
        $generator = $manager->getGenerator();
        
        $renamed_files = array();
        // Generate XML schema by reverse-engineering from existing database tables.
        if ($action == 'write') {
            if (file_exists($schema_file)) {
                if ($overwrite == 'polite') {
                    $schema_file_new = $this->get_src_dir($pkg_root_dir).'model/schema/'.$model.'.'.$now.'.mysql.schema.xml';
                    if (!rename($schema_file, $schema_file_new)) {
                        throw new Exception('Could not rename schema file '.$schema_file);
                    }
                    $renamed_files[$schema_file] = $schema_file_new;
                }
                elseif ($overwrite == 'force') {
                    if (!unlink($schema_file)) {
                        throw new Exception('Could not delete schema file '.$schema_file);
                    }
                    $this->modx->log(modX::LOG_LEVEL_INFO,'Deleted file '.$schema_file);
                }
                else {
                    throw new Exception('Schema already exists: '.$schema_file . ' Refusing to overwrite unless forced.');
                }
            }
            $schema_dir = $model_dir.'schema/';
            $dirs = array($schema_dir);  
            foreach ($dirs as $d) {
                if ( !file_exists($d) ) {
                    if (!mkdir($d, $dir_mode, true) ) {
                        throw new Exception('Could not create directory '.$d);
                    }
                }
                if (!is_writable($d) ) {
                    throw new Exception('Directory is not writeable '.$d);
                }
            }             
            $generator->writeSchema($schema_file, $this->get('namespace'), 'xPDOObject', $table_prefix, $restrict_prefix);  
        }
        // Parse XML schema to create corresponding PHP classes -----------------------------------
        elseif ($action == 'parse') {
            $this->modx->setPackage($this->get('namespace'), $pkg_root_dir, $table_prefix);
            if (!file_exists($schema_file)) throw new Exception('Schema file does not exist: '.$schema_file);
            $class_dir = $model_dir.$model.'/';
            
            if ( !file_exists($class_dir) ) {
                if (!mkdir($class_dir, $dir_mode, true) ) {
                    throw new Exception('Could not create directory '.$class_dir);
                }
                $this->modx->log(modX::LOG_LEVEL_INFO,'Created directory '.$class_dir);
            }
            if (!is_writable($class_dir) ) {
                throw new Exception('Directory is not writeable '.$class_dir);
            }

            $xml = file_get_contents($schema_file);
            if ($xml === false) {
                throw new Exception('Could not read XML schema file: '.$schema_file);
            }
            // Check class files
            preg_match_all('/<object class="(\w+)"/U',$xml,$matches);
            $class_files = array();
            if (isset($matches[1])) {
                foreach ($matches[1] as $f) {
                    $class_file = $class_dir.strtolower($f).'.class.php';
                    $class_files[] = $class_file;
                    if (file_exists($class_file)) {
                        if ($overwrite == 'polite') {
                            $class_file_new = $class_dir.strtolower($f).'.'.$now.'.class.php';
                            if (!rename($class_file, $class_file_new)) {
                                throw new Exception('Could not rename class file '.$class_file);
                            }
                            $renamed_files[$class_file] = $class_file_new;
                            $this->modx->log(modX::LOG_LEVEL_INFO,'Renamed file '.$class_file);
                        }
                        elseif ($overwrite == 'force') {
                            if (!unlink($class_file)) {
                                throw new Exception('Could not delete class file '.$class_file);
                            }
                            $this->modx->log(modX::LOG_LEVEL_INFO,'Deleted file '.$class_file);
                        }
                        else {
                            throw new Exception('Class file exists: '.$class_file.' Refusing to overwrite unless forced.');
                        }
                    }
                }
            }
            // Check for metadata.mysql.php
            $metadata_file = $class_dir.'metadata.mysql.php';
            if (file_exists($metadata_file)) {
                if ($overwrite == 'polite') {
                    $metadata_file_new = $class_dir.'metadata.'.$now.'.mysql.php';
                    if (!rename($metadata_file, $metadata_file_new)) {
                        throw new Exception('Could not rename metadata file  '.$metadata_file);
                    }
                    $renamed_files[$metadata_file] = $metadata_file_new;
                    $this->modx->log(modX::LOG_LEVEL_INFO,'Renamed file '.$metadata_file);
                }
                elseif ($overwrite =='force') {
                    if (!unlink($metadata_file)) {
                        throw new Exception('Could not delete metadata file '.$metadata_file);
                    }
                    $this->modx->log(modX::LOG_LEVEL_INFO,'Deleted metadata file '.$metadata_file);
                }
                else {
                    throw new Exception('metadata.mysql.php file exits. Refusing to overwrite unless forced.');
                }
            }
            // Check mysql files
            $mysql_dir = $class_dir.'mysql';            
            if (file_exists($mysql_dir)) {
                if ($overwrite == 'polite') {
                    if (!rename($mysql_dir, $mysql_dir.'.'.$now)) {
                        throw new Exception('Could not rename mysql directory '.$mysql_dir);
                    }
                    if (!mkdir($mysql_dir, $dir_mode, true) ) {
                        throw new Exception('Could not create directory '.$mysql_dir);
                    }
                    $this->modx->log(modX::LOG_LEVEL_INFO,'Created directory '.$mysql_dir);
                }
                elseif ($overwrite =='force') {
                    if (!$this->rrmdir($mysql_dir)) {
                        throw new Exception('Could not delete mysqld dir '.$mysql_dir);
                    }
                    $this->modx->log(modX::LOG_LEVEL_INFO,'Deleted directory '.$mysql_dir);
                    if (!mkdir($mysql_dir, $dir_mode, true) ) {
                        throw new Exception('Could not create directory '.$mysql_dir);
                    }
                    $this->modx->log(modX::LOG_LEVEL_INFO,'Created directory '.$mysql_dir);
                }
                else {
                    throw new Exception('mysql directory exists: '.$mysql_dir.' Refusing to overwrite unless forced.');
                }
            }
            else {
                if (!mkdir($mysql_dir, $dir_mode, true) ) {
                    throw new Exception('Could not create directory '.$mysql_dir);
                }
                $this->modx->log(modX::LOG_LEVEL_INFO,'Created directory '.$mysql_dir);            
            }
            $generator->parseSchema($schema_file, $model_dir);
        }
    
        // Polite cleanup
        if ($overwrite=='polite') {
                $this->modx->log(modX::LOG_LEVEL_INFO,'Renamed: '.print_r($renamed_files,true));
            foreach ($renamed_files as $old => $new) {
                if (self::files_equal($old, $new)) {
                    if (!unlink($new)) {
                        throw new Exception('Could not delete file '.$new);
                    }
                    $this->modx->log(modX::LOG_LEVEL_INFO,'Cleanup - removing file '.$new);
                }
            }
        }
    
    }


    /**
     * Attempts to uninstall the default namespace, system settings, modx objects,
     * and any database migrations. The behavior is dependent on the MODX cache b/c
     * all new objects are registered in the repoman custom cache partition.
     *
     * No input parameters are required: this looks at the "namespace" config setting.
     *
     * php repoman.php uninstall --namespace=something
     */
    public function uninstall($pkg_root_dir) {
        $pkg_root_dir = self::get_dir($pkg_root_dir);

        // uninstall migrations. Global $modx and $object variables used so the 
        // included files are compat. with the build functionality.
        global $modx;
        $object = $this->config;
        $migrations_path = $this->get_src_dir($pkg_root_dir).$this->get('migrations_dir');

        if (!file_exists($migrations_path) || !is_dir($migrations_path)) {
            $this->modx->log(modX::LOG_LEVEL_INFO, "No migrations detected at ".$migrations_path);
            return;
        }

        if (file_exists($migrations_path.'/uninstall.php')) {
            $this->modx->log(modX::LOG_LEVEL_INFO, "Running migrations/uninstall.php");
            include $migrations_path.'/uninstall.php';
        }
        
        // Remove installed objects
        $cache_dir = MODX_CORE_PATH.'cache/repoman/'.$this->get('namespace');
        if (file_exists($cache_dir) && is_dir($cache_dir)) {
            $obj_dirs = array_diff(scandir($cache_dir), array('..', '.'));

            foreach ($obj_dirs as $objectname_dir) {
                if (!is_dir($cache_dir.'/'.$objectname_dir)) {
                    continue; // wtf? Did you manually edit the cache dirs?
                }

                $objects = array_diff(scandir($cache_dir.'/'.$objectname_dir), array('..', '.'));
                $objecttype = basename($objectname_dir);
                foreach($objects as $o) {
                    $criteria = include $cache_dir.'/'.$objectname_dir.'/'.$o;
                    $Obj = $this->modx->getObject($objecttype, $criteria);
                    if ($Obj) {
                        $Obj->remove();
                    }
                    else {
                        // Some objects are removed b/c of relations before we get to them
                        $this->modx->log(modX::LOG_LEVEL_DEBUG, $objecttype.' could not be located '.print_r($criteria,true));
                    }
                }
            }
            
            Repoman::rrmdir($cache_dir);
        }
        else {
            $this->modx->log(modX::LOG_LEVEL_WARN, 'No cached import data at '.$cache_dir);
        }
    }
	
}
/*EOF*/
