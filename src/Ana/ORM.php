<?php

namespace Ana;

use \Cabinet\DBAL\Db;

abstract class ORM 
{
    const SELECT = 'select';
    const DELETE = 'delete';
    const UPDATE = 'update';

    /**
     * Global connection used by models.
     * @var \Cabinet\DBAL\Db
     */
    public static $connection;
    
    /**
     * Models definitions.
     * @var array
     */
    protected static $models = array();

    /**
     * Name of the object.
     * @var string
     */
    protected $objectName;

    /**
     * Relationships that should always be joined
     * @var array
     */
    protected $loadWith = array();

    /**
     * Validation object created before saving/updating
     * @var Validation
     */
    protected $validation = null;

    /**
     * Current object
     * @var array
     */
    protected $object = array();

    /**
     * @var array
     */
    protected $changed = array();

    /**
     * @var array
     */
    protected $originalValues = array();

    /**
     * @var array
     */
    protected $related = array();

    /**
     * @var bool
     */
    protected $valid = false;

    /**
     * @var bool
     */
    protected $loaded = false;

    /**
     * @var bool
     */
    protected $saved = false;

    /**
     * @var array
     */
    protected $sorting;

    /**
     * Primary key value
     * @var mixed
     */
    protected $primaryKeyValue;

    /**
     * Model configuration, reload on wakeup?
     * @var bool
     */
    protected $reloadOnWakeup = true;

    /**
     * Database Object
     * @var \Cabinet\DBAL\Db
     */
    protected $db = null;

    /**
     * Database methods applied
     * @var array
     */
    protected $dbApplied = array();

    /**
     * Database methods pending
     * @var array
     */
    protected $dbPending = array();

    /**
     * Reset builder
     * @var bool
     */
    protected $dbReset = true;

    /**
     * Database query builder
     * @var Database_Query_Builder_Select
     */
    protected $dbBuilder;

    /**
     * With calls already applied
     * @var array
     */
    protected $withApplied = array();

    /**
     * Data to be loaded into the model from a database call cast
     * @var array
     */
    protected $castData = array();

    public static function define($class, $config)
    {
        $modelName = strtolower(str_replace('\\', '-', ltrim($class, '\\')));

        $defaults = array(
            /**
             * Class name
             * @var string
             */
            'class'             => $class,
            /**
             * Model name used in queries
             * @var string
             */
            'modelName'         => $modelName,
            /**
             * Database table name
             * @var string
             */
            'tableName'         => '',
            /**
             * Table columns
             * @var array
             */
            'tableColumns'      => array(),
            /**
             * Table name
             * @var string
             */
            'primaryKey'        => 'id',
            /**
             * The message filename used for validation errors.
             * Defaults to ORM::$_object_name
             * @var string
             */
            'errorsFilename'    => $modelName,
            /**
             * Foreign key suffix
             * @var string
             */
            'foreignKeySuffix'  => '_key',
            /**
             * "Belongs to" relationships
             * @var array
             */
            'belongsTo'         => array(),
            /**
             * "Has one" relationships
             * @var array
             */
            'hasOne'            => array(),
            /**
             * "Has many" relationships
             * @var array
             */
            'hasMany'           => array()
        );

        $config = array_merge($defaults, $config);

        $methods = array('save', 'load', 'get', 'set', 'update', 'create');
        $columns = array();
        foreach ($config['tableColumns'] as $name => $attr) {
            $attrs = array_merge(array_fill_keys($methods, false), array('type' => 'string'));

            if (is_string($attr)) {
                $attrs['type'] = $attr;
            }

            foreach ($methods as $method) {
                $default = 'on'.ucfirst($method).ucfirst($name);

                if ( ! $attrs[$method] and method_exists($class, $default)) {
                    $attrs[$method] = $default;
                }
            }

            $columns[$name] = $attrs;
        }

        $config['tableColumns'] = $columns;

        foreach ($config['belongsTo'] as $alias => $details)
        {
            if ( ! isset($details['foreignKey'])) {
                $config['belongsTo'][$alias] = $alias.$config['foreignKeySuffix'];
            }
        }

        foreach ($config['hasOne'] as $alias => $details)
        {
            if ( ! isset($details['foreignKey'])) {
                $config['hasOne'][$alias] = $objectName.$config['foreignKeySuffix'];
            }
        }

        foreach ($config['hasMany'] as $alias => $details)
        {
            if ( ! isset($details['foreignKey'])) {
                $config['hasMany'][$alias] = $objectName.$config['foreignKeySuffix'];
            }

            if ( ! isset($details['through'])) {
                $config['hasMany'][$alias] = null;
            }
        }

        ORM::$models[$modelName] = $config;
    }

    /**
     * Return class model name.
     *
     *     \Model\User::modelName(); // model-user
     *     \Model\User_data::modelName(); // model-user_data
     * 
     * @return string
     */
    public static function modelName()
    {
        return strtolower(str_replace('\\', '-', get_called_class()));
    }

    /**
     * Return data model.
     *
     *     \Model\User::info('tableName'); // users
     *     \Ana\ORM::info('model-user', 'tableName'); // users
     *     \Model\User::info(); // all
     * 
     * @return mixed
     */
    public static function info($objectName = null, $key = null)
    {
        if ($key === null and $objectName === null) {
            return ORM::$models[static::modelName()];
        }

        if ($key === null and $objectName !== null) {
            return ORM::$models[static::modelName()][$objectName];
        }

        return ORM::$models[$objectName][$key];
    }

