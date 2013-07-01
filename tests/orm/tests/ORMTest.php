<?php

use \Ana\ORM;
use \Model\User;
use \Model\Group;

class ORMTest extends PHPUnit_Framework_TestCase
{
    private $connection;

    public function setUp()
    {
        // Connect to database
        $conn = \Cabinet\DBAL\Db::connection(array(
            'driver' => 'mysql',
            'username' => 'root',
            'password' => 'root',
        ));

        // Drop database if exists
        $conn
            ->schema()
            ->database('test_database')
            ->drop()
            ->ifExists()
            ->execute();

        // Create database
        $conn
            ->schema()
            ->database('test_database')
            ->ifNotExists()
            ->create()
            ->execute();

        // Connect to created database
        $conn = $this->connection = \Cabinet\DBAL\Db::connection(array(
            'driver' => 'mysql',
            'username' => 'root',
            'password' => isset($_SERVER['DB']) ? '' : 'root',
            'database' => 'test_database',
            'asObject'  => false
        ));

        // Create groups table
        $conn
            ->schema()
            ->table('groups')
            ->create()
            ->engine('InnoDB')
            ->charset('utf8')
            ->indexes(array(function($index) {
                $index->on('id')->type('primary key');
            }))
            ->fields(array(
                'id' => function($field) {
                    $field->type('int')
                        ->constraint(11)
                        ->incremental();
                },
                'name' => function($field){
                    $field->type('varchar')
                        ->constraint(255);
                }
            ))
            ->execute();

        // Create photos table
        $conn
            ->schema()
            ->table('photos')
            ->create()
            ->engine('InnoDB')
            ->charset('utf8')
            ->indexes(array(function($index) {
                $index->on('id')->type('primary key');
            }))
            ->fields(array(
                'id' => function($field) {
                    $field->type('int')
                        ->constraint(11)
                        ->incremental();
                },
                'url' => function($field){
                    $field->type('varchar')
                        ->constraint(255);
                }
            ))
            ->execute();

        // Create table users_photos
        $conn
            ->schema()
            ->table('users_photos')
            ->create()
            ->engine('InnoDB')
            ->charset('utf8')
            ->indexes(array(function($index) {
                $index->on('id')->type('primary key');
            }))
            ->fields(array(
                'id' => function($field) {
                    $field->type('int')
                        ->constraint(11)
                        ->incremental();
                },
                'user_id' => function($field) {
                    $field->type('int')
                        ->constraint(11);
                },
                'photo_id' => function($field) {
                    $field->type('int')
                        ->constraint(11);
                }
            ))
            ->execute();

        $conn
            ->schema()
            ->table('sessions')
            ->create()
            ->engine('InnoDB')
            ->charset('utf8')
            ->indexes(array(function($index) {
                $index->on('id')->type('primary key');
            }))
            ->fields(array(
                'id' => function($field) {
                    $field->type('int')
                        ->constraint(11)
                        ->incremental();
                },
                'data' => function($field) {
                    $field->type('text');
                },
                'user_id' => function($field) {
                    $field->type('int')->constraint(11);
                }
            ))
            ->execute();

        // Create user table
        $conn
            ->schema()
            ->table('users')
            ->create()
            ->engine('InnoDB')
            ->charset('utf8')
            ->indexes(array(function($index) {
                $index->on('id')->type('primary key');
            }))
            ->fields(array(
                'id' => function($field){
                    $field->type('int')
                        ->constraint(11)
                        ->incremental();
                },
                'name' => function($field){
                    $field->type('varchar')
                        ->constraint(255);
                },
                'password' => function($field){
                    $field->type('varchar')
                        ->constraint(40);
                },
                'created_at' => function($field){
                    $field->type('datetime');
                },
                'updated_at' => function($field){
                    $field->type('datetime');
                },
                'group_id' => function($field){
                    $field->type('int')
                        ->constraint(11)
                        ->nullable(false);
                }
            ))
            ->foreignKeys('group_id', function($key) {
                $key
                    ->constraint('FK_users_groups')
                    ->references('groups', 'id')
                    ->onUpdate('NO ACTION')
                    ->onDelete('CASCADE');
            })
            ->execute();

        $conn
            ->insert('groups')
            ->values(array('name'  => 'Admins'))
            ->execute();

        $conn
            ->insert('groups')
            ->values(array('name'  => 'Moderators'))
            ->execute();

        $conn
            ->insert('groups')
            ->values(array('name'  => 'Editors'))
            ->execute();

        $conn
            ->insert('groups')
            ->values(array('name'  => 'Users'))
            ->execute();

        $conn
            ->insert('groups')
            ->values(array('name'  => 'Anonymous'))
            ->execute();

        $conn
            ->insert('photos')
            ->values(array('url' => 'http://domain.com/image.jpg'))
            ->execute();

        $conn
            ->insert('photos')
            ->values(array('url' => 'http://domain.com/image2.jpg'))
            ->execute();

        $conn
            ->insert('photos')
            ->values(array('url' => 'http://domain.com/image3.jpg'))
            ->execute();

        $conn
            ->insert('users_photos')
            ->values(array('user_id' => 1, 'photo_id' => 1))
            ->execute();

        $conn
            ->insert('users_photos')
            ->values(array('user_id' => 1, 'photo_id' => 2))
            ->execute();

        $conn
            ->insert('users_photos')
            ->values(array('user_id' => 1, 'photo_id' => 3))
            ->execute();

        $conn
            ->insert('users')
            ->values(array(
                'name'          => 'Tom Smith',
                'password'      => '123456',
                'created_at'    => '2013-05-30 13:35:42',
                'updated_at'    => '2013-05-30 13:35:42',
                'group_id'      => 1
            ))
            ->execute();

        $conn
            ->insert('users')
            ->values(array(
                'name'          => 'Chris Smith',
                'password'      => '123456',
                'created_at'    => '2013-05-30 13:35:42',
                'updated_at'    => '2013-05-30 13:35:42',
                'group_id'      => 2
            ))
            ->execute();

        $conn
            ->insert('users')
            ->values(array(
                'name'          => 'Anna Smith',
                'password'      => '123456',
                'created_at'    => '2013-05-30 13:35:42',
                'updated_at'    => '2013-05-30 13:35:42',
                'group_id'      => 3
            ))
            ->execute();

        $conn
            ->insert('users')
            ->values(array(
                'name'          => 'Joe Smith',
                'password'      => '123456',
                'created_at'    => '2013-05-30 13:35:42',
                'updated_at'    => '2013-05-30 13:35:42',
                'group_id'      => 4
            ))
            ->execute();

        $conn
            ->insert('users')
            ->values(array(
                'name'          => 'Paul Smith',
                'password'      => '123456',
                'created_at'    => '2013-05-30 13:35:42',
                'updated_at'    => '2013-05-30 13:35:42',
                'group_id'      => 5
            ))
            ->execute();
        
        // Set default connection for ORMs
        \Ana\ORM::$connection = $this->connection;
    }

