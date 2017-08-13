<?php

namespace e7o\Morosity\Executor;

use \e7o\Morosity\Parser\Tokenizer;

class DefaultExecutor implements ExecutionContext
{
	// Data accessor
	protected $context;
	protected $commandHandler;
	protected $environment;
	
	// Execution
	private $position;
	private $parsed;
	private $currentLoops;
	// Array for remembering line numbers to which line we have to jump back
	private $jumpBack;
	private $ifHadMatch;
	
	public function __construct(VariableContext $context, Environment $env, array $commandHandler)
	{
		$this->context = $context;
		$this->environment = $env;
		$this->commandHandler = $commandHandler;
	}
	
	/**
	 * This method renders a template. THIS METHOD IS NOT TRHEAD-SAFE OR SIMILAR.
	 * It can be executed just one at a time. Create a new instance of that object
	 * to render while other rendering is in progress.
	 */
	public function render($template)
	{
		$this->parsed = Tokenizer::parse($template);
		
		$result = '';
		$this->jumpBack = array();
		$this->currentLoops = array();
		// Set to -1 that the first increment doesn't jump after the 0 :)
		$this->position = -1;
		$this->ifHadMatch = array();
		$this->variables = [];
		$ignoreTillNextIfKeyword = array();
		$ignoreDeeperIfKeywords = 0;
		$ignoreTillNextEndfor = 0;
		$ifDeep = 0;
		$executor = null;
		$loopLimit = count($this->parsed) - 1;
		while ($this->position++ < $loopLimit) {
			$currentLine = $this->parsed[$this->position];
			
			// Something to ignore?
			// If we are in a branch we have to ignore, we can continue with
			// the next step of our loop.
			if (!isset($ignoreTillNextIfKeyword[$ifDeep])) {
				$ignoreIfMode = false;
			} else {
				$ignoreIfMode = !empty($ignoreTillNextIfKeyword) && $ignoreTillNextIfKeyword[$ifDeep];
			}
			if (
				$ignoreIfMode
				&& !is_array($currentLine)
				&& substr($currentLine, 0, 5) != '{% if'
				&& substr($currentLine, 0, 7) != '{% else'
				&& substr($currentLine, 0, 8) != '{% endif'
			) {
				continue;
			}
			if ($ignoreTillNextEndfor > 0) {
				if (!isset($currentLine)) {
				} else if (substr($currentLine, 0, 6) == '{% for') {
					$ignoreTillNextEndfor++;
				} else if (substr($currentLine, 0, 9) == '{% endfor') {
					$ignoreTillNextEndfor--;
				}
				continue;
			}
			// Check type
			if (substr($currentLine, 0, 2) == '{%') {
				$commandType = explode(' ', trim(substr($currentLine, 2)), 2);
				$commandParams = isset($commandType[1]) ? $commandType[1] : '';
				$commandType = strtolower(trim($commandType[0]));
				switch ($commandType) {
					case 'for':
						// Find current loop
						$doLoop = $this->initLoop($commandParams);
						if ($doLoop == false) {
							// Fast-forward to stuf after the loop
							$ignoreTillNextEndfor = 1;
						}
						break;
					case 'endfor':
						// Ends a loop
						// Read current loop information from stack
						end($this->currentLoops);
						$loop = &$this->currentLoops[key($this->currentLoops)];
						// Check if counter is smaller than the maximum loop count
						if ($loop[ExecutionContext::LOOP_CURRENT_INDEX] < $loop[ExecutionContext::LOOP_COUNT]) {
							// Yes: Just increase the counter and go back to start of the loop
							$loop[ExecutionContext::LOOP_CURRENT_INDEX]++;
							next($loop[ExecutionContext::LOOP_ARRAY]);
							$this->position = $loop[ExecutionContext::LOOP_JUMPBACK];
							// ... and set variables
							if (!empty($loop[ExecutionContext::LOOP_VARS][0])){
								$key = key($loop[ExecutionContext::LOOP_ARRAY]);
								$this->context->addValue($loop[ExecutionContext::LOOP_VARS][0][0], $key);
							}
							if (!empty($loop[ExecutionContext::LOOP_VARS][1])){
								$value = current($loop[ExecutionContext::LOOP_ARRAY]);
								$this->context->addValue($loop[ExecutionContext::LOOP_VARS][1][0], $value);
							}
						} else {
							// No: Clean up ...
							$this->restoreValues($this->currentLoops[key($this->currentLoops)][ExecutionContext::LOOP_VARS]);
							// ... and remove from stack
							unset($this->currentLoops[key($this->currentLoops)]);
						}
						break;
					case 'if':
						if ($ignoreIfMode) {
							// Ignore this one but count it
							$ignoreDeeperIfKeywords++;
						} else {
							// Add new IF to stack
							$ifDeep++;
							$this->ifHadMatch[$ifDeep] = false;
							$ignoreTillNextIfKeyword[$ifDeep] = false;
						}
					case 'elseif':
					case 'else':
						if ($ignoreDeeperIfKeywords <= 0) {
							// Read if any condition already matched
							$match = $this->ifHadMatch[$ifDeep];
							// Set ignore to true in advance
							$ignoreTillNextIfKeyword[$ifDeep] = true;
							// When there was never a matching in this case
							if (!$match) {
								// Process conditions
								$match = $this->checkConditions($commandParams);
								// Match :)
								if ($match) {
									// We had a match, remember this
									$this->ifHadMatch[$ifDeep] = true;
									// Don't ignore this block
									$ignoreTillNextIfKeyword[$ifDeep] = false;
								}
							}
						}
						break;
					case 'endif':
						if ($ignoreDeeperIfKeywords > 0) {
							$ignoreDeeperIfKeywords--;
						} else {
							// Remove from stack
							$this->ifHadMatch[$ifDeep] = null;
							$ignoreTillNextIfKeyword[$ifDeep] = null;
							$ifDeep--;
						}
						break;
					case 'set':
						// Define a variable/array
						$parts = explode('=', $commandParams, 2);
						$this->context->addValue(trim($parts[0]), $this->evaluateExpression(trim($parts[1])));
						break;
					case 'include':
						if (empty($executor)) {
							$executor = new DefaultExecutor($this->context, $this->environment, $this->commandHandler);
						}
						$names = $this->evaluateExpression($commandParams);
						if (!is_array($names)) {
							$names = [$names];
						}
						$template = '';
						foreach ($names as $tryName) {
							$template = $this->environment->getLoader()->load($tryName);
							if (!empty($template)) {
								break;
							}
						}
						$result .= $executor->render($template);
						break;
					default:
						if (isset($this->commandHandler[$commandType])) {
							$result .= $this->commandHandler[$commandType]
								->handleCommand($this->context, $commandType, $commandParams)
							;
						} else {
							throw new \Exception('Unknown command: ' . $commandType);
						}
				}
			} else if (substr($currentLine, 0, 2) == '{{') {
				$value = $this->evaluateExpression(trim(substr($currentLine, 2)));
				$result .= $value;
			} else if (substr($currentLine, 0, 2) == '{#') {
				// Ignore
			} else {
				// No special code, simply add it
				$result .= $currentLine;
			}
		}
		
		return $result;
	}
	