    /**
     * Factory method
     *
     *     ORM::factory('model-user', 3);
     *     \Model\User::factory();
     *     \Model\User::factory(3);
     * 
     * @param  string $modelName Model name.
     * @param  int $id           Id
     * @return \Ana\ORM
     */
    public static function factory($modelName = null, $id = null)
    {
        if (is_int($modelName)) {
            $id = $modelName;
            $modelName = null;
        }

        $modelName = $modelName ?: static::modelName();

        return new ORM::$models[$modelName]['class']($id);
    }

    /**
     * Constructs a new model and loads a record if given
     *
     * @param mixed $id Parameter for find or object to load
     * @param \Cabinet\DBAL\Connection $db Database connection object.
     */
    public function __construct($id = null, \Cabinet\DBAL\Connection $db = null)
    {
        $this->objectName = static::modelName();

        // Not standard database connection
        $this->db = $db != null ? $db : ORM::$connection;

        $this->clear();

        if ($id !== null)
        {
            if (is_array($id))
            {
                foreach ($id as $column => $value)
                {
                    // Passing an array of column => values
                    $this->where($column, '=', $value);
                }

                $this->find();
            }
            else
            {
                $pk = static::$models[$this->objectName]['primaryKey'];

                // Passing the primary key
                $this->where($this->objectName.'.'.$pk, '=', $id)->find();
            }
        }
        elseif ( ! empty($this->castData))
        {
            // Load preloaded data from a database call cast
            $this->loadValues($this->castData);

            $this->castData = array();
        }
    }

    /**
     * Initializes validation rules, and labels
     *
     * @return void
     */
    protected function createValidation()
    {
        // Build the validation object with its rules
        $this->validation = \Ana\Validation::factory($this->object)
            ->bind(':model', $this)
            ->bind(':originalValues', $this->originalValues)
            ->bind(':changed', $this->changed);

        foreach ($this->getValidationRules() as $field => $rules)
        {
            // Validate only changed fields (on update)
            if ( ! $this->loaded or ($this->loaded and isset($this->changed[$field])) ) {
                $this->validation->rules($field, $rules);
            }
        }

        // Use column names by default for labels
        $columns = array_keys($this('tableColumns'));

        // Merge user-defined labels
        $labels = array_merge(array_combine($columns, $columns), $this->labels());

        foreach ($labels as $field => $label)
        {
            $this->validation->label($field, $label);
        }
    }

    /**
     * Unloads the current object and clears the status.
     *
     * @chainable
     * @return ORM
     */
    public function clear()
    {
        $model = ORM::$models[$this->objectName];

        // Create an array with all the columns set to null
        $values = array_combine(array_keys($model['tableColumns']), array_fill(0, count($model['tableColumns']), null));

        // Replace the object and reset the object status
        $this->object = $this->changed = $this->related = $this->originalValues 
            = array();

        // Replace the current object with an empty one
        $this->loadValues($values);

        // Reset primary key
        $this->primaryKeyValue = null;
        
        // Reset the loaded state
        $this->loaded = false;

        $this->reset();

        return $this;
    }

    /**
     * Reloads the current object from the database.
     *
     * @chainable
     * @return ORM
     */
    public function reload()
    {
        $pk = $this->pk();

        // Replace the object and reset the object status
        $this->object = $this->changed = $this->related 
            = $this->originalValues = array();

        // Only reload the object if we have one to reload
        if ($this->loaded) {
            return 
                $this
                    ->clear()
                    ->where($this->objectName.'.'.$this('primaryKey'), 
                        '=', $pk)
                    ->find();
        } else {
            return $this->clear();
        }
    }

    /**
     * Checks if object data is set.
     *
     * @param  string $column Column name
     * @return boolean
     */
    public function __isset($column)
    {
        $data = ORM::$models[$this->objectName];

        return (isset($this->object[$column]) or
            isset($this->related[$column]) or
            isset($data['hasOne'][$column]) or
            isset($data['belongsTo'][$column]) or
            isset($data['hasMany'][$column]));
    }

    /**
     * Unsets object data.
     *
     * @param  string $column Column name
     * @return void
     */
    public function __unset($column)
    {
        unset($this->object[$column], $this->changed[$column], $this->related[$column]);
    }

    /**
     * Allows serialization of only the object data and state, to prevent
     * "stale" objects being unserialized, which also requires less memory.
     *
     * @return string
     */
    public function serialize()
    {
        // Store only information about the object
        foreach (array('primaryKeyValue', 'object', 'changed', 'loaded', 
            'saved', 'sorting', 'originalValues') as $var)
        {
            $data[$var] = $this->{$var};
        }

        return serialize($data);
    }

    /**
     * Prepares the database connection and reloads the object.
     *
     * @param string $data String for unserialization
     * @return  void
     */
    public function unserialize($data)
    {
        // Initialize model
        $this->clear();

        foreach (unserialize($data) as $name => $var)
        {
            $this->{$name} = $var;
        }

        if ($this->reloadOnWakeup === true)
        {
            // Reload the object
            $this->reload();
        }
    }

    public function hasChange($field = null)
    {
        if ($field === null) {
            return (bool) $this->changed;
        } else if (isset($this->changed[$field])) {
            return $this->changed[$field] !== null;
        }

        return false;
    }

    /**
     * Check whether the model data has been modified.
     * If $field is specified, checks whether that field was modified.
     *
     * @param string  $field  field to check for changes
     * @return  bool  Whether or not the field has changed
     */
    public function getChanged($field = null)
    {
        if ($field === null) {
            return $this->changed;
        } else if (isset($this->changed[$field])) {
            return $this->changed[$field];
        }

        return false;
    }

