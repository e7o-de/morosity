<?php

include('tmp_autoloader.php');

use \e7o\Morosity\Morosity;

$templates = [
	'dummy.dummy' => '-{{ dummy }}-',
	'dummy.i' => '-{{ i }}-',
	'dummy.macro' => '{% macro dummy(i) %}-{{ i }}-{% endmacro %}',
];

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
				$data = json_decode($all[3], true);
				if ($data === null) {
					$fails[] = [$name, json_last_error_msg()];
					echo '?';
				} else {
					try {
						$rendered = $m->render(
							$name,
							$data
						);
						if (trim($rendered) === trim($all[4])) {
							echo '.';
						} else {
							$fails[] = [$name, trim($all[4]), $rendered];
							echo 'F';
						}
					} catch (\Exception $e) {
						$fails[] = [$name, $e->getMessage()];
						echo 'E';
					}
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