    public function testModelCreate()
    {
        $model = new User();

        $this->assertEquals('model-user', $model->getModelName());
        $this->assertEquals('model-user', User::modelName());
        $this->assertEquals('ana-orm', ORM::modelName());
        $this->assertEquals('users', ORM::info('model-user', 'tableName'));
    }

    public function testCreateWithWhereAndPrimaryKey()
    {
        $expected = 'SELECT `model-user`.`id` AS `id`, `model-user`.`name` AS `name`, `model-user`.`password` AS `password`, `model-user`.`created_at` AS `created_at`, `model-user`.`updated_at` AS `updated_at`, `model-user`.`group_id` AS `group_id` FROM `users` AS `model-user` WHERE `model-user`.`id` = 1 LIMIT 1';

        $model = new User(1);
        $this->assertEquals($expected, $model->getDb()->lastQuery());

        $model->reset()->clear();

        $model->where('id', '=', 1)->find();
        $this->assertEquals($expected, $model->getDb()->lastQuery());

        $model->reset()->clear();

        $model = new User(array('id' => 1));
        $this->assertEquals($expected, $model->getDb()->lastQuery());
        
        $model->reset()->clear();

        $model = new User();
        $model->where($model('primaryKey'), '=', 1)->find();
        $this->assertEquals($expected, $model->getDb()->lastQuery());

        $this->assertTrue($model->isLoaded());
    }

    public function testCreateWithWhereAndName()
    {
        $expected = "SELECT `model-user`.`id` AS `id`, `model-user`.`name` AS `name`, `model-user`.`password` AS `password`, `model-user`.`created_at` AS `created_at`, `model-user`.`updated_at` AS `updated_at`, `model-user`.`group_id` AS `group_id` FROM `users` AS `model-user` WHERE `model-user`.`name` = 'Tom' LIMIT 1";

        $model = new User(array('name' => 'Tom'));
        $this->assertEquals($expected, $model->getDb()->lastQuery());

        $model->reset()->clear();

        $model->where('name', '=', 'Tom')->find();
        $this->assertEquals($expected, $model->getDb()->lastQuery());

        $model->reset()->clear();

        $model->where('name', 'Tom')->find();
        $this->assertEquals($expected, $model->getDb()->lastQuery());
    }

    public function testCastData()
    {
        $model = $this->connection
            ->select()
            ->from('users')
            ->where('id', 1)
            ->asObject('\Model\User')
            ->execute()
            ->current();

        $this->assertTrue($model instanceof \Model\User);
        $this->assertTrue($model->isLoaded());
        $this->assertEquals('1', $model->id);
        $this->assertEquals(array('Tom', 'Smith'), $model->name);
    }

    public function testReload()
    {
        $model = new User(1);

        $this->assertTrue($model->isLoaded());
        $this->assertEquals(1, $model->id);

        $model->reload();

        $this->assertTrue($model->isLoaded());
        $this->assertEquals(1, $model->id);
    }

