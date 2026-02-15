<?php
if (function_exists('opcache_compile_file')){
	opcache_compile_file(__DIR__.'/constants.php');
	opcache_compile_file(__DIR__.'/phlo.php');
	opcache_compile_file(__DIR__.'/debug.php');
}
return true;
