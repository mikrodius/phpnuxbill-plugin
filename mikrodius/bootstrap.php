<?php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Mikrodius\\')) {
        $name = substr($class, 10);
        if (preg_match('/^[A-Za-z]+$/D', $name) && is_file(__DIR__ . '/' . $name . '.php')) {
            require_once __DIR__ . '/' . $name . '.php';
        }
    }
});
