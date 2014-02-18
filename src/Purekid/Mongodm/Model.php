<?php 

namespace Purekid\Mongodm;

use \Purekid\Mongodm\Exception\InvalidDataTypeException;

/**
 * Mongodm - A PHP Mongodb ORM
 *
 * @package  Mongodm
 * @author   Michael Gan <gc1108960@gmail.com>
 * @link     http://github.com/purekid
 */
abstract class Model
{

  const DATA_TYPE_ARRAY      = 'array';

  const DATA_TYPE_BOOL       = 'bool';
  const DATA_TYPE_BOOLEAN    = 'boolean';

  const DATA_TYPE_DATE       = 'date';

  const DATA_TYPE_DBL        = 'dbl';
  const DATA_TYPE_DOUBLE     = 'double';
  const DATA_TYPE_FLT        = 'flt';
  const DATA_TYPE_FLOAT      = 'float';

  const DATA_TYPE_EMBED      = 'embed';
  const DATA_TYPE_EMBEDS     = 'embeds';

  const DATA_TYPE_INT        = 'int';
  const DATA_TYPE_INTEGER    = 'integer';

  const DATA_TYPE_MIXED      = 'mixed';

  const DATA_TYPE_REFERENCE  = 'reference';
  const DATA_TYPE_REFERENCES = 'references';

  const DATA_TYPE_STR        = 'str';
  const DATA_TYPE_STRING     = 'string';

  const DATA_TYPE_TIMESTAMP  = 'timestamp';

  const DATA_TYPE_OBJ        = 'obj';
  const DATA_TYPE_OBJECT     = 'object';

 	private static $driverVersion = \Mongo::VERSION;
 	
	public $cleanData = array();

	/**
	 *  exists in the database
	 */
	public $exists = false;

	/**
	 * section choosen in the config 
	 */
	protected static $config = 'default';

	protected static $attrs = array();
	
	/**
	 * Data modified 
	 */
	protected $dirtyData = array();

    /**
     * Data to unset
     */
    protected $unsetData = array();

	protected $ignoreData = array();

	/**
	* Cache for references data
	*/
	private $_cache = array();

	private $_connection = null;

    /**
     * If $_isEmbed = true , this model can't save to database alone.
     */
    private $_isEmbed = false;

    /**
     * Id for offline model (such as embed model)
     */
    private $_tempId = null;
	
	public function __construct($data = array(), $mapFields = false)
	{
		
		// var_dump('Model.__construct('.json_encode($data).', '.json_encode($mapFields).')');
		
        if($mapFields === true) {
          $data = self::mapFields($data, true);
        }

		if (is_null($this->_connection))
		{
			if(isset($this::$config)){
				$config = $this::$config;
			}else{
				$config = self::config;
			}
			$this->_connection = MongoDB::instance($config);
		}

		if(isset($data)) {
 			$this->inform($data,true);
		}
        if(isset($data['_id']) && $data['_id'] instanceof \MongoId){
            $this->exists = true;
        }else{
            $this->initAttrs();
        }
 		$this->initTypes();
 		$this->__init();
	}

	/**
	 * Inform data by a array
	 * @param array $cleanData
	 * @return boolean
	 */
	public function inform(array $cleanData,$isInit = false)
	{
		if($isInit){
			$attrs = $this->getAttrs();
			foreach($cleanData as $key => $value){
				if(($value instanceof Model) && isset($attrs[$key]) && isset($attrs[$key]['type']) 
			    	&& ( $attrs[$key]['type'] == self::DATA_TYPE_REFERENCE or $attrs[$key]['type'] == self::DATA_TYPE_REFERENCES )){
					$value = $this->setRef($key,$value);
				} 
				$this->cleanData[$key] = $value;
			}
		}else{
			foreach($cleanData as $key => $value){
				$this->$key = $value;
			}
		}
	
		return true;
	}

	/**
	 * Mutate data by direct query
	 * @param array $updateQuery
	 * @return boolean
	 */
	public function mutate($updateQuery, $options = array())
	{
		if(!is_array($updateQuery)) throw new Exception('$updateQuery should be an array');
		if(!is_array($options)) throw new Exception('$options should be an array');

		$default = array(
			'w' => 1
		);
		$options = array_merge($default, $options);

		try {
			$this->_connection->update($this->collectionName(), array('_id' => $this->cleanData['_id']), $updateQuery, $options);
		}
		catch(\MongoCursorException $e) {
			return false;
		}

		return true;
	}
	
	/**
	 * get MongoId of this record
	 *
	 * @return MongoId Object
	 */
	public function getId()
	{
		if(isset($this->cleanData['_id'])){
			return new \MongoId($this->cleanData['_id']);
		}else if(isset($this->_tempId)){
            return $this->_tempId;
        }
		return null;
	}
	
