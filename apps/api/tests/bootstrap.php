<?php

declare(strict_types=1);

// PHPUnit bootstrap. vendor/autoload.php alone does not load app/functions.php
// (Webman's config/autoload.php does that at runtime), so service code that
// calls global helpers like nowDatetime()/toIso8601() fails under tests with
// "Call to undefined function". Require it explicitly here.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/functions.php';
