<?php

namespace Model;

\Ana\ORM::define('\Model\Photo', array(
    'tableName' => 'photos',
    'tableColumns' => array(
        'id'    => 'int',
        'url'   => 'string'
    )
));

class Photo extends \Ana\ORM {}