	/**
	 * Delete this record
	 *
	 * @param  array $options
	 * @return boolean
	 */
	public function delete($params = array(), $options = array())
	{
		
		// var_dump('Model.delete('.json_encode($params).', '.json_encode($options).')');
		
		$this->__preDelete();
		
		// Run Bootstrap functions (Validate & Format)
		$_params = \Bootstrap::checkParams(get_called_class(), $params);
		$_data = \Bootstrap::checkData(get_called_class(), (object)array('cleanData' => array(
			'$set' => array(
				'deleted' => 1
			)
		)), 'delete');
		
		// var_dump($_data);
		
		// Security checks for valid query paramaters
		if(isset($_params) && !empty($_params) && isset($_data) && is_object($_data) && isset($_data->cleanData) && is_array($_data->cleanData) && !empty($_data->cleanData)) {
				
			// var_dump('self::connection()->update('.static::$collection.', '.json_encode($_params).', '.json_encode(array('$set' => $_data->cleanData)).')');
				
			$status = self::connection()->update(
				static::$collection,
				$_params,
				$_data->cleanData,
				array('multiple' => false, 'safe' => true)
			);
			
			if(isset($status) && isset($status['n']) && $status['n'] == true) {
				$this->__postDelete();
				return true;
			}	
		}
	
		
		return false;
	}

	/**
	 * Delete this record
	 *
	 * @param  array $options
	 * @return boolean 
	 */
	public function _delete($options = array())
	{
		$this->__preDelete();
		
		if($this->exists){
			$deleted =  $this->_connection->remove($this->collectionName(), array("_id" => $this->getId() ), $options);
			if($deleted){
				$this->exists = false;
			}
		}
		$this->__postDelete();
		return true;
	}

	/**
	 * Save to database
     *
	 * @param array $options
	 * @return array
	 */
	public function save($options = array())
	{

		// var_dump('save('.json_encode($options).')');
		// ($this->cleanData);
		// var_dump($this);
		
        if($this->_isEmbed){
            return false;
        }

        $this->_processReferencesChanged();
        $this->_processEmbedsChanged();

		/* if no changes then do nothing */

		if ($this->exists and empty($this->dirtyData) and empty($this->unsetData)) return true;

		$this->__preSave();
		
		if ($this->exists)
		{
			
			// ('exists');
			
			$this->__preUpdate();
            $updateQuery = array();

            if(!empty($this->dirtyData)){
                $updateQuery['$set'] = self::mapFields($this->dirtyData);
            };

            if(!empty($this->unsetData)){
                $updateQuery['$unset'] = self::mapFields($this->unsetData);
            }

			$success = $this->_connection->update($this->collectionName(), array('_id' => $this->getId()), $updateQuery , $options);
			$this->__postUpdate();
		}
		else
		{
			// var_dump('!exists');
			// var_dump($this);
			// var_dump($this->cleanData);
			
			$this->__preInsert();
            $data = self::mapFields($this->cleanData);
            
            // var_dump(json_encode($data));
            
			$insert = $this->_connection->insert($this->collectionName(), $data, $options);
			$success = !is_null($this->cleanData['_id'] = $insert['_id']);
			if($success){
				$this->exists = true ;
				$this->__postInsert();
			}
			
		}
	
		$this->dirtyData = array();
        $this->unsetData = array();
		$this->__postSave();
		
		return $success;
	}
	
	/**
	 * Insert a record
	 *
	 * @param  array $params
	 * @param  array $fields
	 * @return Model
	 */
	public static function insert($data = array(), $options = array())
	{
		// var_dump('Model.insert('.json_encode($data).', '.json_encode($options).')');

		// Run Bootstrap functions (Validate & Format)
		$_data = \Bootstrap::checkData(get_called_class(), (object)array('cleanData' => $data), 'insert');
		
		// var_dump($_data);
	
		// Security checks for valid query paramaters
		if(isset($_data) && is_object($_data) && isset($_data->cleanData) && is_array($_data->cleanData) && !empty($_data->cleanData)) {
			
			// var_dump('db.'.static::$collection.'.insert('.json_encode($_data->cleanData).', '.json_encode($options).')');
			
			$result =  self::connection()->insert(
				static::$collection, 
				$_data->cleanData,
				$options
			);
			if($result){
				return  Hydrator::hydrate(get_called_class(), $_data->cleanData ,"one");
			} else {
				// var_dump(self::connection()->lastError());
			}
		}
	
		return false;
	}
	
