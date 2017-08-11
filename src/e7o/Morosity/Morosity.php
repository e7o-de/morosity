<?php

namespace e7o\Morosity;

class Morosity
{
	private $loader;
	
	public function __construct($loader)
	{
		$this->loader = $loader;
	}
	
	public function render(string $file, array $params = [])
	{
		// Very very basic implementation, even whitespaces are important ;)
		$template = $this->loader->load($file);
		foreach ($params as $key => $value) {
			$template = str_replace('{{ ' . $key . ' }}', $value, $template);
		}
		return $template;
	}
}
