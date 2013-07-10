<?php

use \Cabinet\DBAL\Db;

class MigrationTest extends PHPUnit_Framework_TestCase
{
    private $mig;
    private $connection;

    public function setUp()
    {
        $config = array(
            'driver'    => 'mysql',
            'username'  => 'root',
            'password'  => 'root',
            'database'  => 'test_database',
            'asObject'  => false
        );

        $this->mig = $mig = new \Ana\MigrationManager($config);
        $mig->setDirectory(__DIR__.'/migrations');

        $this->connection = Db::connection($config);
    }

    public function testCreateAndListAndRemove()
    {
        $mig = $this->mig;

        $migration = $mig->create('test01');

        $this->assertTrue(is_file($migration[1]));

        $list = $mig->getList();
        end($list);
        $current = key($list);

        $this->assertEquals($migration[0].'.php', $current.'.php');

        $this->assertTrue($mig->remove($migration[0]));
    }

    /**
     * @covers \Ana\MigrationManager::createSchema
     * @expectedException \Exception
     */
    public function testCreateSchema()
    {
        // Drop database & create it again & create migration table
        // This is to be sure we start from 0 point
        $this->mig->createSchema();

        $result = $this->getRecords();

        $this->assertEquals(0, $result->count());

        $result = $this->connection
            ->select()
            ->from('groups')
            ->execute();
    }

    /**
     * @covers \Ana\MigrationManager::sync
     */
    public function testSync()
    {
        // Migrate from 0
        $this->mig->sync(false);

        $this->assertEquals(2, $this->getRecords()->count());

        $this->connection->select()->from('users')->execute();
        $this->connection->select()->from('groups')->execute();
    }

    /**
     * @covers \Ana\MigrationManager::up
     */
    public function testUpButWeCant()
    {
        $result = $this->mig->up(false);

        $this->assertEquals('Migrations are up to date.', $result);
    }

    /**
     * @covers \Ana\MigrationManager::up
     */
    public function testUp()
    {
        // Drop database & create it again & create migration table
        $this->mig->createSchema();

        try {
            $result = $this->connection
                        ->select()
                        ->from('users')
                        ->execute();

            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->assertFalse(false);
        }

        $result = $this->mig->up(false);

        $this->assertEquals('Migration done.', $result);

        $result = $this->getRecords();
        $this->assertEquals(1, $result->count());

        $current = $result->current();
        $this->assertEquals('1372750870_create_groups_table', $current['name']);
    }

    /**
     * @covers \Ana\MigrationManager::down
     */
    public function testDownButCant()
    {
        // Drop database & create it again & create migration table
        $this->mig->createSchema();

        $result = $this->mig->down(false);

        $this->assertEquals('Can\'t go down.', $result);
    }

    /**
     * @covers \Ana\MigrationManager::migration
     */
    public function testMigration()
    {
        $this->mig->migrate(\Ana\MigrationManager::SYNC, false);

        $this->assertEquals(2, $this->getRecords()->count());
    }

    /**
     * @covers \Ana\MigrationManager::down
     */
    public function testDown()
    {
        $result = $this->mig->down(false);

        $this->assertEquals('Migration done.', $result);

        $result = $this->mig->down(false);

        $this->assertEquals('Migration done.', $result);        
    }

    private function getRecords()
    {
        return
            $result = $this->connection
                ->select()
                ->from($this->mig->getTableName())
                ->execute();
    }
}