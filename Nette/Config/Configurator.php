<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004, 2011 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Nette\Config;

use Nette,
	Nette\Caching\Cache;



/**
 * Initial system DI container generator.
 *
 * @author     David Grudl
 *
 * @property   bool $productionMode
 * @property   string $tempDirectory
 * @property-read \SystemContainer $container
 */
class Configurator extends Nette\Object
{
	/** config file sections */
	const DEVELOPMENT = 'development',
		PRODUCTION = 'production',
		AUTO = NULL,
		NONE = FALSE;

	/** @var array of function(Configurator $sender, Compiler $compiler); Occurs after the compiler is created */
	public $onCompile;

	/** @var array */
	private $params;

	/** @var array */
	private $files = array();



	public function __construct()
	{
		$this->params = $this->getDefaultParameters();
	}



	/**
	 * Set parameter %productionMode%.
	 * @param  bool
	 * @return Configurator  provides a fluent interface
	 */
	public function setProductionMode($on = TRUE)
	{
		$this->params['productionMode'] = (bool) $on;
		return $this;
	}



	/**
	 * @return bool
	 */
	public function isProductionMode()
	{
		return $this->params['productionMode'];
	}



	/**
	 * Sets path to temporary directory.
	 * @return Configurator  provides a fluent interface
	 */
	public function setTempDirectory($path)
	{
		$this->params['tempDir'] = $path;
		if (!is_dir($cacheDir = $this->getCacheDirectory())) {
			umask(0000);
			mkdir($cacheDir, 0777);
		}
		return $this;
	}



	/**
	 * Adds new parameters. The %params% will be expanded.
	 * @return Configurator  provides a fluent interface
	 */
	public function addParameters(array $params)
	{
		$this->params = $params + $this->params;
		return $this;
	}



	/**
	 * @return array
	 */
	protected function getDefaultParameters()
	{
		$trace = debug_backtrace(FALSE);
		return array(
			'appDir' => isset($trace[1]['file']) ? dirname($trace[1]['file']) : NULL,
			'wwwDir' => isset($_SERVER['SCRIPT_FILENAME']) ? dirname($_SERVER['SCRIPT_FILENAME']) : NULL,
			'productionMode' => static::detectProductionMode(),
			'consoleMode' => PHP_SAPI === 'cli',
		);
	}



	/**
	 * @return Nette\Loaders\RobotLoader
	 */
	public function createRobotLoader()
	{
		if (!($cacheDir = $this->getCacheDirectory())) {
			throw new Nette\InvalidStateException("Set path to temporary directory using setTempDirectory().");
		}
		$loader = new Nette\Loaders\RobotLoader;
		$loader->setCacheStorage(new Nette\Caching\Storages\FileStorage($cacheDir));
		$loader->autoRebuild = !$this->params['productionMode'];
		return $loader;
	}



	/**
	 * Adds configuration file.
	 * @return Configurator  provides a fluent interface
	 */
	public function addConfig($file, $section = self::AUTO)
	{
		if ($section === self::AUTO) {
			$section = $this->params['productionMode'] ? self::PRODUCTION : self::DEVELOPMENT;
		}
		$this->files[] = array($file, $section);
		return $this;
	}



	/** @deprecated */
	public function loadConfig($file, $section = NULL)
	{
		trigger_error(__METHOD__ . '() is deprecated; use addConfig(file, [section])->createContainer() instead.', E_USER_WARNING);
		return $this->addConfig($file, $section)->createContainer();
	}



	/**
	 * Returns system DI container.
	 * @return \SystemContainer
	 */
	public function createContainer()
	{
		if ($cacheDir = $this->getCacheDirectory()) {
			$cache = new Cache(new Nette\Caching\Storages\PhpFileStorage($cacheDir), 'Nette.Configurator');
			$cacheKey = array($this->params, $this->files);
			$cached = $cache->load($cacheKey);
			if (!$cached) {
				$code = $this->buildContainer($dependencies);
				$cache->save($cacheKey, $code, array(
					Cache::FILES => $this->params['productionMode'] ? NULL : $dependencies,
				));
				$cached = $cache->load($cacheKey);
			}
			Nette\Utils\LimitedScope::load($cached['file'], TRUE);

		} elseif ($this->files) {
			throw new Nette\InvalidStateException("Set path to temporary directory using setTempDirectory().");

		} else {
			Nette\Utils\LimitedScope::evaluate($this->buildContainer()); // back compatibility with Environment
		}

		$class = $this->formatContainerClass();
		$container = new $class;
		$container->initialize();
		Nette\Environment::setContext($container); // back compatibility
		return $container;
	}



	/**
	 * Build system container class.
	 * @return string
	 */
	protected function buildContainer(& $dependencies)
	{
		$loader = new Loader;
		$config = array();
		$code = "<?php\n";
		foreach ($this->files as $tmp) {
			list($file, $section) = $tmp;
			$config = Nette\Config\Helpers::merge($loader->load($file, $section), $config);
			$code .= "// source: $file $section\n";
		}
		$code .= "\n";

		$this->checkCompatibility($config);

		if (!isset($config['parameters'])) {
			$config['parameters'] = array();
		}
		$config['parameters'] += $this->params;

		$compiler = $this->createCompiler();
		$this->onCompile($this, $compiler);

		$code .= $compiler->compile($config, $this->formatContainerClass());
		$dependencies = array_merge($loader->getDependencies(), $compiler->getContainer()->getDependencies());
		return $code;
	}



	protected function checkCompatibility(array $config)
	{
		foreach (array('service' => 'services', 'variable' => 'parameters', 'variables' => 'parameters', 'mode' => 'parameters', 'const' => 'constants') as $old => $new) {
			if (isset($config[$old])) {
				throw new Nette\DeprecatedException("Section '$old' in configuration file is deprecated; use '$new' instead.");
			}
		}
		if (isset($config['services'])) {
			foreach ($config['services'] as $key => $def) {
				foreach (array('option' => 'arguments', 'methods' => 'setup') as $old => $new) {
					if (is_array($def) && isset($def[$old])) {
						throw new Nette\DeprecatedException("Section '$old' in service definition is deprecated; refactor it into '$new'.");
					}
				}
			}
		}
	}



	/**
	 * @return Compiler
	 */
	protected function createCompiler()
	{
		$compiler = new Compiler;
		$compiler->addExtension('php', new Extensions\PhpExtension)
			->addExtension('constants', new Extensions\ConstantsExtension)
			->addExtension('nette', new Extensions\NetteExtension);
		return $compiler;
	}



	protected function getCacheDirectory()
	{
		return isset($this->params['tempDir']) ? $this->params['tempDir'] . '/cache' : NULL;
	}



	public function formatContainerClass()
	{
		return 'SystemContainer';
	}



	/********************* tools ****************d*g**/



	/**
	 * Detects production mode by IP address.
	 * @return bool
	 */
	public static function detectProductionMode()
	{
		return !isset($_SERVER['REMOTE_ADDR']) || !in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1', '::1'), TRUE);
	}

}
