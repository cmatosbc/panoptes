<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Set default timezone
date_default_timezone_set('UTC');

// Increase default timeout for tests
ini_set('default_socket_timeout', 5);
