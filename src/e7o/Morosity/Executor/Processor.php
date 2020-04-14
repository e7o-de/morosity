<?php

namespace e7o\Morosity\Executor;

use \e7o\Morosity\Loader\Loader;
use \e7o\Morosity\Parser\ParamParser;

class Processor implements VariableContext, Environment
{
	// Preparation
	private $values;
	
	// Extensions
	private $commandHandler = [];
	private $loader;
	private $functions = [];
	
	// Additonal values
	private $stack = [];
	
	public function __construct()
	{
		$this->values = [];
	}
	
	public function setLoader(Loader $loader)
	{
		$this->loader = $loader;
	}
	
	public function getLoader(): \e7o\Morosity\Loader\Loader
	{
		return $this->loader;
	}
	
	public function setCommandHandler(string $forType, Handler $handler)
	{
		$this->commandHandler[$forType] = $handler;
	}
	
	public function addFunction($name, \Closure $function = null)
	{
		$this->functions[$name] = $function;
	}
	
	public function addValue(string $name, $value)
	{
		$this->values[$name] = $value;
	}
	
	public function getValue(string $name)
	{
		return $this->values[$name];
	}
	
	public function hasValue(string $name)
	{
		return isset($this->values[$name]);
	}
	
	public function addValues(array $values)
	{
		$this->values += $values;
	}
	
	public function setValues(array $values)
	{
		$this->values = $values;
	}
	
	public function pushStack(array $data)
	{
		$this->stack[count($this->stack)] = $data;
	}
	
	public function popStack()
	{
		unset($this->stack[count($this->stack) - 1]);
	}
	
	public function render(string $template)
	{
		$executor = new DefaultExecutor($this, $this, $this->commandHandler);
		return $executor->render($template);
	}
	
	public function evaluateExpression(string $expression, ExecutionContext $context = null)
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
		
		// Split params, extra care for arrays (TODO: Remove)
		if ($expression[0] == '[') {
			// TODO: This cannot handle nested arrays yet (would be slower).
			$i = strpos($expression, ']');
			$sub = substr($expression, 1, $i - 1);
			$rest = substr($expression, $i + 1);
			$sub = ParamParser::split($sub);
			$val = [];
			foreach ($sub as $s) {
				$val[] = $this->evaluateExpression(trim($s));
			}
			if (strlen($rest) > 0) {
				$pipeModifier = ParamParser::split($rest);
				$expression = $val;
			} else {
				return $val;
			}
		} else {
			$parts = ParamParser::split($expression);
			if (count($parts) > 1) {
				$expression = array_shift($parts);
				$pipeModifier = $parts;
			} else {
				$pipeModifier = null;
			}
		}
		// Check some special syntax
		if (is_numeric($expression)) {
			$val = (int)$expression;
		} else if (is_float($expression)) {
			$val = (float)$expression;
		} else if (is_array($expression)) {
			// Just keep that
		} else if (substr($expression, 0, 5) == 'loop.') {
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
			// Function call -- Macro or internal function
			$pos = strpos($expression, '(');
			$name = trim(substr($expression, 0, $pos));
			$params = substr($expression, $pos + 1);
			$params = substr($params, 0, strrpos($params, ')'));
			$args = [];
			$params = ParamParser::split($params);
			foreach ($params as $param) {
				$args[] = $this->evaluateExpression($param, $context);
			}
			if ($context->hasMacro($name)) {
				$val = $context->callMacro($name, $args);
			} else if (!empty($this->functions[$name])) {
				$f = $this->functions[$name];
				$val = $f(...$args);
			} else if (Functions::has($name)) {
				$val = array_shift($args);
				$val = Functions::call($name, $val, $args);
			} else {
				throw new \Exception('Unknown macro/function ' . $name . ' called');
			}
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
		if (!empty($pipeModifier)) {
			$val = $this->processParams($val, $pipeModifier, $context);
		}
		// Done
		return $val;
	}
	
	private function countAndRemoveDots(string &$string)
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
	
	// TODO: Kill and integrate in evaluateExpression
	private function processParams($value, $params, ExecutionContext $context = null)
	{
		foreach ($params as $param) {
			$param = trim($param);
			if (strlen($param) == 0) {
				continue;
			}
			if (($pPos = strpos($param, '(')) !== false && $param[-1] == ')') {
				$param = substr($param, 0, $pPos) . ',' . substr($param, $pPos + 1, strlen($param) - $pPos - 2);
			}
			$param = ParamParser::split($param);
			// Preprocess params
			for ($i = 1; $i < count($param); $i++) {
				$param[$i] = $this->evaluateExpression($param[$i], $context);
			}
			// Call
			$func = strtolower(array_shift($param));
			$value = Functions::call($func, $value, $param);
		}
		return $value;
	}
}
