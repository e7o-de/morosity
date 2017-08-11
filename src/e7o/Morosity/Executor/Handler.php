<?php

namespace e7o\Morosity\Executor;

interface Handler
{
	/**
	 * Method to process the given command. Should return kind of HTML or
	 * similar (depending on use case).
	 */
	public function handleCommand(VariableContext $processor, $command, $commandParams);
}
