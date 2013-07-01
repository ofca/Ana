<?php

namespace Model;

\Ana\ORM::define('\Model\User', array(
    'tableName' => 'users',
    'tableColumns'  => array(
        'id'            => 'int',
        'name'          => 'string',
        'password'      => 'string',
        'created_at'    => 'timestamp',
        'updated_at'    => 'timestamp',
        'group_id'      => 'int'
    ),
    'belongsTo' => array(
        'group' => array(
            'foreignKey' => 'group_id',
            'model'      => '\Model\Group'
        )
    ),
    'hasMany' => array(
        'photos' => array(
            'model'     => '\Model\Photo',
            'farKey'    => 'photo_id',
            'foreignKey'=> 'user_id',
            'through'   => 'users_photos'
        )
    ),
    'hasOne' => array(
        'session' => array(
            'model'         => '\Model\Session',
            'foreignKey'    => 'user_id'
        )
    )
));

class User extends \Ana\ORM
{
    /**
     * Return validation rules
     * @return array
     */
    public function getValidationRules()
    {
        return array(
            'group_id'  => array(
                array('notEmpty')
            )
        );
    }

    /**
     * Custom setter for "name" column
     * 
     * @param  mixed $value Value.
     * @return mixed
     */
    protected function onSetName($value)
    {
        return trim($value);
    }

    /**
     * Custom getter fro "name" column
     * 
     * @param  mixed $value Value.
     * @return mixed
     */
    protected function onGetName($value)
    {
        return explode(' ', $value);
    }

    protected function onSavePassword($value)
    {
        return sha1($value);    
    }

    protected function onLoadPassword($value)
    {
        return null;
    }
}