	public function getLoop($above = 0)
	{
		$this->scrollBackInArray($this->currentLoops, $above);
		return current($this->currentLoops);
	}
	
	private function scrollBackInArray(&$array, $above)
	{
		end($array);
		for ($i = 0; $i < $above; $i++) {
			prev($array);
		}
	}
	
	private function checkConditions($conditionString)
	{
		if (strlen($conditionString) == 0) {
			return true;
		}
		
		// for AND by default. TODO, needs rework, also to support operators etc.
		$mode = false;
		
		$conditions = explode(';', $conditionString);
		foreach ($conditions as $cond) {
			if ($this->checkSingleCondition($cond) == $mode) {
				return $mode;
			}
		}
		return !$mode;
	}
	
	private function checkSingleCondition($condition)
	{
		// Detect not condition
		if ($condition[0] == '!') {
			$condition = substr($condition, 1);
			$not = true;
		} else {
			$not = false;
		}
		// Check operators; we need to ensure the correct order of the operators
		// (> behind >= to prevent false detections)
		$operators = array('==', '!=', '<=', '>=', '<', '>', ' in ', ' notin ', ' is');
		$found = false;
		foreach ($operators as $op) {
			if (strpos($condition, $op) !== false) {
				$found = true;
				break;
			}
		}
		if ($found) {
			$v1 = trim(substr($condition, 0, strpos($condition, $op)));
			$v2 = trim(substr($condition, strpos($condition, $op) + strlen($op)));
		} else {
			$v1 = trim($condition);
			$op = '__has_value';
			$v2 = null;
		}
		
		if ($v1 !== null) {
			$v1 = $this->evaluateExpression($v1);
		}
		
		if ($op === 'is') {
			switch ($v2) {
				case 'empty':
					return empty($v1);
				case 'not empty':
					return !empty($v1);
				case 'odd':
					return ($v1 % 2 == 1) ^ $not;
				case 'even':
					return ($v1 % 2 == 0) ^ $not;
				case 'numeric':
					return (is_numeric($v1)) ^ $not;
				default:
					// todo, unknown comparision
			}
		} else {
			if ($v2 !== null) {
				$v2 = $this->evaluateExpression($v2);
			}
			
			switch (trim($op)) {
				case '==':
					return (bool)($v1 == $v2) ^ $not;
				case '!=':
					return (bool)($v1 != $v2) ^ $not;
				case '<=':
					return (bool)($v1 <= $v2) ^ $not;
				case '>=':
					return (bool)($v1 >= $v2) ^ $not;
				case '<':
					return (bool)($v1 < $v2) ^ $not;
				case '>':
					return (bool)($v1 > $v2) ^ $not;
				case 'in':
					if (is_array($v2)) {
						return in_array($v1, $v2) ^ $not;
					} else {
						return (strpos($v2, $v1) !== false) ^ $not;
					}
				case 'notin':
					if (is_array($v2)) {
						return !in_array($v1, $v2) ^ $not;
					} else {
						return (strpos($v2, $v1) === false) ^ $not;
					}
				case '__has_value': // Internal keyword
					$hasValue =
						is_array($v1)
						|| is_string($v1) && strlen($v1) > 0
						|| is_object($v1)
						|| is_numeric($v1)
						|| $v1 === true
					;
					return $hasValue ^ $not;
				default:
					return false ^ $not;
			}
		}
	}
	
