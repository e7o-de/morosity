<?php

// TODO: Use composer, include this verification in unit tests etc.
spl_autoload_register(function ($class) {
    $prefix = '';
	$base_dir = __DIR__ . '/../src/';
	$len = strlen($prefix);
	$relative_class = substr($class, $len);
	$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
	if (file_exists($file)) {
		require $file;
		return;
	}
});

class TestLoader
{
	public function load($name)
	{
		global $templates;
		return $templates[$name];
	}
}