    /**
     * Handles retrieval of all model values, relationships, and metadata.
     * [!!] This should not be overridden.
     *
     * @param   string $column Column name
     * @return  mixed
     */
    public function __get($column)
    {
        return $this->get($column);
    }
    
    /**
     * Handles getting of column
     * Override this method to add custom get behavior
     *
     * @param   string $column Column name
     * @throws Kohana_Exception
     * @return mixed
     */
    public function get($column)
    {
        $info = static::$models[$this->objectName];

        if (array_key_exists($column, $this->object))
        {
            $value = $this->object[$column];

            $cols = ORM::$models[$this->objectName]['tableColumns'];
            
            if ($cols[$column]['get']) {
                $value = $this->$cols[$column]['get']($value);
            }

            return $value;
        }
        elseif (isset($this->related[$column]))
        {
            // Return related model that has already been fetched
            return $this->getRelated[$column];
        }
        elseif (isset($info['belongsTo'][$column]))
        {
            $belongsTo = $info['belongsTo'][$column];
            $model = $this->getRelated($column);

            // Use this model's column and foreign model's primary key
            $col = $model->objectName.'.'.ORM::$models[$model->objectName]['primaryKey'];
            $val = $this->object[$belongsTo['foreignKey']];

            // Make sure we don't run WHERE "AUTO_INCREMENT column" = null queries. This would
            // return the last inserted record instead of an empty result.
            // See: http://mysql.localhost.net.ar/doc/refman/5.1/en/server-session-variables.html#sysvar_sql_auto_is_null
            if ($val !== null)
            {
                $model->where($col, $val)->find();
            }

            return $this->related[$column] = $model;
        }
        elseif (isset($info['hasOne'][$column]))
        {
            $hasOne = $info['hasOne'][$column];            
            $model = $this->getRelated($column);

            // Use this model's primary key value and foreign model's column
            $col = $model->objectName.'.'.$hasOne['foreignKey'];
            $val = $this->pk();

            $model->where($col, $val)->find();

            return $this->related[$column] = $model;
        }
        elseif (isset($info['hasMany'][$column]))
        {
            $hasMany = $info['hasMany'][$column];

            $model = new $hasMany['model'];

            if (isset($hasMany['through']))
            {
                // Grab has_many "through" relationship table
                $through = $hasMany['through'];

                // Join on through model's target foreign key (far_key) and target model's primary key
                $join_col1 = $through.'.'.$hasMany['farKey'];
                $join_col2 = $model->getModelName().'.'.$model('primaryKey');

                $model->join($through)->on($join_col1, '=', $join_col2);

                // Through table's source foreign key (foreign_key) should be this model's primary key
                $col = $through.'.'.$hasMany['foreignKey'];
                $val = $this->pk();
            }
            else
            {
                // Simple has_many relationship, search where target model's foreign key is this model's primary key
                $col = $model->getModelName().'.'.$hasMany['foreignKey'];
                $val = $this->pk();
            }

            return $model->where($col, $val);
        }
        else
        {
            throw new \Exception(sprintf(
                'The %s property does not exist in the %s class', 
                $column, get_class($this)));
        }
    }

    /**
     * Base set method.
     * [!!] This should not be overridden.
     *
     * @param  string $column  Column name
     * @param  mixed  $value   Column value
     * @return void
     */
    public function __set($column, $value)
    {
        $this->set($column, $value);
    }

    /**
     * Handles setting of columns
     * Override this method to add custom set behavior
     *
     * @param  string $column Column name
     * @param  mixed  $value  Column value
     * @throws Kohana_Exception
     * @return ORM
     */
    public function set($column, $value)
    {
        if ( ! isset($this->objectName)) {
            // Object not yet constructed, so we're loading data from a database call cast
            $this->castData[$column] = $value;

            return $this;
        }

        $info = ORM::$models[$this->objectName];

        if (array_key_exists($column, $this->object)) {
            $cols = ORM::$models[$this->objectName]['tableColumns'];
            
            if ($cols[$column]['set']) {
                $value = $this->$cols[$column]['set']($value);
            }

            // See if the data really changed
            if ($value !== $this->object[$column])
            {
                $this->object[$column] = $value;

                // Data has changed
                $this->changed[$column] = $column;

                // Object is no longer saved or valid
                $this->saved = $this->valid = false;
            }
        }
        elseif (isset($info['belongsTo'][$column]))
        {
            // Update related object itself
            $this->related[$column] = $value;

            // Update the foreign key of this model
            $this->object[$info['belongsTo'][$column]['foreignKey']] = ($value instanceof ORM)
                ? $value->pk()
                : null;

            $this->changed[$column] = $info['belongsTo'][$column]['foreignKey'];
        }
        else
        {
            throw new \Exception(sprintf(
                'The %s property does not exist in the %s class',
                $column, get_class($this)));
        }

        return $this;
    }

    /**
     * Set values from an array with support for one-one relationships.  
     * This method should be used for loading in post data, etc.
     *
     * @param  array $values   Array of column => val
     * @param  array $expected Array of keys to take from $values
     * @return ORM
     */
    public function values(array $values, array $expected = null)
    {
        // Default to expecting everything except the primary key
        if ($expected === null)
        {
            $expected = array_keys($this('tableColumns'));

            // Don't set the primary key by default
            unset($values[$this('primaryKey')]);
        }

        foreach ($expected as $key => $column)
        {
            if (is_string($key))
            {
                // isset() fails when the value is null (we want it to pass)
                if ( ! array_key_exists($key, $values))
                    continue;

                // Try to set values to a related model
                $this->{$key}->values($values[$key], $column);
            }
            else
            {
                // isset() fails when the value is null (we want it to pass)
                if ( ! array_key_exists($column, $values))
                    continue;

                // Update the column, respects __set()
                $this->$column = $values[$column];
            }
        }

        return $this;
    }

