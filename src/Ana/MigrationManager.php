<?php

namespace Ana;

use \Cabinet\DBAL\Db;

class MigrationManager
{
    public static $template = 'migration.class.template';
    protected $directoryPath = '';
    protected $migrationInheritanceClass = '\Ana\Migration';


    public function __construct()
    {
        $this->migrationInheritanceClassPath = __DIR__.'/';
    }

    /**
     * Sets path to directory with migrations.
     * 
     * @param string $path Path to directory which contains migrations.
     * @return $this
     * @throws \InvalidArgumentException If Directory not exists.
     */
    public function setDirectory($path)
    {
        if ( ! is_dir($path)) {
            throw new \InvalidArgumentException(sprintf(
                'Specified directory %s does not exists.', $path));
        }

        $this->directoryPath = rtrim($path, '/').'/';

        return $this;
    }

    public function setMigrationsInheritance($class)
    {
        $this->migrationInheritanceClass = $class;

        return $this;
    }

    public function create($name, $time = null)
    {
        if ( ! preg_match('/^[-_a-z0-9]+$/', $name)) {
            throw new \InvalidArgumentException(
                'Migration name contains disallowed chars. Use only -_a-z0-9');
        }

        if ($time === null) {
            $time = time();
        }

        $name = $time.'_'.$name;
        $fullPath = $this->directoryPath.$name.'.php';

        if (is_file($fullPath)) {
            throw new \InvalidArgumentException(
                'Unit with this name and current timestamp already exists.');
        }

        $tmpl = strtr(file_get_contents(static::$template), array(
                ':createdAt'    => date('r ', $time),
                ':extends'      => $this->migrationInheritanceClass,
                ':className'    => $name
            ));

        file_put_contents($fullPath, $tmpl);

        return array($name, $fullPath);
    }

    public function remove($name)
    {
        $path = $this->directoryPath.$name.'.php';

        if ( ! is_file($path)) {
            throw new \InvalidArgumentException(sprintf(
                'Specified migration "%s" does not exists.', $path));
        }

        return unlink($path);
    }

    public function getList()
    {
        $arr = array();

        foreach (scandir($this->directoryPath) as $file) {

            $path = $this->directoryPath.$file;
            $ext = substr($file, -3);

            if ($file == '.' or $file == '..' or ! is_file($path) or $ext != 'php') {
                continue;
            }

            $arr[$file] = $path;
        }

        return $arr;
    }
}