	/**
	 * Update a record
	 *
	 * @param  array $params
	 * @param  array $fields
	 * @return Model
	 */
	public static function update($params = array(), $data = array(), $fields = array(), $options = array())
	{
		// var_dump(static::$collection.'.update('.json_encode($params).', '.json_encode($data).', '.json_encode($fields).', '.json_encode($options).')');
		
		$defaultOptions = array('multi' => false, 'safe' => true);
	
		// Run Bootstrap functions (Validate & Format)
		$_params = \Bootstrap::checkParams(get_called_class(), $params);
		$_data = \Bootstrap::checkData(get_called_class(), (object)array('cleanData' => $data), 'update');
		$_fields = \Bootstrap::checkFields(get_called_class(), $fields);
		
		// Security checks for valid query paramaters
		if(isset($_params) && !empty($_params) && isset($_data) && is_object($_data) && isset($_data->cleanData) && is_array($_data->cleanData) && !empty($_data->cleanData) && isset($_fields) && is_array($_fields) && !empty($_fields)) {
			
			// var_dump('db.'.static::$collection.'.update('.json_encode($_params).', '.json_encode($_data->cleanData).', '.json_encode(count($_fields)).', '.json_encode(array_merge($defaultOptions, $options)).')');
			// var_dump(self::$driverVersion);
			
			if(self::$driverVersion >= '1.3.0' && 1 == 2) {
				$defaultOptions = array('upsert' => false, 'new' => true);
				
				// var_dump('db.'.static::$collection.'.findAndModify('.json_encode($_params).', '.json_encode($_data->cleanData).', '.json_encode($_fields).', '.json_encode(array_merge($defaultOptions, $options)).')');
				
				$result = self::connection()->findAndModify(
					static::$collection,
					$_params,
					$_data->cleanData,
					null, // $_fields, BUG HERE!
					array_merge($defaultOptions, $options)
				);
				
				var_dump($result);
				
				if(isset($result) && $result !== false){
					return  Hydrator::hydrate(get_called_class(), $result ,"one");
				}
	
			} else {
	
				$status = self::connection()->update(
					static::$collection,
					$_params,
					$_data->cleanData,
					array_merge($defaultOptions, $options)
				);
				
				// var_dump($status);
				// var_dump(self::connection()->lastError());
				
				if(isset($status) && isset($status['n']) && $status['n'] == true) {
					return self::one($params, $fields);
				}
			}	
		}
	
		return false;
	}
	
	/**
	 * Export datas to array
	 *
	 * @return array
	 */
	public function toArray($ignore = array('_type'))
	{
        if(!empty($ignore)){
            $ignores = array();
            foreach($ignore as $val){
                $ignores[$val] = 1;
            }
            $ignore = $ignores;
        }

		return array_diff_key($this->cleanData,$ignore);
	}
	
	/**
	 * Determine exist in the database
	 *
	 * @return integer
	 */
	public function exist(){
		return $this->exists;
	}

	/**
	 * Create a Mongodb reference
	 * @return MongoRef Object
	 */
	public function makeRef()
	{
		$model = get_called_class();
		$ref = \MongoDBRef::create($this->collectionName(), $this->getId());
		return $ref;
	}

  /**
   * Cache attrs map
   */
  protected static function cacheAttrsMap() {
    $class = get_called_class();
    $attrs = $class::getAttrs();
    $private =& $class::getPrivateData();

    if(!isset($private['attrsMapCache'])) {
        $private['attrsMapCache'] = array();
    }
    $cache =& $private['attrsMapCache'];

    if(empty($cache)) {
      $cache = array(
        'db' => array(),
        'model' => array()
      );

      foreach($class::getAttrs() as $key => $value) {
        if(isset($value['field'])) {
          $cache['db'][$key] = $value['field'];
          $cache['model'][$value['field']] = $key;
        }
      }
    }
  }

  /**
   * Map fields
   * 
   * @
   */
  public static function mapFields($array, $toModel = false) {
    $class = get_called_class();

    $class::cacheAttrsMap();
    $attrs = $class::getAttrs();
    $private =& $class::getPrivateData();
    $map =& $private['attrsMapCache']['db'];

    if($toModel === true) {
        $map =& $private['attrsMapCache']['model'];
    }

    foreach($map as $from => $to) {
        if(isset($array[$from])) {

            // Map embeds
            $key = $toModel ? $to : $from;
            if(isset($attrs[$key], $attrs[$key]['type'])) {
                if($attrs[$key]['type'] === $class::DATA_TYPE_EMBED) {
                    $array[$from] = call_user_func(array($attrs[$key]['model'], 'mapFields'), $array[$from], $toModel);
                }
                if(is_array($array[$from]) && $attrs[$key]['type'] === $class::DATA_TYPE_EMBEDS) {
                    $embedArray = array();
                    foreach($array[$from] as $item) {
                        $embedArray[] = call_user_func(array($attrs[$key]['model'], 'mapFields'), $item, $toModel);
                    }
                    $array[$from] = $embedArray;
                }
            }

            $array[$to] = $array[$from];
            unset($array[$from]);
        }
    }

    return $array;
  }
	
