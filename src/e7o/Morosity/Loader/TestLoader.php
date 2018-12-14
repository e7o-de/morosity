<?php

namespace e7o\Morosity\Loader;

class TestLoader implements Loader
{
	public function load(string $name)
	{
		global $templates;
		
		if (!isset($templates[$name])) {
			return '';
		}
		
		return $templates[$name];
	}
}

