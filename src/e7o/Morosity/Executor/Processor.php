<?php

namespace e7o\Morosity\Executor;

use \e7o\Morosity\Loader\Loader;

class Processor implements VariableContext, Environment
{
	// Preparation
	private $values;
	
	// Extensions
	private $commandHandler = [];
	private $loader;
	
	// Additonal values
	private $stack = [];
	
	public function __construct()
	{
		$this->values = array();
	}
	
	public function setLoader(Loader $loader)
	{
		$this->loader = $loader;
	}
	
	public function getLoader(): \e7o\Morosity\Loader\Loader
	{
		return $this->loader;
	}
	
	public function setCommandHandler($forType, Handler $handler)
	{
		$this->commandHandler[$forType] = $handler;
	}
	
	public function addValue($name, $value)
	{
		$this->values[$name] = $value;
	}
	
	public function getValue($name)
	{
		return $this->values[$name];
	}
	
	public function hasValue($name)
	{
		return isset($this->values[$name]);
	}
	
	public function addValues($valuesArray)
	{
		$this->values += $valuesArray;
	}
	
	public function setValues($valuesArray)
	{
		$this->values = $valuesArray;
	}
	
	public function pushStack(array $data)
	{
		$this->stack[count($this->stack)] = $data;
	}
	
	public function popStack()
	{
		unset($this->stack[count($this->stack) - 1]);
	}
	
	public function render($template)
	{
		$executor = new DefaultExecutor($this, $this, $this->commandHandler);
		return $executor->render($template);
	}
	
