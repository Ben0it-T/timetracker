<?php
/* Copyright (c) Anuko International Ltd. https://www.anuko.com
License: See license.txt */

use Smarty\Smarty;

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT); // Report all errors except E_NOTICE and E_STRICT.
ini_set('display_errors', 'Off'); // Disable displaying errors on screen.

// Disable mysqli fatal error behaviour when using php8.1 or greater.
// See https://php.watch/versions/8.1/mysqli-error-mode
if (version_compare(phpversion(), '8.1', '>=')) {
  if (function_exists('mysqli_report'))
    mysqli_report(MYSQLI_REPORT_OFF);
  else
    die("mysqli_report function is not available."); // No point to continue as mysqli will not work.
}

define("APP_VERSION", "1.22.22.5818");
define("APP_DIR", __DIR__);
define("APP_CONFIG", APP_DIR . '/../config');
define("APP_TMP_DIR", APP_DIR . '/../var/tmp');
define("APP_LIB_DIR", APP_DIR . '/../src/lib');
define("APP_PLUGINS_DIR", APP_DIR . '/../src/plugins');
define("TEMPLATE_DIR", APP_DIR . '/../templates');            // smarty templates dir
define("SMARTY_CACHE_DIR", APP_DIR . '/../var/cache');        // smarty cache dir
define("SMARTY_COMPILE_DIR", APP_DIR . '/../var/templates');  // smarty compile dir
define("SMARTY_CONFIG_DIR", APP_DIR . '/../config');          // smarty configs dir
// Date format for database and URI parameters.
define('DB_DATEFORMAT', '%Y-%m-%d');
define('MAX_RANK', 512); // Max user rank.

require_once(APP_LIB_DIR . '/common.lib.php');

// Require the configuration file with application settings.
if (!file_exists(APP_CONFIG . '/config.php')) die ("config.php file does not exist.");
require_once(APP_CONFIG . '/config.php');

// Check whether DSN is defined.
if (!defined("DSN")) {
  die ("DSN value is not defined. Check your config.php file.");
}

if (!defined("APP_2FA_SALT")) die ("2FA_SALT not defined. Check your config.php file.");

// Depending on DSN, require either mysqli or mysql extensions.
if (strrpos(DSN, 'mysqli://', -strlen(DSN)) !== FALSE) {
  check_extension('mysqli'); // DSN starts with mysqli:// - require mysqli extension.
}
if (strrpos(DSN, 'mysql://', -strlen(DSN)) !== FALSE) {
  check_extension('mysql');  // DSN starts with mysql:// - require mysql extension.
}

// Require other extensions.
check_extension('mbstring');

// If auth params are not defined (in config.php) - initialize with an empty array.
if (!isset($GLOBALS['AUTH_MODULE_PARAMS']) || !is_array($GLOBALS['AUTH_MODULE_PARAMS'])) {
  $GLOBALS['AUTH_MODULE_PARAMS'] = array();
}

// if password hash algorithm is not defined (in config.php)
if (!defined('AUTH_DB_HASH_ALGORITHM')) define('AUTH_DB_HASH_ALGORITHM', '');

if (AUTH_DB_HASH_ALGORITHM !== '') {
  if (in_array(AUTH_DB_HASH_ALGORITHM, array("DEFAULT", "BCRYPT", "ARGON2I", "ARGON2ID"))) {
    switch (AUTH_DB_HASH_ALGORITHM) {
      case 'BCRYPT':
        define('PASSWORD_ALGORITHM', PASSWORD_BCRYPT);
        if (!defined('AUTH_DB_HASH_ALGORITHM_OPTIONS')) {
          define('AUTH_DB_HASH_ALGORITHM_OPTIONS', array(
            'cost' => 10
          ));
        }
        break;

      case 'ARGON2I':
        define('PASSWORD_ALGORITHM', PASSWORD_ARGON2I);
        if (!defined('AUTH_DB_HASH_ALGORITHM_OPTIONS')) {
          define('AUTH_DB_HASH_ALGORITHM_OPTIONS', array(
            'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
            'threads' => PASSWORD_ARGON2_DEFAULT_THREADS
          ));
        }
        break;

      case 'ARGON2ID':
        define('PASSWORD_ALGORITHM', PASSWORD_ARGON2ID);
        if (!defined('AUTH_DB_HASH_ALGORITHM_OPTIONS')) {
          define('AUTH_DB_HASH_ALGORITHM_OPTIONS', array(
            'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
            'threads' => PASSWORD_ARGON2_DEFAULT_THREADS
          ));
        }
        break;
      
      default:
        define('PASSWORD_ALGORITHM', PASSWORD_DEFAULT);
        if (!defined('AUTH_DB_HASH_ALGORITHM_OPTIONS')) {
          define('AUTH_DB_HASH_ALGORITHM_OPTIONS', array(
            'cost' => 10
          ));
        }
        break;
    }
  }
  else {
    die ("This hash algorithm is not alowed. Check your config file.");
  }
}