	/**
	 * Retrieve a record by MongoId
	 * @param mixed $id 
	 * @return Model
	 */
	public static function id($id)
	{
		
		if($id  && strlen($id) == 24 ){
			$id = new \MongoId($id);
		}else{
			return null;
		}
		return self::one( array( "_id" => $id ));
	
	}
	
	/**
	 * Retrieve a record
	 *
	 * @param  array $params
	 * @param  array $fields
	 * @return Model
	 */
	public static function one($params = array(), $fields = array())
	{
		// var_dump(static::$collection.'.one('.json_encode($params).', '.json_encode($fields).')');

		$class = get_called_class();
		$types = $class::getModelTypes();
		if(count($types) > 1){
			// $params['_type'] = $class::get_class_name(false);
		}
		
		// Run Bootstrap functions (Validate & Format)
		$_params = \Bootstrap::checkParams(get_called_class(), $params);
		$_fields = \Bootstrap::checkFields(get_called_class(), $fields);
		
		// Security checks for valid query paramaters
		if(isset($_fields) && is_array($_fields) && !empty($_fields)) {
			
			// var_dump('db.'.static::$collection.'.find_one('.json_encode($_params).', '.json_encode($_fields).')');
			
			$result =  self::connection()->find_one(static::$collection, $_params , $_fields);
			if($result){
				return  Hydrator::hydrate(get_called_class(), $result ,"one");
			}
		}
	
		return null;
	}
	
	/**
	 * Retrieve records
	 *
	 * @param  array $params
	 * @param  array $sort
	 * @param  array $fields
	 * @param  int $limit
	 * @param  int $skip
	 * @return Collection
	 */
	public static function find($params = array(), $sort = array(), $fields = array() , $limit = null , $skip = null)
	{
	
		// var_dump('find('.json_encode($params).', '.json_encode($sort).', '.json_encode(count($fields)).')');
		
		$class = get_called_class();
		$types = $class::getModelTypes();
		if(count($types) > 1){
			// $params['_type'] = $class::get_class_name(false);
		}
		
		// Run Bootstrap functions (Validate & Format)
		$params = \Bootstrap::checkParams(get_called_class(), $params);
		$fields = \Bootstrap::checkFields(get_called_class(), $fields);
		
		// Security checks for valid query paramaters
		if(isset($fields) && is_array($fields) && !empty($fields)) {
			
			// var_dump('db.'.static::$collection.'.find('.json_encode($params).', '.json_encode($fields).')');
			
			$results =  self::connection()->find(static::$collection, $params, $fields);
			
			if(\Check::validReturn($results)) {
				if ( ! is_null($limit))
				{
					$results->limit($limit);
				}
				
				if( !  is_null($skip))
				{
					$results->skip($skip);
				}
				
				if ( ! empty($sort))
				{
					$results->sort($sort);
				}
				
				return Hydrator::hydrate(get_called_class(), $results);
			}
		}
		
		return false;
	}
	
	/**
	 * Retrieve all records
	 *
	 * @param  array $sort
	 * @param  array $fields
	 * @return Collection
	 */
	public static function all( $sort = array() , $fields = array())
	{
		$class = get_called_class();
		$types = $class::getModelTypes();
		$params = array();
		if(count($types) > 1){
			// $params['_type'] = $class::get_class_name(false);
		}
		
		return self::find($params,$fields,$sort);
	
	}

    public static function group(array $keys, array $query, $initial = null,$reduce = null)
	{

        if(!$reduce) $reduce = new \MongoCode( 'function(doc, out){ out.object = doc }');
        if(!$initial) $initial = array('object'=>0);
        return self::connection()->group(self::collectionName(),$keys,$initial,$reduce,array('condition'=>$query));

    }

    public static function aggregate($query){

        $rows =  self::connection()->aggregate(self::collectionName(),$query);
        return $rows;

    }

	/**
	 * Count of records
	 *
	 * @param  $params
	 * @return integer
	 */
	public static function count($params = array())
	{
		$class = get_called_class();
		$types = $class::getModelTypes();
		if(count($types) > 1){
			// $params['_type'] = $class::get_class_name(false);
		}
		$count = self::connection()->count(self::collectionName(),$params);
		return $count;
	
	}
	
