<?php

class MigrationTest extends PHPUnit_Framework_TestCase
{
    public function testCreateAndListAndRemove()
    {
        $mig = new \Ana\MigrationManager();
        $mig
            ->setDirectory(__DIR__.'/migrations');

        $migration = $mig->create('test01');

        $this->assertTrue(is_file($migration[1]));


        $list = $mig->getList();
        $current = key($list);

        $this->assertEquals($migration[0].'.php', $current);

        $this->assertTrue($mig->remove($migration[0]));
    }


}