    public function testSerialize()
    {
        $expected = 'a:7:{s:15:"primaryKeyValue";s:1:"1";s:6:"object";a:6:{s:2:"id";s:1:"1";s:4:"name";s:9:"Tom Smith";s:8:"password";N;s:10:"created_at";s:19:"2013-05-30 13:35:42";s:10:"updated_at";s:19:"2013-05-30 13:35:42";s:8:"group_id";s:1:"1";}s:7:"changed";a:0:{}s:6:"loaded";b:1;s:5:"saved";b:0;s:7:"sorting";N;s:14:"originalValues";a:6:{s:2:"id";s:1:"1";s:4:"name";s:9:"Tom Smith";s:8:"password";N;s:10:"created_at";s:19:"2013-05-30 13:35:42";s:10:"updated_at";s:19:"2013-05-30 13:35:42";s:8:"group_id";s:1:"1";}}';

        $model = new User(1);

        $this->assertEquals($expected, $model->serialize());
    }

    public function testUnserialize()
    {
        $model = new User(1);
        $serialized = $model->serialize();

        $model = new User();
        $model->unserialize($serialized);

        $this->assertTrue($model->isLoaded());
        $this->assertEquals(1, $model->id);
    }

    public function testChanged()
    {
        $model = new User(1);
        $model->name = 'Joe';

        $this->assertTrue($model->hasChange());
        $this->assertTrue($model->hasChange('name'));
        $this->assertFalse($model->hasChange('password'));
        $this->assertEquals(array('name' => 'name'), $model->getChanged());
    }

    public function testCustomSetAndGet()
    {
        $model = new User();
        $model->name = 'Joe Smith   ';

        $this->assertEquals(array('Joe', 'Smith'), $model->name);
    }

    public function testCustomSaveAndLoad()
    {
        $model = User::factory(1);

        $this->assertEquals(null, $model->password);

        $model->password = '123456';
        $model->save();

        $password = 
            $this->connection
                ->select('password')
                ->from('users')
                ->where('id', '=', 1)
                ->execute()
                ->get('password');

        $this->assertTrue(strlen($password) === 40);
    }

    public function testSave()
    {
        $model = new User();
        $model->values(array(
            'name'      => 'Joe',
            'password'  => 'Hello',
            'group_id'  => 1
        ));

        $model->save();

        $id = $model->id;

        $model = new User($id);

        $this->assertTrue($model->isLoaded());
    }

    public function testValidation()
    {
        $model = new User();
        $model->values(array(
            'name'      => 'Joe',
            'password'  => 'Hello'
        ));

        $errors = array();

        try {
            $model->save();
        } catch (\Ana\ORM_Validation_Exception $e) {
            $errors = $e->errors(__DIR__.'/../errors');
        }

        $this->assertEquals(array('group_id' => 'Damn, it\'s empty!'), $errors);
    }

    public function testUpdate()
    {
        $model = new User(1);
        $model->name = 'Emma Smith  ';

        $model->save();
        $model->clear();
        $model->where('id', 1)->find();

        $this->assertEquals(array('Emma', 'Smith'), $model->name);
    }

    public function testDelete()
    {
        $model = new User(1);
        $model->delete();

        $this->assertEquals(null, $model->id);

        $model = new User(1);

        $this->assertFalse($model->isLoaded());
    }

    public function testBelongsTo()
    {
        $model = new User(1);

        $this->assertTrue(true, $model->group->isLoaded());
    }

    public function testHasMany()
    {
        $model = new User(1);
        $result = $model->photos->findAll();

        $this->assertTrue($result instanceof \Cabinet\DBAL\Result);
        $this->assertEquals(3, $result->count());
    }

    public function testHas()
    {
        $this->assertTrue(User::factory(1)->has('photos'));
    }

    public function testWith()
    {
        $expected = 'SELECT `group`.`id` AS `group:id`, `group`.`name` AS `group:name`, `model-user`.`id` AS `id`, `model-user`.`name` AS `name`, `model-user`.`password` AS `password`, `model-user`.`created_at` AS `created_at`, `model-user`.`updated_at` AS `updated_at`, `model-user`.`group_id` AS `group_id` FROM `users` AS `model-user` LEFT JOIN `groups` AS `group` ON (`group`.`id` = `model-user`.`group_id`) WHERE `model-user`.`id` = 1 LIMIT 1';

        $model = User::factory()->with('group')->where('id', 1)->find();

        $this->assertEquals($expected, $model->getDb()->lastQuery());
    }

    public function testCountAll()
    {
        $this->assertEquals(5, User::factory()->countAll());
    }

    public function testCreateSlug()
    {
        $this->assertEquals('Tom Smith-1', User::factory()->createSlug('name', 'Tom Smith'));
        
        User::factory()
            ->values(array(
                'name'  => 'Tom Smith-1',
                'group_id'  => 1
            ))
            ->save();

        $this->assertEquals('Tom Smith-2', User::factory()->createSlug('name', 'Tom Smith'));
    }

    public function tearDown()
    {
        //print_r($this->connection->queries());
    }
}