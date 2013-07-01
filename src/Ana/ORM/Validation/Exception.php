<?php

namespace Ana;

use \Ana\Validation;

/**
 * ORM Validation exceptions.
 *
 * @package    Kohana/ORM
 * @author     Kohana Team
 * @copyright  (c) 2007-2012 Kohana Team
 * @license    http://kohanaframework.org/license
 */
class ORM_Validation_Exception extends \Exception {

    /**
   * Array of validation objects
   * @var array
   */
    protected $_objects = array();

    /**
   * The alias of the main ORM model this exception was created for
   * @var string
   */
    protected $_alias = NULL;

    /**
     * Constructs a new exception for the specified model
     *
     * @param  string     $alias       The alias to use when looking for error messages
     * @param  Validation $object      The Validation object of the model
     * @param  string     $message     The error message
     * @param  array      $values      The array of values for the error message
     * @param  integer    $code        The error code for the exception
     * @return void
     */
    public function __construct($alias, \Ana\Validation $object, $message = 'Failed to validate array')
    {
        $this->_alias = $alias;
        $this->_objects['_object'] = $object;
        $this->_objects['_has_many'] = FALSE;

        parent::__construct($message);
    }

    /**
     * Adds a Validation object to this exception
     *
     *     // The following will add a validation object for a profile model
     *     // inside the exception for a user model.
     *     $e->add_object('profile', $validation);
     *     // The errors array will now look something like this
     *     // array
     *     // (
     *     //   'username' => 'This field is required',
     *     //   'profile'  => array
     *     //   (
     *     //     'first_name' => 'This field is required',
     *     //   ),
     *     // );
     *
     * @param  string     $alias    The relationship alias from the model
     * @param  Validation $object   The Validation object to merge
     * @param  mixed      $has_many The array key to use if this exception can be merged multiple times
     * @return ORM_Validation_Exception
     */
    public function addObject($alias, \Ana\Validation $object, $has_many = FALSE)
    {
        // We will need this when generating errors
        $this->_objects[$alias]['_has_many'] = ($has_many !== FALSE);

        if ($has_many === TRUE)
        {
            // This is most likely a has_many relationship
            $this->_objects[$alias][]['_object'] = $object;
        }
        elseif ($has_many)
        {
            // This is most likely a has_many relationship
            $this->_objects[$alias][$has_many]['_object'] = $object;
        }
        else
        {
            $this->_objects[$alias]['_object'] = $object;
        }

        return $this;
    }

    /**
     * Merges an ORM_Validation_Exception object into the current exception
     * Useful when you want to combine errors into one array
     *
     * @param  ORM_Validation_Exception $object   The exception to merge
     * @param  mixed                    $has_many The array key to use if this exception can be merged multiple times
     * @return ORM_Validation_Exception
     */
    public function merge(Ana\ORM_Validation_Exception $object, $has_many = FALSE)
    {
        $alias = $object->alias();

        // We will need this when generating errors
        $this->_objects[$alias]['_has_many'] = ($has_many !== FALSE);

        if ($has_many === TRUE)
        {
            // This is most likely a has_many relationship
            $this->_objects[$alias][] = $object->objects();
        }
        elseif ($has_many)
        {
            // This is most likely a has_many relationship
            $this->_objects[$alias][$has_many] = $object->objects();
        }
        else
        {
            $this->_objects[$alias] = $object->objects();
        }

        return $this;
    }

    /**
     * Returns a merged array of the errors from all the Validation objects in this exception
     *
     *     // Will load Model_User errors from messages/orm-validation/user.php
     *     $e->errors('orm-validation');
     *
     * @param   string  $directory Directory to load error messages from
     * @param   mixed   $translate Translate the message
     * @return  array
     * @see generate_errors()
     */
    public function errors($directory = NULL)
    {
        return $this->generateErrors($this->_alias, $this->_objects, $directory);
    }

    /**
     * Recursive method to fetch all the errors in this exception
     *
     * @param  string $alias     Alias to use for messages file
     * @param  array  $array     Array of Validation objects to get errors from
     * @param  string $directory Directory to load error messages from
     * @param  mixed  $translate Translate the message
     * @return array
     */
    protected function generateErrors($alias, array $array, $directory)
    {
        $errors = array();

        foreach ($array as $key => $object)
        {
            if (is_array($object))
            {
                $errors[$key] = ($key === '_external')
                    // Search for errors in $alias/_external.php
                    ? $this->generateErrors($alias.'/'.$key, $object, $directory, $translate)
                    // Regular models get their own file not nested within $alias
                    : $this->generateErrors($key, $object, $directory, $translate);
            }
            elseif ($object instanceof \Ana\Validation)
            {
                if ($directory === NULL)
                {
                    // Return the raw errors
                    $file = NULL;
                }
                else
                {
                    $file = '/'.trim($directory.'/'.$alias, '/');
                }

                // Merge in this array of errors
                $errors += $object->errors($file);
            }
        }

        return $errors;
    }

    /**
     * Returns the protected _objects property from this exception
     *
     * @return array
     */
    public function objects()
    {
        return $this->_objects;
    }

    /**
     * Returns the protected _alias property from this exception
     *
     * @return string
     */
    public function alias()
    {
        return $this->_alias;
    }
} // End Kohana_ORM_Validation_Exception
