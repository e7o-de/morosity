<?php

namespace e7o\Morosity;

use \e7o\Morosity\Loader\Loader;

class Morosity
{
	private $loader;
	
	public function __construct(Loader $loader)
	{
		$this->loader = $loader;
	}
	
	public function render(string $file, array $params = [])
	{
		$template = $this->loader->load($file);
		return $this->renderString($template, $params);
	}
	
	public function renderString(string $template, array $params = [])
	{
		$processor = new Executor\Processor;
		$processor->setValues($params);
		$processor->setLoader($this->loader);
		return $processor->render($template);
	}
}

