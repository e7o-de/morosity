<?php

include('vendor/autoload.php');

use \e7o\Morosity\Morosity;
use \e7o\Morosity\Loader\TestLoader;

function diff($a, $b)
{
	$a = explode("\n", $a);
	$b = explode("\n", $b);
	$diff = [];
	$maxl = 0;
	for ($i = 0; $i < count($a); $i++) if (strlen($a[$i]) > $maxl) $maxl = strlen($a[$i]);
	for ($i = 0; $i < count($b); $i++) if (strlen($b[$i]) > $maxl) $maxl = strlen($b[$i]);
	$maxl = max($maxl, 10);
	$diff[] =
		"  \x00\x1b[4m   | "
		. str_pad('Expected', $maxl, ' ', STR_PAD_RIGHT)
		. " | "
		. str_pad('Actual', $maxl, ' ', STR_PAD_RIGHT)
		. " \x00\x1b[0m"
	;
	for ($i = 0; $i < max(count($a), count($b)); $i++) {
		$ad = $a[$i] ?? '';
		$bd = $b[$i] ?? '';
		$diff[] =
			(strcmp($ad, $bd) == 0 ? '     ' : "\x00\x1b[30m\x00\x1b[47;1m   * ")
			. '| '
			. str_pad($ad, $maxl, ' ', STR_PAD_RIGHT)
			. ' | ' . str_pad($bd, $maxl + 1, ' ', STR_PAD_RIGHT)
			. "\x00\x1b[0m"
		;
	}
	return implode(PHP_EOL, $diff);
}

$templates = [
	'dummy.dummy' => '-{{ dummy }}-',
	'dummy.i' => '-{{ i }}-',
	'dummy.macro' => '{% macro dummy(i) %}-{{ i }}-{% endmacro %}',
	'dummy.imacro' => '{% macro dummy(i) %}-{{ i }}-{% endmacro %}imported',
];

$dir = dir(__DIR__);
$m = new Morosity(new TestLoader());
$m->addFunction('invertstr', function ($c) { return strtolower($c) ^ strtoupper($c) ^ $c; });
$fails = [];
echo 'Morosity specification tester' . PHP_EOL;
$pattern = '/' . ($argv[1] ?? '.') . '/i';
while ($group = $dir->read()) {
	if (is_dir(__DIR__ . '/' . $group) && $group[0] != '.') {
		$ctGroup = 0;
		$dir2 = dir(__DIR__ . '/' . $group);
		while ($feature = $dir2->read()) {
			if ($feature[0] != '.' && preg_match($pattern, $group . '/' . $feature)) {
				if ($ctGroup == 0) {
					echo PHP_EOL . ' - Testing ' . $group . PHP_EOL . '   ';
				}
				$ctGroup++;
				$all = file_get_contents(__DIR__ . '/' . $group . '/' . $feature);
				$all = explode('------------------------------------------------------------------------------', $all);
				$name = $group . '-' . $feature;
				$templates[$name] = trim($all[2]);
				$data = json_decode($all[3], true);
				if ($data === null) {
					$fails[] = [$name, '   JSON error: ' . json_last_error_msg()];
					echo '?';
				} else {
					try {
						$rendered = $m->render(
							$name,
							$data
						);
						if (trim($rendered) === trim($all[4])) {
							echo "\x00\x1b[32;1m+\x00\x1b[0m";
						} else {
							$fails[] = [$name, diff(trim($all[4]), $rendered)];
							echo "\x00\x1b[31;1mF\x00\x1b[0m";
						}
					} catch (\Exception $e) {
						$fails[] = [$name, $e->getMessage()];
						echo "\x00\x1b[33;1mE\x00\x1b[0m";
					}
				}
			}
		}
		if ($ctGroup > 0) {
			echo PHP_EOL . '   (' . $ctGroup . ' executed)' . PHP_EOL;
		}
		$dir2->close();
	}
}
$dir->close();

if (!empty($fails)) {
	echo PHP_EOL . "\x00\x1b[31;1mFOUND DIFFERENCES\x00\x1b[0m" . PHP_EOL . PHP_EOL;
	foreach ($fails as $fail) {
		echo "in \x00\x1b[37;1m" . $fail[0] . "\x00\x1b[0m:" . PHP_EOL;
		echo $fail[1] . PHP_EOL . PHP_EOL;
	}
	exit(1);
} else {
	exit(0);
}