	/**
	 * Get collection name
	 *
	 * @return string
	 */
	public static function collectionName()
	{
		$class = get_called_class();
		$collection = $class::$collection;
		return $collection;
	}
	
	/**
	 * Drop the collection
	 *
	 * @return boolean
	 */
	public static function drop(){
	
		$class = get_called_class();
		return self::connection()->drop_collection($class::collectionName());
	
	}
	
	/**
	 * Retrieve a record by MongoRef
     *
	 * @param mixed $ref
	 * @return Model 
	 */
	public static function ref( $ref ){
		if(isset($ref['$id'])){
			if($ref['$ref'] == self::collectionName()){
				return self::id($ref['$id']);
			}
		}
		return null;
	}
	
	/**
	 * Returns the name of a class using get_class with the namespaces stripped.
     *
	 * @param boolean $with_namespaces
	 * @return  string  Name of class with namespaces stripped
	 */
	public static function get_class_name($with_namespaces = true)
	{
		$class_name = get_called_class();
		if($with_namespaces) return $class_name;
		$class = explode('\\',  $class_name);
		return $class[count($class) - 1];
	}


    public function setIsEmbed($is_embed){

        $this->_isEmbed = $is_embed;
        if($is_embed){
            unset($this->_connection);
            unset($this->exists);
        }

    }

    public function getIsEmbed(){

        return $this->_isEmbed ;

    }

    public function setTempId( $tempId ){
        $this->_tempId = $tempId;
    }

	/**
	 * Ensure index 
	 * @param mixed $keys
	 * @param array $options
	 * @return  boolean 
	 */
	public static function ensure_index (  $keys, $options = array())
	{
		$result =  self::connection()->ensure_index(self::collectionName(),$keys,$options);
		return $result; 
	}

    /**
     * Initialize the "_type" attribute for the model
     */
    private function initTypes(){

        $class = $this->get_class_name(false);
        $types = $this->getModelTypes();
        $type = $this->_type;
        if(!$type || !is_array($type)){
            $this->_type = $types;
        }else if(!in_array($class,$type)){
            $type[] = $class;
            $this->_type = $type;
        }

    }

	/**
	 * Get Mongodb connection instance
	 * @return Mongodb
	 */
	private static function connection()
	{
		$class = get_called_class();
		$config = $class::$config;
		return MongoDB::instance($config);
	}
	
	/**
	 * Get current database name
	 * @return string
	 */
	private function dbName()
	{
	
		$dbName = "default";
		$config = $this::$config;
		$configs = MongoDB::config($config);
		if($configs){
			$dbName = $configs['connection']['database'];
		}
	
		return $dbName;
	}
	
    /**
	 * Parse value with specific definition in $attrs
     *
	 * @param string $key
	 * @param mixed $value
	 * @return mixed
	 */
	public function parseValue($key,$value){
		
		$attrs = $this->getAttrs();
		if(!isset($attrs[$key]) && is_object($value)){
			if(method_exists($value, 'toArray')){
				$value = (array) $value->toArray();
			}
      else if(method_exists($value, 'to_array')){
				$value = (array) $value->to_array();
			}
		}
		else if(isset($attrs[$key]) && isset($attrs[$key]['type'])) {
			switch($attrs[$key]['type']) {
        case self::DATA_TYPE_INT:
				case self::DATA_TYPE_INTEGER:
					$value = (integer) $value;
				break;
        case self::DATA_TYPE_STR:
				case self::DATA_TYPE_STRING:
					$value = (string) $value;
					break;
        case self::DATA_TYPE_FLT:
        case self::DATA_TYPE_FLOAT:
        case self::DATA_TYPE_DBL;
				case self::DATA_TYPE_DOUBLE:
					$value = (float) $value;
					break;
				case self::DATA_TYPE_TIMESTAMP:
					if(! ($value instanceof \MongoTimestamp)){
            try {
						  $value = new \MongoTimestamp($value);
            }
            catch(\Exception $e) {
              throw new InvalidDataTypeException('$key cannot be parsed by \MongoTimestamp', $e->getCode(), $e);
            }
					}
					break;
        case self::DATA_TYPE_DATE:
          if(! ($value instanceof \MongoDate)) {
            try {
              if(!$value instanceof \MongoDate) {
                if(is_numeric($value)) {
                  $value = '@'.$value;
                }
                if(!$value instanceof \DateTime) {
                  $value = new \DateTime($value);
                }
                $value = new \MongoDate($value->getTimestamp());
              }
            }
            catch(\Exception $e) {
              throw new InvalidDataTypeException('$key cannot be parsed by \DateTime', $e->getCode(), $e);
            }
          }
          break;
        case self::DATA_TYPE_BOOL:
				case self::DATA_TYPE_BOOLEAN:
					$value = (boolean) $value;
					break;
        case self::DATA_TYPE_OBJ:
				case self::DATA_TYPE_OBJECT:
					if(!empty($value) && !is_array($value) && !is_object($value)){
						throw new InvalidDataTypeException("[$key] is not an object");
					}
					$value = (object) $value;
					break;
				case self::DATA_TYPE_ARRAY:
					if(!empty($value) && !is_array($value)){
						throw new InvalidDataTypeException("[$key] is not an array");
					}
					$value = (array) $value;
					break;
        case self::DATA_TYPE_EMBED:
        case self::DATA_TYPE_EMBEDS:
        case self::DATA_TYPE_MIXED:
        case self::DATA_TYPE_REFERENCE:
        case self::DATA_TYPE_REFERENCES:
          break;
				default:
          throw new InvalidDataTypeException("{$attrs[$key]['type']} is not a valid type");
					break;
			}
		}
		return $value;
	}