// AUTH_DB - Login minlength
if (!defined('AUTH_DB_LOGIN_MINLENGTH')) define('AUTH_DB_LOGIN_MINLENGTH', 5);
if (!defined('AUTH_DB_PWD_MINLENGTH')) define('AUTH_DB_PWD_MINLENGTH', 8);

// Smarty initialization.
$smarty = new Smarty;
$smarty->setUseSubDirs(false);
$smarty->setTemplateDir(TEMPLATE_DIR);
$smarty->setConfigDir(SMARTY_CONFIG_DIR);
$smarty->setCompileDir(SMARTY_COMPILE_DIR);
$smarty->setCacheDir(SMARTY_CACHE_DIR);

// Note: these 3 settings below used to be in .htaccess file. Moved them here to eliminate "error 500" problems
// with some shared hostings that do not have AllowOverride Options or AllowOverride All in their apache configurations.
// Change http cache expiration time to 1 minute.
session_cache_expire(1);

// Set lifetime for garbage collection.
if (!defined('PHPSESSID_TTL')) define('PHPSESSID_TTL', 60*60*24);
ini_set('session.gc_maxlifetime', PHPSESSID_TTL);

// Set PHP session path, if defined to avoid garbage collection interference from other scripts.
if (defined('PHP_SESSION_PATH') && realpath(PHP_SESSION_PATH)) {
  ini_set('session.save_path', realpath(PHP_SESSION_PATH));
  ini_set('session.gc_probability', 1);
}

// "tt_" prefix is to avoid sharing session with other PHP apps that do not name session.
if (!defined('SESSION_COOKIE_NAME')) define('SESSION_COOKIE_NAME', 'tt_PHPSESSID');
if (!defined('LOGIN_COOKIE_NAME')) define('LOGIN_COOKIE_NAME', 'tt_login');

// Set session cookie lifetime.
session_set_cookie_params(PHPSESSID_TTL);
if (isset($_COOKIE[SESSION_COOKIE_NAME])) {
  // Extend PHP session cookie lifetime by PHPSESSID_TTL (if defined, otherwise 24 hours) 
  // so that users don't have to re-login during this period from now. 
  setcookie(SESSION_COOKIE_NAME, $_COOKIE[SESSION_COOKIE_NAME],  time() + PHPSESSID_TTL, '/');
}

// Set session storage
if (!defined('SESSION_HANDLER')) define('SESSION_HANDLER', 'file');
if (SESSION_HANDLER === 'db') {
  import('ttSession');
  $ttSession = new ttSession();
}

// Start session
session_name(SESSION_COOKIE_NAME);
@session_start();

// Authorization.
import('Auth');
$auth = Auth::factory(AUTH_MODULE, $GLOBALS['AUTH_MODULE_PARAMS']);

// Some defines we'll need.
//
define('RESOURCE_DIR', APP_DIR.'/../translations');
define('COOKIE_EXPIRE', 60*60*24*30); // Cookies expire in 30 days.

// Status values for projects, users, etc.
define('ACTIVE', 1);
define('INACTIVE', 0);
// define('DELETED', -1); // DELETED items should have a NULL status. This allows us to have duplicate NULL status entries with existing indexes.

// Definitions for tracking mode types.
define('MODE_TIME', 0); // Tracking time only. There are no projects or tasks.
define('MODE_PROJECTS', 1); // Tracking time per projects. There are no tasks.
define('MODE_PROJECTS_AND_TASKS', 2); // Tracking time for projects and tasks.