	private function initLoop($command)
	{
		$parts = explode(' in ', $command, 2);
		
		// Get variable names
		$vars = explode(',', $parts[0]);
		switch (count($vars)) {
			case 1:
				$loopVarNames = [null, [trim($vars[0]), null]];
				break;
			case 2:
				$loopVarNames = [[trim($vars[0]), null], [trim($vars[1]), null]];
				break;
			default:
				throw new Exception('Invalid number of variables in for loop: ' . $parts[0]);
		}
		
		// Get array to loop over
		// TODO: If not set throw syntax error
		$loopArr = $this->evaluateExpression($parts[1]);
		// Converting objects if known
		if ($loopArr instanceof \PDOStatement) {
			$newLoopArr = array();
			while ($loopVal = $loopArr->fetch()) {
				$newLoopArr[] = $loopVal;
			}
			$loopArr = $newLoopArr;
		}
		
		// Not doing anything on an empty array
		if (empty($loopArr)) {
			return false;
		}
		
		// Init loop
		$this->storeVariables($loopVarNames);
		if (!is_array($loopArr) && !($loopArr instanceof \Traversable)) {
			// Unknown variable
			throw new \Exception('Bad loop initialisator: ' . $command);
		} else {
			// Set info
			reset($loopArr);
			$loopInfo = [
				self::LOOP_CURRENT_INDEX => 0,
				self::LOOP_COUNT => count($loopArr) - 1,
				self::LOOP_ARRAY => &$loopArr,
				self::LOOP_JUMPBACK => $this->position,
				self::LOOP_VARS => $loopVarNames,
			];
			if (count($loopArr) > 0) {
				if (!empty($loopVarNames[0])) {
					$this->context->addValue($loopVarNames[0][0], key($loopArr));
				}
				if (!empty($loopVarNames[1])) {
					$this->context->addValue($loopVarNames[1][0], current($loopArr));
				}
			}
		}
		// Set nesting loops information - put loop id on stack
		$this->currentLoops[] = $loopInfo;
		
		return true;
	}
	
	private function storeVariables(&$arr)
	{
		foreach ($arr as &$val) {
			if ($this->context->hasValue($val[0])) {
				$val[1] = $this->context->getValue($val[0]);
			}
		}
	}
	
	private function restoreValues(&$arr)
	{
		foreach ($arr as $val) {
			$this->context->addValue($val[0], $val[1]);
		}
	}
	
	private function evaluateExpression($expression)
	{
		return $this->context->evaluateExpression($expression, $this);
	}
}
