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
		$processor = new Executor\Processor;
		$processor->setValues($params);
		$template = $this->loader->load($file);
		return $processor->render($template);
	}
}