    /**
     *  Update the 'references' attribute for model's instance when that 'references' data  has changed.
     */
    private function _processReferencesChanged(){

        $cache = $this->_cache;
        $attrs = $this->getAttrs();
        foreach($cache as $key => $item){
            if(!isset($attrs[$key])) continue;
            $attr = $attrs[$key];
            if($attr['type'] == self::DATA_TYPE_REFERENCES){
                if( $item instanceof Collection && $this->cleanData[$key] !== $item->makeRef()){
                    $this->__setter($key,$item);
                }
            }
        }

    }

    /**
     *  Update the 'embeds' attribute for model's instance when that 'embeds' data  has changed.
     */
    private function _processEmbedsChanged(){

        $cache = $this->_cache;
        $attrs = $this->getAttrs();
        foreach($cache as $key => $item){
            if(!isset($attrs[$key])) continue;
            $attr = $attrs[$key];
            if($attr['type'] == self::DATA_TYPE_EMBED){
                if( $item instanceof Model && $this->cleanData[$key] !== $item->toArray()){
                    $this->__setter($key,$item);
                }
            }else if($attr['type'] == self::DATA_TYPE_EMBEDS){
                if( $item instanceof Collection && $this->cleanData[$key] !== $item->toEmbedsArray()){
                    $this->__setter($key,$item);
                }
            }
        }

    }

	/**
	 *  If the attribute of $key is a reference ,
	 *  save the attribute into database as MongoDBRef
	 */
	private function setRef($key,$value)
	{
		$attrs = $this->getAttrs();
		$cache = &$this->_cache;
		$reference = $attrs[$key];
		$model = $reference['model'];
		$type = $reference['type'];

		if($type == self::DATA_TYPE_REFERENCE){
			$model = $reference['model'];
			$type = $reference['type'];
			if($value instanceof $model){
				$ref = $value->makeRef();
				$return = $ref;
			}else if($value == null){
				$return = null;
			}else{
				throw new \Exception ("{$key} is not instance of '$model'");
			}
				
		}else if($type == self::DATA_TYPE_REFERENCES){
			$arr = array();
			if(is_array($value)){
				foreach($value as $item){
					if(! ( $item instanceof Model ) ) continue;
					$arr[] = $item->makeRef();
				}
				$return = $arr;
				$value = Collection::make($value);
			}else if($value instanceof Collection){
				$return = $value->makeRef();
			}else if($value == null){
				$return = null;
			}else{
				throw new \Exception ("{$key} is not instance of '$model'");
			}
		}
	
		$cache[$key] = $value;
		return $return;
	}

    /**
     *  Tthe attribute of $key is a Embed
     *
     */
    private function setEmbed($key,$value)
    {
        $attrs = $this->getAttrs();
        $cache = &$this->_cache;
        $embed = $attrs[$key];
        $model = $embed['model'];
        $type = $embed['type'];

        if($type == self::DATA_TYPE_EMBED){
            $model = $embed['model'];
            $type = $embed['type'];
            if($value instanceof $model){
                $value->setIsEmbed(true);
                $return  = $value->toArray(array('_type','_id'));
            }else if($value == null){
                $return = null;
            }else{
                throw new \Exception ("{$key} is not instance of '$model'");
            }

        }else if($type == self::DATA_TYPE_EMBEDS){
            $arr = array();
            if(is_array($value)){
                foreach($value as $item){
                    if(! ( $item instanceof Model ) ) continue;
                    $item->setIsEmbed(true);
                    $arr[] = $item;
                }
                $value = Collection::make($arr);
            }

            if($value instanceof Collection){
                $return = $value->toEmbedsArray();
            }else if($value == null){
                $return = null;
            }else{
                throw new \Exception ("{$key} is not instance of '$model'");
            }
        }

        $cache[$key] = $value;

        return $return;
    }

