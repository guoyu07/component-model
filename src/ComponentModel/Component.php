<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\ComponentModel;

use Nette;


/**
 * Component is the base class for all components.
 *
 * Components are objects implementing IComponent. They has parent component and own name.
 *
 * @property-read string $name
 * @property-read IContainer|null $parent
 */
abstract class Component implements IComponent
{
	use Nette\SmartObject;

	/** @var IContainer */
	private $parent;

	/** @var string */
	private $name;

	/** @var array of [type => [obj, depth, path, is_monitored?]] */
	private $monitors = [];


	public function __construct()
	{
		[$parent, $name] = func_get_args() + [null, null];
		if ($parent !== null) {
			trigger_error(__METHOD__ . '() argument $parent is deprecated, use $parent->addComponent() instead.', E_USER_DEPRECATED);
			$parent->addComponent($this, $name);

		} elseif (is_string($name)) {
			$this->name = $name;
		}
	}


	/**
	 * Lookup hierarchy for component specified by class or interface name.
	 * @param  bool $throw   throw exception if component doesn't exist?
	 */
	final public function lookup(?string $type, bool $throw = true): ?IComponent
	{
		if (!isset($this->monitors[$type])) { // not monitored or not processed yet
			$obj = $this->parent;
			$path = self::NAME_SEPARATOR . $this->name;
			$depth = 1;
			while ($obj !== null) {
				$parent = $obj->getParent();
				if ($type ? $obj instanceof $type : $parent === null) {
					break;
				}
				$path = self::NAME_SEPARATOR . $obj->getName() . $path;
				$depth++;
				$obj = $parent; // IComponent::getParent()
				if ($obj === $this) {
					$obj = null; // prevent cycling
				}
			}

			if ($obj) {
				$this->monitors[$type] = [$obj, $depth, substr($path, 1), false];

			} else {
				$this->monitors[$type] = [null, null, null, false]; // not found
			}
		}

		if ($throw && $this->monitors[$type][0] === null) {
			throw new Nette\InvalidStateException("Component '$this->name' is not attached to '$type'.");
		}

		return $this->monitors[$type][0];
	}


	/**
	 * Lookup for component specified by class or interface name. Returns backtrace path.
	 * A path is the concatenation of component names separated by self::NAME_SEPARATOR.
	 */
	final public function lookupPath(string $type = null, bool $throw = true): ?string
	{
		$this->lookup($type, $throw);
		return $this->monitors[$type][2];
	}


	/**
	 * Starts monitoring.
	 */
	final public function monitor(string $type): void
	{
		if (empty($this->monitors[$type][3])) {
			if ($obj = $this->lookup($type, false)) {
				$this->attached($obj);
			}
			$this->monitors[$type][3] = true; // mark as monitored
		}
	}


	/**
	 * Stops monitoring.
	 */
	final public function unmonitor(string $type): void
	{
		unset($this->monitors[$type]);
	}


	/**
	 * This method will be called when the component (or component's parent)
	 * becomes attached to a monitored object. Do not call this method yourself.
	 */
	protected function attached(IComponent $obj): void
	{
	}


	/**
	 * This method will be called before the component (or component's parent)
	 * becomes detached from a monitored object. Do not call this method yourself.
	 */
	protected function detached(IComponent $obj): void
	{
	}


	/********************* interface IComponent ****************d*g**/


	final public function getName(): ?string
	{
		return $this->name;
	}


	/**
	 * Returns the container if any.
	 */
	final public function getParent(): ?IContainer
	{
		return $this->parent;
	}


	/**
	 * Sets or removes the parent of this component. This method is managed by containers and should
	 * not be called by applications
	 * @return static
	 * @throws Nette\InvalidStateException
	 * @internal
	 */
	public function setParent(?IContainer $parent, string $name = null)
	{
		if ($parent === null && $this->parent === null && $name !== null) {
			$this->name = $name; // just rename
			return $this;

		} elseif ($parent === $this->parent && $name === null) {
			return $this; // nothing to do
		}

		// A component cannot be given a parent if it already has a parent.
		if ($this->parent !== null && $parent !== null) {
			throw new Nette\InvalidStateException("Component '$this->name' already has a parent.");
		}

		// remove from parent?
		if ($parent === null) {
			$this->refreshMonitors(0);
			$this->parent = null;

		} else { // add to parent
			$this->validateParent($parent);
			$this->parent = $parent;
			if ($name !== null) {
				$this->name = $name;
			}

			$tmp = [];
			$this->refreshMonitors(0, $tmp);
		}
		return $this;
	}


	/**
	 * Is called by a component when it is about to be set new parent. Descendant can
	 * override this method to disallow a parent change by throwing an Nette\InvalidStateException
	 * @throws Nette\InvalidStateException
	 */
	protected function validateParent(IContainer $parent): void
	{
	}


	/**
	 * Refreshes monitors.
	 * @param  array|null $missing (array = attaching, null = detaching)
	 */
	private function refreshMonitors(int $depth, array &$missing = null, array &$listeners = []): void
	{
		if ($this instanceof IContainer) {
			foreach ($this->getComponents() as $component) {
				if ($component instanceof self) {
					$component->refreshMonitors($depth + 1, $missing, $listeners);
				}
			}
		}

		if ($missing === null) { // detaching
			foreach ($this->monitors as $type => $rec) {
				if (isset($rec[1]) && $rec[1] > $depth) {
					if ($rec[3]) { // monitored
						$this->monitors[$type] = [null, null, null, true];
						$listeners[] = [$this, $rec[0]];
					} else { // not monitored, just randomly cached
						unset($this->monitors[$type]);
					}
				}
			}

		} else { // attaching
			foreach ($this->monitors as $type => $rec) {
				if (isset($rec[0])) { // is in cache yet
					continue;

				} elseif (!$rec[3]) { // not monitored, just randomly cached
					unset($this->monitors[$type]);

				} elseif (isset($missing[$type])) { // known from previous lookup
					$this->monitors[$type] = [null, null, null, true];

				} else {
					$this->monitors[$type] = null; // forces re-lookup
					if ($obj = $this->lookup($type, false)) {
						$listeners[] = [$this, $obj];
					} else {
						$missing[$type] = true;
					}
					$this->monitors[$type][3] = true; // mark as monitored
				}
			}
		}

		if ($depth === 0) { // call listeners
			$method = $missing === null ? 'detached' : 'attached';
			$prev = [];
			foreach ($listeners as $item) {
				if (!in_array($item, $prev, true)) {
					$item[0]->$method($item[1]);
					$prev[] = $item;
				}
			}
		}
	}


	/********************* cloneable, serializable ****************d*g**/


	/**
	 * Object cloning.
	 */
	public function __clone()
	{
		if ($this->parent === null) {
			return;

		} elseif ($this->parent instanceof Container) {
			$this->parent = $this->parent->_isCloning();
			if ($this->parent === null) { // not cloning
				$this->refreshMonitors(0);
			}

		} else {
			$this->parent = null;
			$this->refreshMonitors(0);
		}
	}


	/**
	 * Prevents serialization.
	 */
	final public function __sleep()
	{
		throw new Nette\NotImplementedException('Object serialization is not supported by class ' . get_class($this));
	}


	/**
	 * Prevents unserialization.
	 */
	final public function __wakeup()
	{
		throw new Nette\NotImplementedException('Object unserialization is not supported by class ' . get_class($this));
	}
}
