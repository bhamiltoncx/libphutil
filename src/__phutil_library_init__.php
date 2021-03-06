<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

define('__LIBPHUTIL__', true);

/**
 * @group library
 */
function phutil_require_module($library, $module) {
  PhutilBootloader::getInstance()->loadModule($library, $module);
}

/**
 * @group library
 */
function phutil_require_source($source) {
  PhutilBootloader::getInstance()->loadSource($source);
}

/**
 * @group library
 */
function phutil_register_library($library, $path) {
  PhutilBootloader::getInstance()->registerLibrary($library, $path);
}

/**
 * @group library
 */
function phutil_register_library_map(array $map) {
  PhutilBootloader::getInstance()->registerLibraryMap($map);
}

/**
 * @group library
 */
function phutil_load_library($path) {
  PhutilBootloader::getInstance()->loadLibrary($path);
}

/**
 * @group library
 */
final class PhutilBootloader {

  private static $instance;

  private $registeredLibraries = array();
  private $libraryMaps         = array();
  private $moduleStack         = array();
  private $currentLibrary      = null;

  public static function getInstance() {
    if (!self::$instance) {
      self::$instance = new PhutilBootloader();
    }
    return self::$instance;
  }

  private function __construct() {
    // This method intentionally left blank.
  }

  public function registerLibrary($name, $path) {
    if (basename($path) != '__phutil_library_init__.php') {
      throw new PhutilBootloaderException(
        'Only directories with a __phutil_library_init__.php file may be '.
        'registered as libphutil libraries.');
    }

    $path = dirname($path);

    // Detect attempts to load the same library multiple times from different
    // locations. This might mean you're doing something silly like trying to
    // include two different versions of something, or it might mean you're
    // doing something subtle like running a different version of 'arc' on a
    // working copy of Arcanist.
    if (isset($this->registeredLibraries[$name])) {
      $old_path = $this->registeredLibraries[$name];
      if ($old_path != $path) {
        throw new PhutilLibraryConflictException($name, $old_path, $path);
      }
    }

    $this->registeredLibraries[$name] = $path;
    return $this;
  }

  public function registerLibraryMap(array $map) {
    $this->libraryMaps[$this->currentLibrary] = $map;
    return $this;
  }

  public function getLibraryMap($name) {
    if (empty($this->libraryMaps[$name])) {
      $root = $this->getLibraryRoot($name);
      $this->currentLibrary = $name;
      $okay = include $root.'/__phutil_library_map__.php';
      if (!$okay) {
        throw new PhutilBootloaderException(
          "Include of '{$root}/__phutil_library_map__.php' failed!");
      }
    }
    return $this->libraryMaps[$name];
  }

  public function getLibraryRoot($name) {
    if (empty($this->registeredLibraries[$name])) {
      throw new PhutilBootloaderException(
        "The phutil library '{$name}' has not been loaded!");
    }
    return $this->registeredLibraries[$name];
  }

  public function getAllLibraries() {
    return array_keys($this->registeredLibraries);
  }

  private function pushModuleStack($library, $module) {
    array_push($this->moduleStack, $this->getLibraryRoot($library).'/'.$module);
    return $this;
  }

  private function popModuleStack() {
    array_pop($this->moduleStack);
  }

  private function peekModuleStack() {
    return end($this->moduleStack);
  }

  public function loadLibrary($path) {
    $root = null;
    if (!empty($_SERVER['PHUTIL_LIBRARY_ROOT'])) {
      if ($path[0] != '/') {
        $root = $_SERVER['PHUTIL_LIBRARY_ROOT'];
      }
    }
    $okay = $this->executeInclude($root.$path.'/__phutil_library_init__.php');
    if (!$okay) {
      throw new PhutilBootloaderException(
        "Include of '{$path}/__phutil_library_init__.php' failed!");
    }
  }

  public function loadModule($library, $module) {
    $this->pushModuleStack($library, $module);
    phutil_require_source('__init__.php');
    $this->popModuleStack();
  }

  public function loadSource($source) {
    $base = $this->peekModuleStack();
    $okay = $this->executeInclude($base.'/'.$source);
    if (!$okay) {
      throw new PhutilBootloaderException(
        "Include of '{$base}/{$source}' failed!");
    }
  }

  public function moduleExists($library, $module) {
    $path = $this->getLibraryRoot($library);
    return @file_exists($path.'/'.$module.'/__init__.php');
  }

  private function executeInclude($path) {
    // Suppress warning spew if the file does not exist; we'll throw an
    // exception instead. We still emit error text in the case of syntax errors.
    $old = error_reporting(E_ALL & ~E_WARNING);
    $okay = include_once $path;
    error_reporting($old);

    return $okay;
  }

}

/**
 * @group library
 */
final class PhutilBootloaderException extends Exception { }


/**
 * Thrown when you attempt to load two different copies of a library with the
 * same name. Trying to load the second copy of the library will trigger this,
 * and the library will not be loaded.
 *
 * This means you've either done something silly (like tried to explicitly load
 * two different versions of the same library into the same program -- this
 * won't work because they'll have namespace conflicts), or your configuration
 * might have some problems which caused two parts of your program to try to
 * load the same library but end up loading different copies of it, or there
 * may be some subtle issue like running 'arc' in a different Arcanist working
 * directory. (Some bootstrapping workflows like that which run low-level
 * library components on other copies of themselves are expected to fail.)
 *
 * To resolve this, you need to make sure your program loads no more than one
 * copy of each libphutil library, but exactly how you approach this depends on
 * why it's happening in the first place.
 *
 * @task info Getting Exception Information
 * @task construct Creating Library Conflict Exceptions
 * @group library
 */
final class PhutilLibraryConflictException extends Exception {

  private $library;
  private $oldPath;
  private $newPath;

  /**
   * Create a new library conflict exception.
   *
   * @param string The name of the library which conflicts with an existing
   *               library.
   * @param string The path of the already-loaded library.
   * @param string The path of the attempting-to-load library.
   *
   * @task construct
   */
  public function __construct($library, $old_path, $new_path) {
    $this->library = $library;
    $this->oldPath = $old_path;
    $this->newPath = $new_path;

    $message = "Library conflict! The library '{$library}' has already been ".
               "loaded (from '{$old_path}') but is now being loaded again ".
               "from a new location ('{$new_path}'). You can not load ".
               "multiple copies of the same library into a program.";

    parent::__construct($message);
  }

  /**
   * Retrieve the name of the library in conflict.
   *
   * @return string The name of the library which conflicts with an existing
   *                library.
   * @task info
   */
  public function getLibrary() {
    return $this->library;
  }


  /**
   * Get the path to the library which has already been loaded earlier in the
   * program's execution.
   *
   * @return string The path of the already-loaded library.
   * @task info
   */
  public function getOldPath() {
    return $this->oldPath;
  }

  /**
   * Get the path to the library which is causing this conflict.
   *
   * @return string The path of the attempting-to-load library.
   * @task info
   */
  public function getNewPath() {
    return $this->newPath;
  }
}

phutil_register_library('phutil', __FILE__);

phutil_require_module('phutil', 'symbols');

/**
 * @group library
 */
function __phutil_autoload($class) {
  PhutilSymbolLoader::loadClass($class);
}

spl_autoload_register('__phutil_autoload', $throw = true);