    private function loadEmbed($key)
    {
        $attrs = $this->getAttrs();
        $embed = $attrs[$key];
        $cache = &$this->_cache;

        if(isset($this->cleanData[$key])){
            $value = $this->cleanData[$key];
        }else{
            $value = null;
        }

        $model = $embed['model'];
        $type = $embed['type'];
        if( isset($cache[$key]) ){
            $obj = &$cache[$key];
            return $obj;
        }else{
            if(class_exists($model)){
                if($type == self::DATA_TYPE_EMBED){
                    if($value){
                        $data = $value;
                        $object = new $model($data);
                        $object->setIsEmbed(true);
                        $cache[$key] = $object;
                        return $object;
                    }
                    return null;
                }else if($type == self::DATA_TYPE_EMBEDS){
                    $res = array();
                    if(!empty($value)){
                        foreach($value as $item){
                            $data = $item;
                            $record = new $model($data);
                            $record->setIsEmbed(true);
                            if($record){
                                $res[] = $record;
                            }
                        }
                    }
                    $set =  Collection::make($res);
                    $cache[$key] = $set;
                    $obj = &$cache[$key];
                    return $obj;
                }
            }
        }
    }

	/**
	 *  If the attribute of $key is a reference ,
	 *  load its original record from db and save to $_cache temporarily.
	 */
	private function loadRef($key)
	{
		$attrs = $this->getAttrs();
		$reference = $attrs[$key];
		$cache = &$this->_cache;
		if(isset($this->cleanData[$key])){
			$value = $this->cleanData[$key];
		}else{
			$value = null;
		}
	
		$model = $reference['model'];
		$type = $reference['type'];
		if( isset($cache[$key]) ){
            $obj = &$cache[$key];
			return $obj;
		}else{
			if(class_exists($model)){
				if($type == self::DATA_TYPE_REFERENCE){
					if(\MongoDBRef::isRef($value)){
						$object = $model::id($value['$id']);
						$cache[$key] = $object;
						return $object;
					}
					return null;
				}else if($type == self::DATA_TYPE_REFERENCES){
					$res = array();
					if(!empty($value)){
						foreach($value as $item){
							$record = $model::id($item['$id']);
							if($record){
								$res[] = $record;
							}
						}
					}
					$set =  Collection::make($res);
					$cache[$key] = $set;
                    $obj = &$cache[$key];
                    return $obj;
				}
			}
		}
	}

    /**
     * unset a attribute
     * @deprecated see __unset magic method and __unsetter
     * @param $key
     */
    private function _unset( $key ) {
      $this->__unset($key);
    }

    /**
     * Initialize attributes with default value
     */
    protected function initAttrs(){
        $attrs = self::getAttrs();
        foreach($attrs as $key => $attr){
            if(! isset($attr['default'])) continue;
            if( !isset($this->cleanData[$key])){
                $this->$key = $attr['default'];
            }
        }
    }

    /**
     * Get all defined attributes in $attrs ( extended by parent class )
     * @return array
     */
    public static function getAttrs(){

        $class = get_called_class();
        $parent = get_parent_class($class);
        if($parent){
            $attrs_parent = $parent::getAttrs();
            $attrs = array_merge($attrs_parent,$class::$attrs);
        }else{
            $attrs = $class::$attrs;
        }
        if(empty($attrs)) $attrs = array();
        return array_diff_key($attrs, array('__private__'));

    }

    /**
     * Get private data
     * @return array
     */
    protected static function &getPrivateData() {
        $class = get_called_class();
        if(!isset($class::$attrs['__private__'])) {
            $class::$attrs['__private__'] = array();
        }
        return $class::$attrs['__private__'];
    }

    /**
     * Get types of model,type is the class_name without namespace of Model
     * @return array
     */
    protected static function getModelTypes(){

        $class = get_called_class();
        $class_name = $class::get_class_name(false);
        $parent = get_parent_class($class);
        if($parent){
            $names_parent = $parent::getModelTypes();
            $names = array_merge($names_parent,array($class_name));
        }else{
            $names = array();
        }
        return $names;

    }

	/*********** Hooks ***********/
	
	protected function __init()
	{
		return true;
	}
	
	protected function __preSave()
	{
		return true;
	}
	
	protected function __preUpdate()
	{
		return true;
	}
	
	protected function __preInsert()
	{
		return true;
	}
	
	protected function __preDelete()
	{
		return true;
	}
	