	public function evaluateExpression($expression, ExecutionContext $context = null)
	{
		switch (strtolower($expression)) {
			case 'false':
				return false;
			case 'true':
				return true;
			case 'null':
				return null;
			case '':
				return '';
		}
		
		// Array?
		if ($expression[0] == '[') {
			// TODO: Improve functionality (quotation marks, sub-arrays etc.)
			$sub = substr($expression, 1, -1);
			$sub = explode(',', $sub);
			$val = [];
			foreach ($sub as $s) {
				$val[] = $this->evaluateExpression(trim($s));
			}
			return $val;
		}
		// Split params
		$i = strpos($expression, '|');
		if ($i !== false) {
			$pipeModifier = substr($expression, $i + 1);
			$expression = substr($expression, 0, $i);
		} else {
			$pipeModifier = null;
		}
		// Check some special syntax (especially stuff which could contain dots) 
		if (is_numeric($expression)) {
			$val = (int)$expression;
		} else if (is_float($expression)) {
			$val = (float)$expression;
		} else if (strlen($expression) >= 5 && substr($expression, 0, 5) == 'loop.') {
			// Loop variable, first check context
			if ($context === null) {
				throw new \Exception('Cannot access LOOP variables without context');
			}
			// Remove first five characters
			$expression = substr($expression, 5);
			// Remove _additional_ dots from string
			$dots = $this->countAndRemoveDots($expression);
			// Fetch the matching loop
			$loop = $context->getLoop($dots);
			// Check for predefined values
			$val = 0;
			switch ($expression) {
				// Current index
				case 'index':
					$val = 1;
				case 'index0':
					$val += $loop[ExecutionContext::LOOP_CURRENT_INDEX];
					break;
				// Remaining elements
				case 'revindex':
					$val = -1;
				case 'revindex0':
					$val += $loop[ExecutionContext::LOOP_COUNT] - $loop[ExecutionContext::LOOP_CURRENT_INDEX];
					break;
				// Total count of elements
				case 'length':
					$val = $loop[ExecutionContext::LOOP_COUNT] + 1;
					break;
				// Positions
				case 'first':
					$val = (bool)($loop[ExecutionContext::LOOP_CURRENT_INDEX] == 0);
					break;
				case 'last':
					$val = (bool)($loop[ExecutionContext::LOOP_CURRENT_INDEX] == $loop[ExecutionContext::LOOP_COUNT]);
					break;
				// Everything else
				default:
					// Get array
					if (!is_array($loop[ExecutionContext::LOOP_ARRAY])) {
						$val = $loop[ExecutionContext::LOOP_ARRAY];
						break;
					}
					$arr = &$loop[ExecutionContext::LOOP_ARRAY][$loop[ExecutionContext::LOOP_CURRENT_INDEX]];
					if (strlen($expression) == 0) {
						// Direct value ("LOOP."), direct access
						$val = $arr;
					} else {
						// Read out of the array
						$val = isset($arr[$expression]) ? $arr[$expression] : null;
					}
					break;
			}
		} else if (preg_match('/^([\'"]?)[0-9a-z]+\1\.\.([\'"]?)[0-9a-z]+\2$/i', $expression)) {
			// Range: 2..5 or sth like this
			$range = explode('..', $expression);
			$val = range($this->evaluateExpression($range[0]), $this->evaluateExpression($range[1]));
		} else if ($expression[0] == "'" || $expression[0] == '"') {
			$val = substr($expression, 1, -1);
		} else if (!empty($context) && (preg_match('/^[a-z0-9_.]+ *\(/i', $expression))) {
			// Macro call
			$pos = strpos($expression, '(');
			$name = trim(substr($expression, 0, $pos));
			if (!$context->hasMacro($name)) {
				throw new \Exception('Unknown macro ' . $name . ' called');
			}
			$params = substr($expression, $pos + 1);
			$params = substr($params, 0, strpos($params, ')'));
			$args = [];
			// todo: explode should care about sub-arrays etc. ;)
			foreach (explode(',', $params) as $param) {
				$args[] = $this->evaluateExpression(trim($param), $context);
			}
			$val = $context->callMacro($name, $args);
		} else {
			// Iterate through dots
			$val = null;
			foreach (explode('.', $expression) as $expressionSub) {
				$expressionSub = trim($expressionSub);
				if ($expressionSub === '') {
					$val = null; // shouldn't happen i guess
				} else if (is_array($val) && isset($val[$expressionSub])) {
					$val = $val[$expressionSub];
				} else if ($expressionSub[0] == ':') {
					// Special character
					// (todo: remove)
					switch ($expressionSub) {
						case ':pipe': $val = '|'; break;
						case ':comma': $val = ','; break;
						case ':colon': $val = ':'; break;
						case ':lbrace': $val = '{'; break;
						case ':rbrace': $val = '}'; break;
						case ':at': $val = '@'; break;
						default: $val = '';
					}
				} else {
					$found = false;
					for ($i = count($this->stack) - 1; $i >= 0; $i--) {
						if (isset($this->stack[$i][$expressionSub])) {
							$val = $this->stack[$i][$expressionSub];
							$found = true;
							break;
						}
					}
					if ($found === false) {
						if (isset($this->values[$expressionSub])) {
							// User variable, exists
							$val = $this->values[$expressionSub];
						} else {
							// Unknown variable
							$val = null;
						}
					}
				}
			}
		}
		// Post-process
		if ($pipeModifier !== null) {
			$val = $this->processParams($val, explode('|', $pipeModifier));
		}
		// Done
		return $val;
	}
	
	private function countAndRemoveDots(&$string)
	{
		for ($i = 0; $i < strlen($string); $i++) {
			if ($string[$i] != '.') {
				$string = substr($string, $i);
				return $i;
			}
		}
		$i = strlen($string);
		$string = '';
		return $i;
	}
	
