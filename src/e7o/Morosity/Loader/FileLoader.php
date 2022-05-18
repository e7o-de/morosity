<?php

namespace e7o\Morosity\Loader;

class FileLoader implements Loader
{
	private $dirs;
	private $cache = [];
	
	public function __construct(string ...$rootDirs)
	{
		$this->dirs = [];
		foreach ($rootDirs as $dir) {
			if ($dir[-1] != '/') {
				$dir .= '/';
			}
			$this->dirs[] = $dir;
		}
	}
	
	public function load(string $file)
	{
		if (isset($this->cache[$file])) {
			return $this->cache[$file];
		}
		
		foreach ($this->dirs as $dir) {
			$possibleFilename = $dir . $file;
			if (file_exists($possibleFilename)) {
				$this->cache[$file] = file_get_contents($possibleFilename);
				return $this->cache[$file];
			}
		}
		
		throw new \Exception('Template not found: ' . $file);
	}
}