    /**
     * Returns the values of this object as an array, including any related one-one
     * models that have already been loaded using with()
     *
     * @return array
     */
    public function asArray($skip = array())
    {
        $object = array();

        foreach ($this->_object as $column => $value) {
            // Call __get for any user processing
            $object[$column] = $this->__get($column);
        }


        foreach ($this->_related as $column => $model) {
            // Include any related objects that are already loaded
            $object[$column] = $model->as_array();
        }

        foreach ($skip as $column) {
            if (array_key_exists($column, $object)) {
                unset($object[$column]);
            }
        }

        return $object;
    }

    /**
     * Binds another one-to-one object to this model.  One-to-one objects
     * can be nested using 'object1:object2' syntax
     *
     * @param  string $target_path Target model to bind to
     * @return ORM
     */
    public function with($targetPath)
    { 
        if (isset($this->withApplied[$targetPath])) {
            // Don't join anything already joined 
            return $this;
        }

        // Split object parts
        $aliases = explode(':', $targetPath);
        $target = $this;

        foreach ($aliases as $alias)
        {
            // Go down the line of objects to find the given target
            $parent = $target;
            $target = $parent->getRelated($alias);

            if ( ! $target)
            {
                // Can't find related object
                return $this;
            }
        }

        // Target alias is at the end
        $targetAlias = $alias;

        // Pop-off top alias to get the parent path (user:photo:tag becomes user:photo - the parent table prefix)
        array_pop($aliases);
        $parentPath = implode(':', $aliases);

        if (empty($parentPath))
        {
            // Use this table name itself for the parent path
            $parentPath = $this->objectName;
        }
        else
        {
            if ( ! isset($this->withApplied[$parentPath]))
            {
                // If the parent path hasn't been joined yet, do it first (otherwise LEFT JOINs fail)
                $this->with($parentPath);
            }
        }

        // Add to with_applied to prevent duplicate joins
        $this->withApplied[$targetPath] = true;

        // Use the keys of the empty object to determine the columns
        foreach (array_keys($target->object) as $column)
        {
            $name = $targetPath.'.'.$column;
            $alias = $targetPath.':'.$column;

            // Add the prefix so that load_result can determine the relationship
            $this->select(array($name, $alias));
        }

        $parentInfo = ORM::$models[$parent->getModelName()];

        if (isset($parentInfo['belongsTo'][$targetAlias]))
        {
            // Parent belongs_to target, use target's primary key and parent's foreign key
            $join_col1 = $targetPath.'.'.$target('primaryKey');
            $join_col2 = $parentPath.'.'.$parentInfo['belongsTo'][$targetAlias]['foreignKey'];
        }
        else
        {
            // Parent has_one target, use parent's primary key as target's foreign key
            $join_col1 = $parentPath.'.'.$parentInfo['primaryKey'];
            $join_col2 = $targetPath.'.'.$parentInfo['hasOne'][$targetAlias]['foreignKey'];
        }

        // Join the related object into the result
        $this->join(array($target('tableName'), $targetPath), 'LEFT')->on($join_col1, '=', $join_col2);

        return $this;
    }

    /**
     * Initializes the Database Builder to given query type
     *
     * @param  integer $type Type of Database query
     * @return ORM
     */
    protected function build($type)
    {
        $info = ORM::$models[$this->objectName];

        // Construct new builder object based on query type
        switch ($type)
        {
            case ORM::SELECT:
                $this->dbBuilder = $this->db->select();
            break;
            case ORM::UPDATE:
                $this->dbBuilder = 
                    $this->db->update(array($info('tableName'), $this->objectName));
            break;
            case ORM::DELETE:
                // Cannot use an alias for DELETE queries
                $this->dbBuilder = $this->db->delete($info['tableName']);
        }

        // Process pending database method calls
        foreach ($this->dbPending as $method)
        {
            $name = $method['name'];
            $args = $method['args'];
            $num = count($method['args']);

            $this->dbApplied[$name] = $name;

            if ($num == 3) {
                $this->dbBuilder->$name($args[0], $args[1], $args[2]);
            } else if ($num == 2) {
                $this->dbBuilder->$name($args[0], $args[1]);
            } else if ($num == 1) {
                $this->dbBuilder->$name($args[0]);
            } else if ($num == 0) {
                $this->dbBuilder->$name();
            } else {
                call_user_func_array(array($this->dbBuilder, $name), $args);
            }
        }

        return $this;
    }

    /**
     * Finds and loads a single database row into the object.
     *
     * @chainable
     * @throws Kohana_Exception
     * @return ORM
     */
    public function find()
    {
        if ($this->loaded)
            throw new \Exception('Method find() cannot be called on loaded objects');

        if ( ! empty($this->loadWith))
        {
            foreach ($this->loadWith as $alias)
            {
                // Bind auto relationships
                $this->with($alias);
            }
        }

        $this->build(ORM::SELECT);

        return $this->loadResult(false);
    }

    /**
     * Finds multiple database rows and returns an iterator of the rows found.
     *
     * @throws Kohana_Exception
     * @return Database_Result
     */
    public function findAll()
    {
        if ($this->loaded)
            throw new \Exception('Method find_all() cannot be called on loaded objects');

        if ( ! empty($this->loadWith))
        {
            foreach ($this->loadWith as $alias)
            {
                // Bind auto relationships
                $this->with($alias);
            }
        }

        $this->build(ORM::SELECT);

        return $this->loadResult(true);
    }

