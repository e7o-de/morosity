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

use \e7o\Morosity\Morosity;

$templates = [];
class TestLoader
{
	public function load($name)
	{
		global $templates;
		return $templates[$name];
	}
}

$ctTotal = 0;
$dir = dir(__DIR__);
$m = new Morosity(new TestLoader());
$fails = [];
echo 'Morosity specification tester' . PHP_EOL;
while ($group = $dir->read()) {
	if (is_dir(__DIR__ . '/' . $group) && $group[0] != '.') {
		$ctTotal++;
		$ctGroup = 0;
		echo PHP_EOL . 'Testing ' . $group . PHP_EOL. PHP_EOL;
		$dir2 = dir(__DIR__ . '/' . $group);
		while ($feature = $dir2->read()) {
			if ($feature[0] != '.') {
				$ctGroup++;
				$all = file_get_contents(__DIR__ . '/' . $group . '/' . $feature);
				$all = explode('------------------------------------------------------------------------------', $all);
				$name = $group . '-' . $feature;
				$templates[$name] = trim($all[2]);
				$rendered = $m->render(
					$name,
					json_decode($all[3], true)
				);
				if ($rendered === trim($all[4])) {
					echo '.';
				} else {
					$fails[] = [trim($all[4]), $rendered];
					echo 'F';
				}
			}
		}
		echo PHP_EOL . PHP_EOL . '  --> ' . $ctGroup . ' tests executed.' . PHP_EOL;
		$dir2->close();
	}
}
echo PHP_EOL . ' ==> ' . $ctTotal . ' groups tested.' . PHP_EOL;
$dir->close();

if (!empty($fails)) {
	var_dump($fails);
}