<?php
define('phlo', '1.0β');

define('cli', !isset($_SERVER['REQUEST_METHOD']));
define('async', 'phlo' === ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? null));
define('method', cli ? 'CLI' : $_SERVER['REQUEST_METHOD']);
define('jsonFlags', JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

define('br', '<br>');
define('bs', '\\');
define('bt', '`');
define('colon', ':');
define('comma', ',');
define('cr', "\r");
define('dash', '-');
define('dot', '.');
define('dq', '"');
define('eq', '=');
define('lf', "\n");
define('nl', cr.lf);
define('perc', '%');
define('pipe', '|');
define('qm', '?');
define('semi', ';');
define('slash', '/');
define('space', ' ');
define('sq', '\'');
define('tab', "\t");
define('us', '_');
define('void', '');
