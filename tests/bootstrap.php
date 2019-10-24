<?php

const FAILED_CLASSES_PATH = __DIR__ . '/../failed-classes/';

if (is_dir(FAILED_CLASSES_PATH)) {
    $di = new RecursiveDirectoryIterator(FAILED_CLASSES_PATH, FilesystemIterator::SKIP_DOTS);
    $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($ri as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
}

@mkdir(FAILED_CLASSES_PATH);

require_once __DIR__ . '/../vendor/autoload.php';
