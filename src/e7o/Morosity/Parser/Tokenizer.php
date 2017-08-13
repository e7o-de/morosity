<?php

namespace e7o\Morosity\Parser;

class Tokenizer implements \ArrayAccess, \Countable
{
	private $parsed;
	
	public function __construct(&$tmpl)
	{
		$this->parse($tmpl);
	}
	
	private function parse(&$tmpl)
	{
		$this->parsed = [];
		
		$i = 0;
		$oldi = 0;
		while (true) {
			// Find next syntax element (like "{%" )
			$i = $this->findNextToken($tmpl, $i);
			// Not found?
			if ($i === false) {
				// Done - last piece to array
				$this->parsed[] = substr($tmpl, $oldi);
				break;
			}
			// Find closing tag
			switch (substr($tmpl, $i, 2)) {
				case '{%': $toClose = '%}'; break;
				case '{{': $toClose = '}}'; break;
				case '{#': $toClose = '#}'; break;
			}
			$j = strpos($tmpl, $toClose, $i);
			if ($j > $i) {
				// Closing tag found, push both parts to array
				$this->parsed[] = substr($tmpl, $oldi, $i - $oldi);
				$this->parsed[] = substr($tmpl, $i, $j - $i);
				// Remember positions
				$i = $j + 2;
				$oldi = $i;
			} else {
				// Parse error.
				throw new Exception('Missing end tag around #' . $i);
			}
		}
	}
	
	private function findNextToken(&$string, $start)
	{
		while (true) {
			$i = strpos($string, '{', $start);
			
			if ($i === false) {
				return false;
			}
			
			$next = substr($string, $i + 1, 1);
			if ($next == '%' || $next == '{' || $next == '#') {
				$next = substr($string, $i + 2, 1);
				if ($next == '{' || $next == '%' || $next == '#') {
					// Special case e. g. JS object: {{%LOOP%}...bla...}
					$i++;
				}
				return $i;
			}
			
			$start = $i + 1;
		}
	}
	
	public function offsetExists($offset)
	{
		return isset($this->parsed[$offset]);
	}
	
	public function offsetGet($offset)
	{
		return $this->parsed[$offset];
	}
	
	public function offsetSet($offset, $value)
	{
		throw new Exception('Manipulation not allowed');
	}
	
	public function offsetUnset($offset)
	{
		// Same as offsetSet
		throw new Exception('Manipulation not allowed');
	}
	
	public function count()
	{
		return count($this->parsed);
	}
}
