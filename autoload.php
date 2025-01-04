<?php

$classes = array(
    'IvoPetkov\EmailParser' => __DIR__ . '/src/EmailParser.php'
);

spl_autoload_register(function ($class) use ($classes): void {
    if (isset($classes[$class])) {
        require $classes[$class];
    }
});

