<?php
/**
 * This file was originally written by Chris Petersen for several different open
 * source projects.  It is distrubuted under the GNU General Public License.
 * I (Chris Petersen) have also granted a special LGPL license for this code to
 * several companies I do work for on the condition that these companies will
 * release any changes to this back to me and the open source community as GPL,
 * thus continuing to improve the open source version of the library.  If you
 * would like to inquire about the status of this arrangement, please contact
 * me personally.
 *
 * ---
 *
 * The main Database superclass.  The primary function of this class is to act
 * as a constructor for various engine-specific subclasses.  It is intended to
 * work somewhat like perl's DBI library.
 *
 * Do not create any instances of this class.  Instead, use the public
 * constructor wrapper Database::connect() so that it can determine the correct
 * database engine subclass and return it to you.
 *
 * Subclasses are expected to define a prepare() method, along with a matching
 * execute() method in the query class that prepare() returns.  They must also
 * define _errstr() and _errno() methods to return the appropriate database
 * engine error string/number.
 *
 * @url         $URL$
 * @date        $Date$
 * @version     $Revision$
 * @author      $Author$
 * @copyright   Silicon Mechanics
 * @license     GPL (LGPL for SiMech)
 *
 * @package     MythWeb
 * @subpackage  Database
 *
/**/

