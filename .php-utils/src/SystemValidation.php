<?php

namespace GuySartorelli\DdevPhpUtils;

final class SystemValidation
{
    public static function validate()
    {
        if (PHP_VERSION_ID < 80100) {
            echo 'This command requires at least PHP 8.1 and you are running ' . PHP_VERSION
             . ', please upgrade PHP. Aborting.' . PHP_EOL;
            exit(1);
        }
    }
}