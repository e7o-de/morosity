<?php

include('vendor/autoload.php');

use \e7o\Morosity\Morosity;
use \e7o\Morosity\Loader\TestLoader;

$test = <<<TEST
	Some text before ... la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	{% for i in var %}
		{# random comment #}
		{{ long }}
		{% for j in i %}
			{{ j.value }}
			{% for k in j.sub %}
				la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
				la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
				la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
				{{ k }}
				{% include 'measure-include' %}
				{% for l in 4..10 %}
					la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
					la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
					la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
					la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
				{% endfor %}
				{% include 'measure-include' %}
			{% endfor %}
		{% endfor %}
	{% endfor %}
	Some text after .... la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
	la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la la
TEST;

$templates = [
	'measure' => $test,
	'measure-include' => '{% for j in "a".."z" %}{{ i }}{{ j }}{% endfor %}',
	'measure-include-include' => '{% for j in "a".."z" %}{{ i }}{{ j }}{% include "measure-include-include" %}{% endfor %}',
];

$start = microtime(true);

$m = new Morosity(new TestLoader());
$ct = 0;
$data = [
	'long' => str_repeat('w', 500),
	'var' => [
		[
			'sub' => [1, 2, 3, 4, 5, 6, 7, 8, 9],
			'value' => 'a',
		],
		[
			'sub' => [5, 6, 7, 8, 9, 10, 11, 12, 13],
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
];
while (microtime(true) - $start < 10.) {
	$ct++;
	$m->render('measure', $data);
}
$time = microtime(true) - $start;
$avg = round($time * 1000 / $ct, 3);
echo "Processed template $ct times in $time seconds (avg $avg ms per template)\n";