	protected function __postSave()
	{
		return true;
	}
	
	protected function __postUpdate()
	{
		return true;
	}
	
	protected function __postInsert()
	{
		return true;
	}
	
	protected function __postDelete()
	{
		return true;
	}
	
	/*********** Magic methods ************/

  /**
   * Interface for __get magic method
   * 
   * @param string $key
   * @return mixed
   */
  public function __getter($key)
  {
    $attrs = $this->getAttrs();
    $value = NULL;
    if(isset($attrs[$key], $attrs[$key]['type'])) {
      if(in_array($attrs[$key]['type'], array(self::DATA_TYPE_REFERENCE, self::DATA_TYPE_REFERENCES))) {
        return $this->loadRef($key);
      }
      else if (in_array($attrs[$key]['type'], array(self::DATA_TYPE_EMBED, self::DATA_TYPE_EMBEDS))) {
        return $this->loadEmbed($key);
      }
    }

    if(isset($this->cleanData[$key])) {
      $value = $this->parseValue($key,$this->cleanData[$key]);
      return $value;
    }
    else if(isset($key, $this->ignoreData[$key])) {
      return $this->ignoreData[$key];
    }
  
  }

	/**
	 * @param string $key
	 * @return mixed
	 */
	public function __get($key)
	{
    $value = $this->__getter($key);

    if(method_exists($this, 'get'.ucfirst($key))) {
      return call_user_func(array($this, 'get'.ucfirst($key)), $value);
    }

    return $value;
	}
	
	/**
	 * Interface for __set magic method
   *
   * @param string $key
   * @param mixed $value 
	 */
	public function __setter($key, $value)
	{
		$attrs = $this->getAttrs();

		if(isset($attrs[$key]) && isset($attrs[$key]['type']) ){
      if(in_array($attrs[$key]['type'], array(self::DATA_TYPE_REFERENCE, self::DATA_TYPE_REFERENCES))) {
        $value = $this->setRef($key,$value);
      }
      else if (in_array($attrs[$key]['type'], array(self::DATA_TYPE_EMBED, self::DATA_TYPE_EMBEDS))) {
        $value = $this->setEmbed($key,$value);
      }
		}
		
		$value = $this->parseValue($key,$value);

		if(!isset($this->cleanData[$key]) || $this->cleanData[$key] !== $value) {
			$this->cleanData[$key] = $value;
			$this->dirtyData[$key] = $value;
		}
	}

  /**
   * @param string $key
   * @param mixed $value 
   */
  public function __set($key, $value)
  {
    if(method_exists($this, 'set'.ucfirst($key))) {
      return call_user_func(array($this, 'set'.ucfirst($key)), $value);
    }
    else {
      $this->__setter($key, $value);
    }
  }
  
  /**
   * Interface for __unset magic method
   *
   * @param string $key
   */
  public function __unsetter($key)
  {
    $attrs = $this->getAttrs();
    if(strpos($key, ".") !== false) {
      throw new \Exception('The key to unset can\'t contain a "." ');
    }

    if(isset($this->cleanData[$key])) {
      unset($this->cleanData[$key]);
    }

    if(isset($this->dirtyData[$key])) {
      unset($this->dirtyData[$key]);
    }

    $this->unsetData[$key] = 1;
  }

  /**
   * @param string $key
   * @param mixed $value 
   */
  public function __unset($key)
  {
    if(is_array($key)) {
      foreach($key as $item) {
        $this->__unset($item);
      }
    }
    else {
      if(method_exists($this, 'unset'.ucfirst($key))) {
        return call_user_func(array($this, 'unset'.ucfirst($key)));
      }
      else {
        $this->__unsetter($key);
      }
    }
  }

  /**
   * 
   * @param string $key
   */
  public function __isset($key)
  {
    return isset($this->cleanData[$key])
        || isset($this->dirtyData[$key])
        || isset($this->ignoreData[$key]);
  }


  /**
   * @param string $func
   * @param mixed $args
   */
  public function __call($func, $args){
    if($func == 'unset' && isset($args[0])) {
      $this->__unset($args[0]);
    }

    if(strpos($func, 'get') === 0 && strlen($func) > 3) {
      $key = strtolower(substr($func, 3));
      if(method_exists($this, $func)) {
        return call_user_func(array($this, $func));
      }

      return $this->__get($key);
    }

    if(strpos($func, 'set') === 0 && strlen($func) > 3) {
      $key = strtolower(substr($func, 3));
      if(method_exists($this, $func)) {
        return call_user_func(array($this, $func), isset($args[0]) ? $args[0] : null);
      }

      return $this->__set($key, isset($args[0]) ? $args[0] : null);
    }
  }

}
