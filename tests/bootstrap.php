<?php

ini_set('memory_limit', '256M');

define('TEST_BASE_DIR', sys_get_temp_dir() . '/PHPModelGeneratorTest_' . uniqid('', true));
define('FAILED_CLASSES_PATH', __DIR__ . '/../failed-classes/');
define('MODEL_TEMP_PATH', TEST_BASE_DIR . '/Models');

if (is_dir(FAILED_CLASSES_PATH)) {
    $di = new RecursiveDirectoryIterator(FAILED_CLASSES_PATH, FilesystemIterator::SKIP_DOTS);
    $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($ri as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
}

@mkdir(FAILED_CLASSES_PATH);

register_shutdown_function(static function (): void {
    if (!is_dir(TEST_BASE_DIR)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(TEST_BASE_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }

    rmdir(TEST_BASE_DIR);
});

require_once __DIR__ . '/../vendor/autoload.php';