/**
 * Abstract superclass for all database connection types.  This also defines the
 * Database::connect() function that handles creating instances of the
 * appropriate database handle for the requested database engine.
/**/
class Database {

/** @var resource   Resource handle for this database connection */
    var $dbh;

/** @var string     A full error message generated by the coder */
    var $error;

/** @var string     The database-generated error string */
    var $err;

/** @var int        The database-generated error number */
    var $errno;

/** @var resource   The last statement handle created by this object */
    var $last_sh;

/** @var bool       This controls if the mysql query errors are fatal or just stored in the mysql error string */
    var $fatal_errors = true;

/**
 * @var string      The regular expression used to see if a LIMIT statement
 *                  exists within the current query.  It is used entirely in
 *                  a read-only context.
/**/
    var $limit_regex = '/((.*)\sLIMIT\s+\d+(?:\s*(?:,|OFFSET)\s*\d+)?)?
                           ((?:\s+PROCEDURE\s+\w+\(.+?\))?
                            (?:\s+FOR\s+UPDATE)?
                            (?:\s+LOCK\s+IN\s+SHARE\s+MODE)?
                           )
                           \s*$/xe';

/**
 * @var string      The regular expression used to add a LIMIT statement to
 *                  queries that will benefit from one.
/**/
    var $limit_regex_replace = '"$2"." LIMIT 1 "."$3"';

/******************************************************************************/

/**
 * Legacy constructor to catch things that the abstract classification won't
/**/
    function Database() {
        trigger_error('The Database class should never be created as an object.  Use Database::connect() instead.', E_USER_ERROR);
    }

/**
 * This takes the place of a database constructor.  It should be called directly
 * without an object as:
 *
 *      Database::connect(....)
 *
 * This assumes that you are either using a class autoloader (php5) or have
 * already require_once'd the appropriate database engine file
 * (eg. Database_mysql.php).
 *
 * @param string $db_name   Name of the database we're connecting to
 * @param string $login     Login name to use when connecting
 * @param string $password  Password to use when connecting
 * @param string $server    Database server to connect to           (default: localhost)
 * @param string $port      Port or socket address to connect to
 * @param string $engine    Database engine to use                  (default: mysql_detect)
 * @param array  $options   Hash of var=>value pairs of server options for
 *                          engines that support them
 *
 * @return object           Database subclass based on requested $engine
/**/
    function &connect($db_name, $login, $password, $server='localhost', $port=NULL, $engine='mysql_detect', $options=array()) {
    // For consistency, engine names are all lower case.
        $engine = strtolower($engine);
    // There are two versions of the mysql driver in php.  We have special
    // consideration here for people who want auto-detection.
        if ($engine == 'mysql_detect') {
            $dbh =& new Database_mysql($db_name, $login, $password, $server, $port);
        // MySQL gets some extra smarts to try to use mysqli if it's available
            if ($dbh && function_exists('mysqli_connect')) {
                $version = preg_replace('/^(\d+\.\d).*$/', '$1', $dbh->server_info());
                if ($version >= 4.1) {
                    $dbh->close();
                    $dbh =& new Database_mysqli_compat($db_name, $login, $password, $server, $port);
                }
            }
        }
    // Do our best to load the requested class
        else {
            $css_class = "Database_$engine";
            $dbh =& new $css_class($db_name, $login, $password, $server, $port, $options);
        }
    // Return
        return $dbh;
    }

/**
 * I like how in perl you can pass variables into functions in lists or arrays,
 * and they all show up to the function as one giant list.  This takes an array
 * containing scalars and arrays of scalars, and returns one cleanarray of all
 * values.
 *
 * @param mixed $args Scalar or nested array to be "flattened" into a single array.
 *
 * @return array      Single array comprised of all scalars present in $args.
/**/
    function smart_args($args) {
        $new_args = array();
    // Not an array
        if (!is_array($args))
            return array($args);
    // Loop
        foreach ($args as $arg) {
            foreach (Database::smart_args($arg) as $arg2) {
                $new_args[] = $arg2;
            }
        }
    // Return
        return $new_args;
    }

/**
 *  Calls $this->escape() on an array of strings.
 *
 *  @return string
/**/
    function escape_array($array) {
        $new = array();
        foreach ($array as $string) {
            $new[] = $this->escape($string);
        }
        return $new;
    }

/******************************************************************************/

/**
 *  Fill the error variables
 *
 *  @param string $error     The string to set the error message to.  Set to
 *                           false if you want to wipe out the existing errors.
 *  @param bool   $backtrace Include a backtrace along with the error message.
/**/
    function error($error='', $backtrace=true) {
        if ($error === false) {
            $this->err   = null;
            $this->errno = null;
            $this->error = null;
        }
        else {
            $this->err   = $this->_errstr();
            $this->errno = $this->_errno();
            $this->error = ($error ? "$error\n\n" : '')."$this->err [#$this->errno]";
            if ($backtrace)
                $this->error .= "\n\nBacktrace\n".print_r(debug_backtrace(), true);
        }
    }

/**
 *  Perform a database query and return a handle.  Usage:
 *
 *  <pre>
 *      $sh =& $db->query('SELECT * FROM foo WHERE x=? AND y=? AND z="bar\\?"',
 *                        $x_value, $y_value);
 *  </pre>
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return mixed           Statement handle for the current type of database connection
/**/
    function &query($query) {
    // Hack to get query_row and query_assoc working correctly
        $args = array_slice(func_get_args(), 1);
    // Split out sub-arrays, etc..
        $args = Database::smart_args($args);
    // Create and return a database query
        $this->last_sh =& $this->prepare($query);
        $this->last_sh->execute($args);
    // PHP 5 doesn't like us returning NULL by reference
        if (!$this->last_sh->sh)
            $this->last_sh = NULL;
        return $this->last_sh;
    }

/**
 *  Returns a single row from the database and frees the result.
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return array
/**/
    function query_row($query) {
    // Add a "LIMIT 1" if no limit was specified -- this will speed up queries at least slightly
        $query = preg_replace($this->limit_regex, $this->limit_regex_replace, $query, 1);
    // Query and return
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = $sh->fetch_row();
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns a single assoc row from the database and frees the result.
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return assoc
/**/
    function query_assoc($query) {
    // Add a "LIMIT 1" if no limit was specified -- this will speed up queries at least slightly
        $query = preg_replace($this->limit_regex, $this->limit_regex_replace, $query, 1);
    // Query and return
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = $sh->fetch_assoc();
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns a single column from the database and frees the result.
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return mixed
/**/
    function query_col($query) {
    // Add a "LIMIT 1" if no limit was specified -- this will speed up queries at least slightly
        $query = preg_replace($this->limit_regex, $this->limit_regex_replace, $query, 1);
    // Query and return
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            list($return) = $sh->fetch_row();
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns an array of all first colums returned from the specified query.
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return array
/**/
    function query_list($query) {
    // Query and return
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_array()) {
                $return[] = $row[0];
            }
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns an array of the results from the specified query.  Each result is
 *  stored in an array.
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return array
/**/
    function query_list_array($query) {
    // Query and return
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_array()) {
                $return[] = $row;
            }
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns an array of the results from the specified query.  Each result is
 *  stored in an assoc.
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return array
/**/
    function query_list_assoc($query) {
    // Query and return
        $args  = array_slice(func_get_args(), 1);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_assoc()) {
                $return[] = $row;
            }
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns an array of the results from the specified query.  Each result is
 *  stored in an array.  The array returned will be indexed by the value of the
 *  column specified by $key.
 *
 *  @param string $key      Column to use as the returned list's key
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return array
/**/
    function query_keyed_list_array($key, $query) {
    // Query and return
        $args  = array_slice(func_get_args(), 2);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_array()) {
                $return[$row[$key]] = $row;
            }
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns an array of the results from the specified query.  Each result is
 *  stored in an assoc.  The array returned will be indexed by the value of the
 *  column specified by $key.
 *
 *  @param string $key      Column to use as the returned list's key
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return array
/**/
    function query_keyed_list_assoc($key, $query) {
    // Query and return
        $args  = array_slice(func_get_args(), 2);
        $sh    = $this->query($query, $args);
        if ($sh) {
            $return = array();
            while ($row = $sh->fetch_assoc()) {
                $return[$row[$key]] = $row;
            }
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns the row count from the query and frees the result.
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return int   The number of rows affected by the requested query.
/**/
    function query_num_rows($query) {
    // Query and return
        $args = array_slice(func_get_args(), 1);
        $sh   = $this->query($query, $args);
        if ($sh) {
            $return = $sh->num_rows();
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Returns the inserted id from the query and frees the result
 *
 *  @param string $query    The query string
 *  @param mixed  $arg      Query arguments to escape and insert at ? placeholders in $query
 *  @param mixed  ...       Additional arguments
 *
 *  @return int   The insert_id generated by the requested query.
/**/
    function query_insert_id($query) {
    // Query and return
        $args = array_slice(func_get_args(), 1);
        $sh   = $this->query($query, $args);
        if ($sh) {
            $return = $this->insert_id;
            $sh->finish();
            return $return;
        }
        return null;
    }

/**
 *  Wrapper for the last query statement's insert_id method.
 *  @return int
/**/
    function insert_id() {
        return $this->last_sh->insert_id();
    }

/**
 *  Wrapper for the last query statement's affected_rows method.
 *  @return int
/**/
    function affected_rows() {
        return $this->last_sh->affected_rows();
    }

/**
 * This function and the next one control if the mysql_query throws a fatal error or not
/**/
    function enable_fatal_errors() {
        $this->fatal_errors = true;
    }

/**
 * This function disables the fatal error trigger code
/**/
    function disable_fatal_errors() {
        $this->fatal_errors = false;
    }

}

