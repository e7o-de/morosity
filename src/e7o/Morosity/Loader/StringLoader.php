<?php

namespace e7o\Morosity\Loader;

class StringLoader implements Loader
{
	private $templates = [];
	
	public function __construct(array $templates = [])
	{
		$this->templates = $templates;
	}
	
	public function set($key, $code)
	{
		$this->templates[$key] = $code;
	}
	
	public function load(string $name)
	{
		return $this->templates[$name] ?? '';
	}
}

