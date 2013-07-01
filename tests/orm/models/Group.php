<?php

namespace Model;

\Ana\ORM::define('\Model\Group', array(
    'tableName'     => 'groups',
    'tableColumns'  => array(
        'id'    => 'int',
        'name'  => 'string'
    ),
    'hasMany' => array(
        'users' => array(
            'model' => '\Model\User',
            'foreignKey' => 'group_id'
        )
    )
));

class Group extends \Ana\ORM
{
    
}