    /**
     * Returns an array of columns to include in the select query. This method
     * can be overridden to change the default select behavior.
     *
     * @return array Columns to select
     */
    protected function buildSelect()
    {
        $info = static::$models[$this->objectName];
        $columns = array();

        foreach ($info['tableColumns'] as $column => $_)
        {
            $columns[] = array($this->objectName.'.'.$column, $column);
        }

        return $columns;
    }

    /**
     * Loads a database result, either as a new record for this model, or as
     * an iterator for multiple rows.
     *
     * @chainable
     * @param  bool $multiple Return an iterator or load a single row
     * @return ORM|Database_Result
     */
    protected function loadResult($multiple = false)
    {
        $info = static::$models[$this->objectName];
        $dbb = $this->dbBuilder;

        $dbb->from(array($info['tableName'], $this->objectName));

        if ($multiple === false)
        {
            // Only fetch 1 record
            $dbb->limit(1);
        }

        // Select all columns by default
        $dbb->selectArray($this->buildSelect());

        if ( ! isset($this->dbApplied['orderBy']) AND ! empty($this->sorting))
        {
            foreach ($this->sorting as $column => $direction)
            {
                if (strpos($column, '.') === false)
                {
                    // Sorting column for use in JOINs
                    $column = $this->objectName.'.'.$column;
                }

                $dbb->orderBy($column, $direction);
            }
        }

        if ($multiple === true)
        {
            // Return database iterator casting to this object type
            $result = $dbb->asObject(get_class($this))->execute();

            $this->reset();

            return $result;
        }
        else
        {
            // Load the result as an associative array
            $result = $dbb->execute();

            $this->reset();

            if ($result->count() === 1)
            {
                // Load object values
                $this->loadValues( (array) $result->current());
            }
            else
            {
                // Clear the object, nothing was found
                $this->clear();
            }

            return $this;
        }
    }

    /**
     * Loads an array of values into into the current object.
     *
     * @chainable
     * @param  array $values Values to load
     * @return ORM
     */
    public function loadValues(array $values)
    {
        $pk = ORM::$models[$this->objectName]['primaryKey'];
        $cols = ORM::$models[$this->objectName]['tableColumns'];

        if (array_key_exists($pk, $values)) {
            if ($values[$pk] !== null) {
                // Flag as loaded and valid
                $this->loaded = $this->valid = true;

                // Store primary key
                $this->primaryKeyValue = $values[$pk];
            } else {
                // Not loaded or valid
                $this->loaded = $this->valid = false;
            }
        }

        // Related objects
        $related = array();

        foreach ($values as $column => $value)
        {
            if (strpos($column, ':') === false)
            {
                if ($cols[$column]['load']) {
                    $value = $this->$cols[$column]['load']($value);
                }

                // Load the value to this model
                $this->object[$column] = $value;
            }
            else
            {
                // Column belongs to a related model
                list ($prefix, $column) = explode(':', $column, 2);

                $related[$prefix][$column] = $value;
            }
        }

        if ( ! empty($related))
        {
            foreach ($related as $object => $values)
            {
                // Load the related objects with the values in the result
                $this->getRelated($object)->loadValues($values);
            }
        }

        if ($this->loaded)
        {
            // Store the object in its original state
            $this->originalValues = $this->object;
        }

        return $this;
    }

    /**
     * Rule definitions for validation
     *
     * @return array
     */
    public function getValidationRules()
    {
        return array();
    }

    /**
     * Filters a value for a specific column
     *
     * @param  string $field  The column name
     * @param  string $value  The value to filter
     * @return string
     */
    protected function runFilter($field, $value)
    { return $value;
        $filters = $this->filters();

        // Get the filters for this column
        $wildcards = empty($filters[true]) ? array() : $filters[true];

        // Merge in the wildcards
        $filters = empty($filters[$field]) ? $wildcards : array_merge($wildcards, $filters[$field]);

        // Bind the field name and model so they can be used in the filter method
        $_bound = array
        (
            ':field' => $field,
            ':model' => $this,
        );

        foreach ($filters as $array)
        {
            // Value needs to be bound inside the loop so we are always using the
            // version that was modified by the filters that already ran
            $_bound[':value'] = $value;

            // Filters are defined as array($filter, $params)
            $filter = $array[0];
            $params = Arr::get($array, 1, array(':value'));

            foreach ($params as $key => $param)
            {
                if (is_string($param) AND array_key_exists($param, $_bound))
                {
                    // Replace with bound value
                    $params[$key] = $_bound[$param];
                }
            }

            if (is_array($filter) OR ! is_string($filter))
            {
                // This is either a callback as an array or a lambda
                $value = call_user_func_array($filter, $params);
            }
            elseif (strpos($filter, '::') === false)
            {
                // Use a function call
                $function = new ReflectionFunction($filter);

                // Call $function($this[$field], $param, ...) with Reflection
                $value = $function->invokeArgs($params);
            }
            else
            {
                // Split the class and method of the rule
                list($class, $method) = explode('::', $filter, 2);

                // Use a static method call
                $method = new ReflectionMethod($class, $method);

                // Call $Class::$method($this[$field], $param, ...) with Reflection
                $value = $method->invokeArgs(null, $params);
            }
        }

        return $value;
    }

    /**
     * Label definitions for validation
     *
     * @return array
     */
    public function labels()
    {
        return array();
    }