	private function processParams($value, $params)
	{
		foreach ($params as $param) {
			$param = trim($param);
			if (strlen($param) == 0) {
				continue;
			}
			$param = str_getcsv($param, ',', '"');
			// Preprocess params
			for ($i = 1; $i < count($param); $i++) {
				// Use variable instead of value itself
				if ($param[$i][0] == '~') {
					$param[$i] = $this->evaluateExpression(substr($param[$i], 1));
				}
			}
			//
			switch (strtolower($param[0])) {
			// Array
				case 'array':
					/*todo: remove, ist mit ~ ausreichend implementiert?
					if (!is_numeric($param[1])) {
						$param[1] = $this->getVarValue($param[1]);
					}*/
					if (!isset($value[$param[1]])) {
						$value = '';
					} else {
						$value = $value[$param[1]];
					}
					break;
				case 'split':
				case 'explode':
					$value = explode($param[1], $value);
					break;
				case 'count':
					if (is_array($value)) {
						$value = count($value);
					} else {
						$value = 0;
					}
					break;
				case 'dump': // Debugging function
					$value = str_replace('=>', '', print_r($value, true));
					$value = str_replace('[', '<small>[', $value);
					$value = str_replace(']', ']</small>', $value);
					$value = str_replace(' ', '&nbsp;', $value);
					$value = nl2br($value);
					break;
			// String
				case 'uppercase':
					$value = strtoupper($value);
					break;
				case 'lowercase':
					$value = strtolower($value);
					break;
				case 'rot13':
					$value = str_rot13($value);
					break;
				case 'shuffle':
					$value = str_shuffle($value);
					break;
				case 'wordcount':
					$value = str_word_count($value);
					break;
				case 'len':
				case 'length':
					$value = strlen($value);
					break;
				case 'cut':
					$value = substr($value, 0, (int)$param[1]);
					break;
				case 'substr':
					if (isset($param[2])) {
						$value = substr($value, (int)$param[1], (int)$param[2]);
					} else {
						$value = substr($value, (int)$param[1]);
					}
					break;
				case 'paragraphcut':
					$pos = strpos($value, "\n", (int)$param[1] - 10);
					if ($pos !== false) {
						$value = substr($value, 0, $pos);
						break;
					}
				case 'wordcut':
					preg_match('/[[:space:]]/', $value, $captured, PREG_OFFSET_CAPTURE, (int)$param[1] - 5);
					if (count($captured) > 0 || $captured[0][1] > 10) {
						$pos = $captured[0][1];
					} else {
						$pos = (int)$param[1];
					}
					$value = substr($value, 0, $pos);
					break;
				case 'concat':
					// ToDo: Untested
					for ($i = 1; $i < count($param); $i++) {
						$value .= $param[$i];
					}
					break;
				case 'replace':
					$value = str_replace($param[1], $param[2], $value);
					break;
				case 'e':
				case 'encode':
					$value = htmlentities($value, null, 'UTF-8');
					break;
				case 'striphtml':
					$value = strip_tags($value, '<br><p>');
					break;
				case 'nl2br':
					$value = nl2br($value);
					break;
			// Math
				case 'subtract':
					if (!isset($param[1])) {
						$param[1] = 0;
					}
					$param[1] *= -1;
				case 'add':
					if (!isset($param[1])) {
						$param[1] = 0;
					}
					$value = $value + $param[1];
					break;
				case 'multiply':
					if (!isset($param[1])) {
						$param[1] = 1;
					}
					$value = $value * (double)$param[1];
					break;
				case 'divide':
					if (isset($param[1]) && is_numeric($param[1]) && $param[1] != 0) {
						$value = $value / (double)$param[1];
					} else {
						$value = 'infinity';
					}
					break;
			// Date and time
				case 'date':
					// For the moment only timestamps are supported.
					// Use default format when no specification is made
					if (empty($param[1])) {
						$param[1] = \DateTime::W3C;
					}
					$value = date($param[1], (int)$value);
					// TODO: Replace it with language-specific month name
					break;
			// Convert and format
				case 'int': $value = (int)$value; break;
				case 'hex': $value = dechex($value); break;
				case 'binary': $value = decbin($value); break;
				case 'char': $value = chr($value); break;
				case 'ord': $value = ord($value); break;
				case 'round':
					if (!isset($param[1]) || !is_numeric($param[1])) {
						$param[1] = 0;
					}
					$value = round($value, (int)$param[1]);
					break;
				case 'json':
					$value = json_encode($value);
					break;
				case 'unjson':
					$value = json_decode($value, true);
					break;
			// Other
				default:
					$getVar = $this->evaluateExpression($param[0]);
					if ($getVar instanceof \Closure) {
						$value = $getVar($value, $params);
					} else {
						$value = '(Unknown function '.$param[0].')';
					}
					break;
			}
		}
		return $value;
	}
}