// Definitions of types for time records.
define('TYPE_ALL', 0); // Time record can be specified with either duration or start and finish times.
define('TYPE_START_FINISH', 1); // Time record has start and finish times.
define('TYPE_DURATION', 2); // Time record has only duration, no start and finish times.

define('CHARSET', 'utf-8');

// Definitions of max counts of utf8mb4 characters for various varchar database fields.
define('MAX_NAME_CHARS', 80);
define('MAX_DESCR_CHARS', 255);
define('MAX_CURRENCY_CHARS', 7);

date_default_timezone_set(@date_default_timezone_get());

// Initialize global objects that are needed for the application.
import('html.HttpRequest');
$request = new ttHttpRequest();

import('form.ActionErrors');
$err = new ActionErrors(); // Error messages for user.
$msg = new ActionErrors(); // Notification messages (not errrors) for user.

// Create an instance of ttUser class. This gets us most of user details.
import('ttUser');
$user = new ttUser(null, $auth->getUserId());
if ($user->custom_logo) {
  if (file_exists('img/'.$user->group_id.'.png')) {
    $smarty->assign('custom_logo', 'img/'.$user->group_id.'.png');
    $smarty->assign('mobile_custom_logo', '../img/'.$user->group_id.'.png');
  }
  else {
    $user->custom_logo = 0;
  }
}
$smarty->assign('user', $user);

// Localization.
import('I18n');
$i18n = new I18n();

// Determine the language to use.
$lang = $user->lang;
if (!$lang) {
  if (defined('LANG_DEFAULT'))
    $lang = LANG_DEFAULT;

  // If we still do not have the language get it from the browser.
  if (!$lang) {
    $lang = $i18n->getBrowserLanguage();

    // Finally - English is the default.
    if (!$lang) {
      $lang = 'en';
    }
  }
}

// Load i18n file.
$i18n->load($lang);

// Assign things for smarty to use in template files.
$smarty->assign('i18n', $i18n->keys);
$smarty->assign('err', $err);
$smarty->assign('msg', $msg);

// TODO: move this code out of here to the files that use it.

// We use js/strftime.js to print dates in JavaScript (in DateField controls).
// One of our date formats (%d.%m.%Y %a) prints a localized short weekday name (%a).
// The init_js_date_locale function iniitializes Date.ext.locales array in js/strftime.js for our language
// so that we could print localized short weekday names.
//
// JavaScript usage (see http://hacks.bluesmoon.info/strftime/localisation.html).
//
// var d = new Date();
// d.locale = "fr";           // Remember to initialize locale.
// d.strftime("%d.%m.%Y %a"); // This will output a localized %a as in "31.05.2013 Ven"

// Initialize date locale for JavaScript.
init_js_date_locale();

function init_js_date_locale()
{
  global $i18n, $smarty;
  $lang = $i18n->lang;

  $days = $i18n->weekdayNames;
  $short_day_names = array();
  foreach($days as $k => $v) {
    $short_day_names[$k] = mb_substr($v, 0, 3, 'utf-8');
  }

  /*
  $months = $i18n->monthNames;
  $short_month_names = array();
  foreach ($months as $k => $v) {
    $short_month_names[$k] = mb_substr($v, 0, 3, 'utf-8');
  }
  $js = "Date.ext.locales['$lang'] = {
      a: ['" . join("', '", $short_day_names) . "'],
      A: ['" . join("', '", $days) . "'],
      b: ['" . join("', '", $short_month_names) . "'],
      B: ['" . join("', '", $months) . "'],
      c: '%a %d %b %Y %T %Z',
      p: ['', ''],
      P: ['', ''],
      x: '%Y-%m-%d',
      X: '%T'
    };"; */
  // We use %a in one of date formats. Therefore, simplified code here (instead of the above block).
  // %p is also used on the Profile page in 12-hour time format example. Note that %p is not localized.
  $js = "Date.ext.locales['$lang'] = {
      a: ['" . join("', '", $short_day_names) . "'],
      p: ['AM', 'PM']
    };";
  $smarty->assign('js_date_locale', $js);
}
