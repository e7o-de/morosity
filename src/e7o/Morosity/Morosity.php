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
		return $this->renderActually($template, $file, $params);
	}
	
	public function renderString(string $template, ?array $params = [])
	{
		return $this->renderActually($template, 'unknown', $params);
	}
	
	private function renderActually($templateSource, $templateName, $params)
	{
		$this->processor->setValues($params ?: []);
		return $this->processor->render($templateSource, $templateName);
	}
}

