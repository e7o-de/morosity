<?php

include('tmp_autoloader.php');

use \e7o\Morosity\Morosity;

$test = <<<TEST
	{% for i in var %}
		{# random comment #}
		{% for j in i %}
			{{ j.value }}
			{% for k in j.sub %}
				{{ k }}
			{% endfor %}
		{% endfor %}
	{% endfor %}
TEST;

$templates = [
	'measure' => $test,
];

$start = microtime(true);

$m = new Morosity(new TestLoader());
$ct = 0;
while (microtime(true) - $start < 10.) {
	$ct++;
	$m->render(
		'measure',
		[
			'var' => [
				[
					'sub' => [1, 2, 3, 4],
					'value' => 'a',
				],
				[
					'sub' => [5, 6, 7, 8, 9],
					'value' => 'b',
				],
				[
					'sub' => ['hello world', 'something else'],
					'value' => 'c',
				],
				[
					'sub' => ['la la la la la'],
					'value' => 'd',
				],
			],
		]
	);
}
$time = microtime(true) - $start;
echo "Processed $ct templates in $time seconds\n";