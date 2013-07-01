<?php

namespace Model;

\Ana\ORM::define('\Model\Session', array(
    'tableName' => 'sessions',
    'tableColumns' => array(
        'id'    => 'int',
        'data'  => 'string',
        'user_id'   => 'int'
    ),
    'belongsTo' => array(
        'user' => array(
            'foreignKey' => 'user_id',
            'model' => '\Model\User'
        )
    )
));

class Session extends \Ana\ORM {}