    /**
     * Validates the current model's data
     *
     * @param  Validation $extra_validation Validation object
     * @throws ORM_Validation_Exception
     * @return ORM
     */
    public function check(\Ana\Validation $extraValidation = null)
    {
        // Determine if any external validation failed
        $extraErrors = ($extraValidation and ! $extraValidation->check());

        // Always build a new validation object
        $this->createValidation();

        $array = $this->validation;

        if (($this->valid = $array->check()) === false or $extraErrors)
        {
            $exception = new \Ana\ORM_Validation_Exception($this('errorsFilename'), $array);

            if ($extraErrors)
            {
                // Merge any possible errors from the external object
                $exception->add_object('_external', $extraValidation);
            }
            throw $exception;
        }

        return $this;
    }

    /**
     * Insert a new object to the database
     * @param  Validation $validation Validation object
     * @throws Kohana_Exception
     * @return ORM
     */
    public function create(\Ana\Validation $validation = null)
    {
        if ($this->loaded) {
            throw new \Exception(sprintf(
                'Cannot create %s model because it is already loaded.', 
                $this->objectName));
        }

        // Require model validation before saving
        if ( ! $this->valid or $validation)
        {
            $this->check($validation);
        }

        $cols = ORM::$models[$this->objectName]['tableColumns'];

        $data = array();
        foreach ($this->changed as $column)
        {
            $col = $cols[$column];
            $value = $this->object[$column];

            if ($col['save']) {
                $value = $this->$col['save']($value);
            }

            if ($col['create']) {
                $value = $this->$col['create']($value);
            }

            // Generate list of column => values
            $data[$column] = $value;
        }

        $result = $this->db
            ->insert($this('tableName'))
            ->values($data)
            ->execute();

        $primaryKey = $this('primaryKey');

        if ( ! array_key_exists($primaryKey, $data))
        {
            // Load the insert id as the primary key if it was left out
            $this->object[$primaryKey] = $this->primaryKeyValue = $result[0];
        }
        else
        {
            $this->primaryKeyValue = $this->object[$primaryKey];
        }

        // Object is now loaded and saved
        $this->loaded = $this->saved = true;

        // All changes have been saved
        $this->changed = array();
        $this->originalValues = $this->object;

        return $this;
    }

    /**
     * Updates a single record or multiple records
     *
     * @chainable
     * @param  Validation $validation Validation object
     * @throws Kohana_Exception
     * @return ORM
     */
    public function update(\Ana\Validation $validation = null)
    {
        if ( ! $this->loaded) {
            throw new \Exception(sprintf(
                'Cannot update %s model because it is not loaded.', 
                $this->objectName));
        }

        // Run validation if the model isn't valid or we have additional validation rules.
        if ( ! $this->valid OR $validation)
        {
            $this->check($validation);
        }

        if (empty($this->changed))
        {
            // Nothing to update
            return $this;
        }

        $cols = ORM::$models[$this->objectName]['tableColumns'];

        $data = array();
        foreach ($this->changed as $column)
        {
            $col = $cols[$column];
            $value = $this->object[$column];

            if ($col['save']) {
                $value = $this->$col['save']($value);
            }

            if ($col['update']) {
                $value = $this->$col['create']($value);
            }

            // Generate list of column => values
            $data[$column] = $value;
        }

        // Use primary key value
        $id = $this->pk();
        $primaryKey = $this('primaryKey');

        // Update a single record
        $this->db
            ->update($this('tableName'))
            ->set($data)
            ->where($primaryKey, $id)
            ->execute();

        if (isset($data[$primaryKey]))
        {
            // Primary key was changed, reflect it
            $this->primaryKeyValue = $data[$primaryKey];
        }

        // Object has been saved
        $this->saved = true;

        // All changes have been saved
        $this->changed = array();
        $this->originalValues = $this->object;

        return $this;
    }

    /**
     * Updates or Creates the record depending on loaded()
     *
     * @chainable
     * @param  Validation $validation Validation object
     * @return ORM
     */
    public function save(Validation $validation = null)
    {
        return $this->loaded ? $this->update($validation) : $this->create($validation);
    }

    /**
     * Deletes a single record while ignoring relationships.
     *
     * @chainable
     * @throws Kohana_Exception
     * @return ORM
     */
    public function delete()
    {
        if ( ! $this->loaded)
            throw new \Exception(sprintf(
                'Cannot delete % model because it is not loaded.',
                $this->objectName));

        // Use primary key value
        $id = $this->pk();

        $this->db
            ->delete()
            ->from($this('tableName'))
            ->where($this('primaryKey'), $id)
            ->execute();

        return $this->clear();
    }

    /**
     * Tests if this object has a relationship to a different model,
     * or an array of different models. When providing far keys, the number
     * of relations must equal the number of keys.
     * 
     *
     *     // Check if $model has the login role
     *     $model->has('roles', ORM::factory('role', array('name' => 'login')));
     *     // Check for the login role if you know the roles.id is 5
     *     $model->has('roles', 5);
     *     // Check for all of the following roles
     *     $model->has('roles', array(1, 2, 3, 4));
     *     // Check if $model has any roles
     *     $model->has('roles')
     *
     * @param  string  $alias    Alias of the has_many "through" relationship
     * @param  mixed   $far_keys Related model, primary key, or an array of primary keys
     * @return boolean
     */
    public function has($alias, $farKeys = null)
    {
        $count = $this->countRelations($alias, $farKeys);

        if ($farKeys === null) {
            return (bool) $count;
        } else {
            return $count === count($farKeys);
        }

    }

