<?php

use Website\WebsiteKernel;

require_once __DIR__ . '/../../../vendor/autoload_runtime.php';

return function (array $context) {
    return new WebsiteKernel($context['APP_ENV'], (bool)$context['APP_DEBUG']);
};
