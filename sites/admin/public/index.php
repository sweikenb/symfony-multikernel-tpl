<?php

use Admin\AdminKernel;

require_once __DIR__ . '/../../../vendor/autoload_runtime.php';
require_once __DIR__ . '/../config/boostrap.php';

return function (array $context) {
    return new AdminKernel($context['APP_ENV'], (bool)$context['APP_DEBUG']);
};