    /**
     * Tests if this object has a relationship to a different model,
     * or an array of different models. When providing far keys, this function
     * only checks that at least one of the relationships is satisfied.
     *
     *     // Check if $model has the login role
     *     $model->has('roles', ORM::factory('role', array('name' => 'login')));
     *     // Check for the login role if you know the roles.id is 5
     *     $model->has('roles', 5);
     *     // Check for any of the following roles
     *     $model->has('roles', array(1, 2, 3, 4));
     *     // Check if $model has any roles
     *     $model->has('roles')
     *
     * @param  string  $alias    Alias of the has_many "through" relationship
     * @param  mixed   $far_keys Related model, primary key, or an array of primary keys
     * @return boolean
     */
    public function hasAny($alias, $farKeys = null)
    {
        return (bool) $this->countRelations($alias, $farKeys);
    }

    /**
     * Returns the number of relationships 
     *
     *     // Counts the number of times the login role is attached to $model
     *     $model->has('roles', ORM::factory('role', array('name' => 'login')));
     *     // Counts the number of times role 5 is attached to $model
     *     $model->has('roles', 5);
     *     // Counts the number of times any of roles 1, 2, 3, or 4 are attached to
     *     // $model
     *     $model->has('roles', array(1, 2, 3, 4));
     *     // Counts the number roles attached to $model
     *     $model->has('roles')
     *
     * @param  string  $alias    Alias of the has_many "through" relationship
     * @param  mixed   $far_keys Related model, primary key, or an array of primary keys
     * @return integer
     */
    public function countRelations($alias, $farKeys = null)
    {
        $info = ORM::$models[$this->objectName];

        if ($farKeys === null)
        {
            $count = $this->db
                        ->select(array($this->db->fn('count', '*'), 'count'))
                        ->from($info['hasMany'][$alias]['through'])
                        ->where($info['hasMany'][$alias]['foreignKey'], $this->pk())
                        ->execute()
                        ->get('count');

            return $count;
        }

        $farKeys = ($farKeys instanceof \Ana\ORM) ? $farKeys->pk() : $farKeys;

        // We need an array to simplify the logic
        $farKeys = (array) $farKeys;

        // Nothing to check if the model isn't loaded or we don't have any $farKeys
        if ( ! $farKeys OR ! $this->loaded) {
            return 0;
        }

        $count = 
            $this->db
                ->select(array($this->db->fn('count', '*'), 'count'))
                ->from($info['hasMany'][$alias]['through'])
                ->where($info['hasMany'][$alias]['foreignKey'], $this->pk())
                ->where($info['hasMany'][$alias]['farKey'], 'IN', $farKeys)
                ->execute()
                ->get('count');

        // Rows found need to match the rows searched
        return $count;
    }

    /**
     * Adds a new relationship to between this model and another.
     *
     *     // Add the login role using a model instance
     *     $model->add('roles', ORM::factory('role', array('name' => 'login')));
     *     // Add the login role if you know the roles.id is 5
     *     $model->add('roles', 5);
     *     // Add multiple roles (for example, from checkboxes on a form)
     *     $model->add('roles', array(1, 2, 3, 4));
     *
     * @param  string  $alias    Alias of the has_many "through" relationship
     * @param  mixed   $far_keys Related model, primary key, or an array of primary keys
     * @return ORM
     */
    public function add($alias, $farKeys)
    {
        $info = ORM::$models[$this->objectName];
        $hasMany = $info['hasMany'][$alias];

        $farKeys = ($farKeys instanceof \Ana\ORM) ? $farKeys->pk() : $farKeys;

        $columns = array($hasMany['foreignKey'], $hasMany['farKey']);
        $foreignKey = $this->pk();

        $query = $this->db->insert($hasMany['through'], $columns);

        foreach ( (array) $farKeys as $key)
        {
            $query->values(array($foreignKey, $key));
        }

        $query->execute();

        return $this;
    }

    /**
     * Removes a relationship between this model and another.
     *
     *     // Remove a role using a model instance
     *     $model->remove('roles', ORM::factory('role', array('name' => 'login')));
     *     // Remove the role knowing the primary key
     *     $model->remove('roles', 5);
     *     // Remove multiple roles (for example, from checkboxes on a form)
     *     $model->remove('roles', array(1, 2, 3, 4));
     *     // Remove all related roles
     *     $model->remove('roles');
     *
     * @param  string $alias    Alias of the has_many "through" relationship
     * @param  mixed  $far_keys Related model, primary key, or an array of primary keys
     * @return ORM
     */
    public function remove($alias, $farKeys = null)
    {
        $hasMany = ORM::$models[$this->objectName]['hasMany'][$alias];        

        $farKeys = ($farKeys instanceof \Ana\ORM) ? $farKeys->pk() : $farKeys;

        $query = 
            $this->db
                ->delete()
                ->from($hasMany['through'])
                ->where($hasMany['foreignKey'], $this->pk());

        if ($farKeys !== null) {
            // Remove all the relationships in the array
            $query->where($hasMany['farKey'], 'IN', (array) $farKeys);
        }

        $query->execute();

        return $this;
    }

    /**
     * Count the number of records in the table.
     *
     * @return integer
     */
    public function countAll()
    {
        $selects = array();

        foreach ($this->dbPending as $key => $method)
        {
            if ($method['name'] == 'select')
            {
                // Ignore any selected columns for now
                $selects[] = $method;
                unset($this->dbPending[$key]);
            }
        }

        if ( ! empty($this->loadWith))
        {
            foreach ($this->loadWith as $alias)
            {
                // Bind relationship
                $this->with($alias);
            }
        }

        $this->build(ORM::SELECT);

        $count = 
            $this->dbBuilder
                ->from(array($this('tableName'), $this->objectName))
                ->select(array(Db::fn('count', '*'), 'count'))
                ->execute()
                ->get('count');

        // Add back in selected columns
        $this->dbPending += $selects;

        $this->reset();

        // Return the total number of records in a table
        return $count;
    }

