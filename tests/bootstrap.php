<?php

define('FAILED_CLASSES_PATH', __DIR__ . '/../failed-classes/');
define('MODEL_TEMP_PATH', sys_get_temp_dir() . '/PHPModelGeneratorTest/Models');

if (is_dir(FAILED_CLASSES_PATH)) {
    $di = new RecursiveDirectoryIterator(FAILED_CLASSES_PATH, FilesystemIterator::SKIP_DOTS);
    $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($ri as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
}

@mkdir(FAILED_CLASSES_PATH);

require_once __DIR__ . '/../vendor/autoload.php';
