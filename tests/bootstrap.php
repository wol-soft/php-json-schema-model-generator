<?php

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

    // Suppressed: this runs after the last test has finished, so no TestCase is on the call
    // stack. Since PHPUnit 13.2.0, an unsuppressed warning raised outside of a running test
    // (e.g. a locked file that can't be deleted yet) makes PHPUnit's error handler throw
    // NoTestCaseObjectOnCallStackException, crashing the whole run instead of just leaving the
    // temp directory behind.
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
    }

    @rmdir(TEST_BASE_DIR);
});

require_once __DIR__ . '/../vendor/autoload.php';