    public function __call($method, $args = array())
    {
        $wheres = array('where', 'andWhere', 'orWhere', 'notWhere', 'andNotWhere',
            'orNotWhere', 'whereOpen', 'whereClose', 'andWhereOpen', 'andWhereClose',
            'orWhereOpen', 'orWhereClose', 'orWhereOpen', 'orWhereClose', 
            'notWhereOpen', 'notWhereClose', 'andNotWhereOpen', 'andNotWhereClose',
            'orNotWhereOpen', 'orNotWhereClose', 'orderBy', 'limit', 'offset',
            'distinct', 'select', 'from', 'join', 'on', 'andOn', 'orOn',
            'groupBy', 
            'having', 'notHaving', 'andHaving', 'andNotHaving', 'orHaving',
            'orNotHaving', 'havingOpen', 'notHavingOpen', 'havingClose',
            'notHavingClose', 'andHavingOpen', 'andNotHavingOpen', 
            'andHavingClose', 'andNotHavingClose', 'orHavingOpen', 
            'orNotHavingOpen', 'orHavingClose', 'orNotHavingClose', 
            'bind');

        if (in_array($method, $wheres)) {
            // $args[0] is column name without table alias prefix
            if ( ! in_array($method, array('limit', 'join')) 
                and ! is_array($args[0]) 
                and strpos($args[0], '.') === false) {
                $args[0] = $this->objectName.'.'.$args[0];
            }

            $this->dbPending[] = array(
                'name'  => $method,
                'args'  => $args
            );

            return $this;
        }

        throw new \BadMethodCallException(sprintf(
            'Method %s does not exists in class %s', $method, get_class($this)));
    }

    /**
     * Returns an ORM model for the given one-one related alias
     *
     * @param  string $alias Alias name
     * @return ORM
     */
    protected function getRelated($alias)
    {
        $data = ORM::$models[$this->objectName];

        if (isset($this->related[$alias]))
        {
            return $this->related[$alias];
        }
        elseif (isset($data['hasOne'][$alias]))
        {
            return $this->related[$alias] = new $data['hasOne'][$alias]['model'];
        }
        elseif (isset($data['belongsTo'][$alias]))
        {
            return $this->related[$alias] = new $data['belongsTo'][$alias]['model'];
        }
        else
        {
            return false;
        }
    }

    /**
     * Clears query builder.  Passing false is useful to keep the existing
     * query conditions for another query.
     *
     * @param bool $next Pass false to avoid resetting on the next call
     * @return ORM
     */
    public function reset($next = true)
    {
        if ($next and $this->dbReset) {
            $this->dbPending   = array();
            $this->dbApplied   = array();
            $this->dbBuilder   = null;
            $this->withApplied = array();
        }

        // Reset on the next call?
        $this->dbReset = $next;

        return $this;
    }

    public function isLoaded()
    {
        return $this->loaded;
    }

    public function isSaved()
    {
        return $this->saved;
    }

    /**
     * Returns the value of the primary key
     *
     * @return mixed Primary key
     */
    public function pk()
    {
        return $this->primaryKeyValue;
    }

    /**
     * Returns database object.
     *
     * @return \Cabinet\DBAL\Connection
     */
    public function getDb()
    {
        return $this->db;
    }

    public function getLoadWith()
    {
        return $this->loadWith;
    }

    public function getOriginalValues()
    {
        return $this->originalValues;
    }

    public function getValidation()
    {
        if ( ! isset($this->validation))
        {
            // Initialize the validation object
            $this->createValidation();
        }

        return $this->validation;
    }

    public function getObject()
    {
        return $this->object;
    }

    public function getInfo($key = null, $default = null)
    {
        if ($key === null) {
            return ORM::$models[$this->objectName];
        }

        return isset(ORM::$models[$this->objectName][$key])
                ? ORM::$models[$this->objectName][$key]
                : $default;
    }

    public function getModelName()
    {
        return $this->objectName;
    }

    public function setValid($valid = true)
    {
        $this->valid = $valid;
    }

    public function __invoke($key = null, $default = null)
    {
        $modelName = static::modelName();

        if ($key === null) {
            return ORM::$models[$modelName];
        }

        return isset(ORM::$models[$modelName][$key])
                ? ORM::$models[$modelName][$key]
                : $default;
    }

    /**
     * Checks whether a column value is unique.
     * Excludes itself if loaded.
     *
     * @param   string   $field  the field to check for uniqueness
     * @param   mixed    $value  the value to check for uniqueness
     * @return  bool     whteher the value is unique
     */
    public function isUnique($field, $value = null)
    {
        $info = ORM::$models[$this->objectName];

        $record = 
            ORM::$connection
                ->select($info['primaryKey'])
                ->from($info['tableName'])
                ->where($field, $value ?: $this->$field)
                ->execute();

        if ($record) {
            return $record[0][$info['primaryKey']] == $model->pk();
        }

        return true;
    }

    public function createSlug($field, $value)
    {
        $info = ORM::$models[$this->objectName];

        $result = 
            $this->db
                ->select($field)
                ->from($info['tableName'])
                ->where($field, $value)
                ->orWhere($field, 'rlike', $value.'-[0-9]*')
                ->orderBy(Db::fn('length', $field), 'desc')
                ->orderBy($field, 'desc')
                ->limit(1)
                ->execute()
                ->get($field);

        if ( ! $result) {
            return $value;
        }

        preg_match('/-([0-9])+$/', $result, $match);

        if ($match) {
            $value = str_replace($match[1], (int) $match[1] + 1, $result);
        } else {
            $value .= '-1';
        }

        return $value;
    }
} // End ORM
