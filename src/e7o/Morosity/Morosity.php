<?php

namespace e7o\Morosity;

use \e7o\Morosity\Loader\Loader;
use \e7o\Morosity\Executor\Handler;

class Morosity
{
	private $processor;
	private $loader;
	
	public function __construct(Loader $loader)
	{
		$this->loader = $loader;
		$processor = new Executor\Processor;
		$processor->setLoader($loader);
		$this->processor = $processor;
	}
	
	public function setCommandHandler(string $command, Handler $handler)
	{
		$this->processor->setCommandHandler($command, $handler);
	}
	
	public function addFunction($name, \Closure $function = null)
	{
		$this->processor->addFunction($name, $function);
	}
	
	public function render(string $file, ?array $params = [])
	{
		$template = $this->loader->load($file);
		return $this->renderString($template, $params ?: []);
	}
	
	public function renderString(string $template, ?array $params = [])
	{
		$this->processor->setValues($params ?: []);
		return $this->processor->render($template);
	}
}
