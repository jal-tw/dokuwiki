<?php
if(!class_exists('PHPUnit_Framework_TestCase')) {
    /**
     * phpunit 5/6 compatibility
     */
    class PHPUnit_Framework_TestCase extends PHPUnit\Framework\TestCase {
        /**
         * setExpectedException is deprecated in PHPUnit 6
         *
         * @param string $class
         * @param null|string $message
         */
        public function setExpectedException($class, $message=null) {
            $this->expectException($class);
            if(!is_null($message)) {
                $this->expectExceptionMessage($message);
            }
        }
    }
}

/**
 * Helper class to provide basic functionality for tests
 *
 * @uses PHPUnit_Framework_TestCase and thus PHPUnit 5.7+ is required
 */
abstract class DokuWikiTest extends PHPUnit_Framework_TestCase {

    /**
     * tests can override this
     *
     * @var array plugins to enable for test class
     */
    protected $pluginsEnabled = array();

    /**
     * tests can override this
     *
     * @var array plugins to disable for test class
     */
    protected $pluginsDisabled = array();

    /**
     * Setup the data directory
     *
     * This is ran before each test class
     */
    public static function setUpBeforeClass() {
        // just to be safe not to delete something undefined later
        if(!defined('TMP_DIR')) die('no temporary directory');
        if(!defined('DOKU_TMP_DATA')) die('no temporary data directory');

        self::setupDataDir();
        self::setupConfDir();
    }

    /**
     * Reset the DokuWiki environment before each test run. Makes sure loaded config,
     * language and plugins are correct.
     *
     * @throws Exception if plugin actions fail
     * @return void
     */
    public function setUp() {

        // reload config
        global $conf, $config_cascade;
        $conf = array();
        foreach (array('default','local','protected') as $config_group) {
            if (empty($config_cascade['main'][$config_group])) continue;
            foreach ($config_cascade['main'][$config_group] as $config_file) {
                if (file_exists($config_file)) {
                    include($config_file);
                }
            }
        }

        // reload license config
        global $license;
        $license = array();

        // load the license file(s)
        foreach (array('default','local') as $config_group) {
            if (empty($config_cascade['license'][$config_group])) continue;
            foreach ($config_cascade['license'][$config_group] as $config_file) {
                if(file_exists($config_file)){
                    include($config_file);
                }
            }
        }
        // reload some settings
        $conf['gzip_output'] &= (strpos($_SERVER['HTTP_ACCEPT_ENCODING'],'gzip') !== false);

        if($conf['compression'] == 'bz2' && !DOKU_HAS_BZIP) {
            $conf['compression'] = 'gz';
        }
        if($conf['compression'] == 'gz' && !DOKU_HAS_GZIP) {
            $conf['compression'] = 0;
        }
        // make real paths and check them
        init_paths();
        init_files();

        // reset loaded plugins
        global $plugin_controller_class, $plugin_controller;
        /** @var Doku_Plugin_Controller $plugin_controller */
        $plugin_controller = new $plugin_controller_class();

        // disable all non-default plugins
        global $default_plugins;
        foreach ($plugin_controller->getList() as $plugin) {
            if (!in_array($plugin, $default_plugins)) {
                if (!$plugin_controller->disable($plugin)) {
                    throw new Exception('Could not disable plugin "'.$plugin.'"!');
                }
            }
        }

        // disable and enable configured plugins
        foreach ($this->pluginsDisabled as $plugin) {
            if (!$plugin_controller->disable($plugin)) {
                throw new Exception('Could not disable plugin "'.$plugin.'"!');
            }
        }
        foreach ($this->pluginsEnabled as $plugin) {
            /*  enable() returns false but works...
            if (!$plugin_controller->enable($plugin)) {
                throw new Exception('Could not enable plugin "'.$plugin.'"!');
            }
            */
            $plugin_controller->enable($plugin);
        }

        // reset event handler
        global $EVENT_HANDLER;
        $EVENT_HANDLER = new Doku_Event_Handler();

        // reload language
        $local = $conf['lang'];
        trigger_event('INIT_LANG_LOAD', $local, 'init_lang', true);

        global $INPUT;
        $INPUT = new Input();
    }

    /**
     * Reinitialize the data directory for this class run
     */
    public static function setupDataDir() {
        // remove any leftovers from the last run
        if(is_dir(DOKU_TMP_DATA)) {
            // clear indexer data and cache
            idx_get_indexer()->clear();
            TestUtils::rdelete(DOKU_TMP_DATA);
        }

        // populate default dirs
        TestUtils::rcopy(TMP_DIR, __DIR__ . '/../data/');
    }

    /**
     * Reinitialize the conf directory for this class run
     */
    public static function setupConfDir() {
        $defaults = [
            'acronyms.conf',
            'dokuwiki.php',
            'entities.conf',
            'interwiki.conf',
            'license.php',
            'manifest.json',
            'mediameta.php',
            'mime.conf',
            'plugins.php',
            'plugins.required.php',
            'scheme.conf',
            'smileys.conf',
            'wordblock.conf'
        ];

        // clear any leftovers
        if(is_dir(DOKU_CONF)) {
            TestUtils::rdelete(DOKU_CONF);
        }
        mkdir(DOKU_CONF);

        // copy defaults
        foreach($defaults as $file) {
            copy(DOKU_INC . '/conf/' . $file, DOKU_CONF . $file);
        }

        // copy test files
        TestUtils::rcopy(TMP_DIR, __DIR__ . '/../conf');
    }

    /**
     * Waits until a new second has passed
     *
     * The very first call will return immeadiately, proceeding calls will return
     * only after at least 1 second after the last call has passed.
     *
     * When passing $init=true it will not return immeadiately but use the current
     * second as initialization. It might still return faster than a second.
     *
     * @param bool $init wait from now on, not from last time
     * @return int new timestamp
     */
    protected function waitForTick($init = false) {
        static $last = 0;
        if($init) $last = time();
        while($last === $now = time()) {
            usleep(100000); //recheck in a 10th of a second
        }
        $last = $now;
        return $now;
    }

    /**
     * Allow for testing inaccessible methods (private or protected)
     *
     * This makes it easier to test protected methods without needing to create intermediate
     * classes inheriting and changing the access.
     *
     * @link https://stackoverflow.com/a/8702347/172068
     * @param object $obj Object in which to call the method
     * @param string $func The method to call
     * @param array $args The arguments to call the method with
     * @return mixed
     * @throws ReflectionException when the given obj/func does not exist
     */
    protected static function callInaccessibleMethod($obj, $func, array $args) {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($func);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}
