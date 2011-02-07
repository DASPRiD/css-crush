<?php
/**
 *
 * CSS Crush
 * @version 1.0
 *
 *
 * CSS pre-processor that collates a host CSS file and its imports into one,
 * applies specified CSS variables, applies search/replace macros,
 * minifies then outputs cached file.
 *
 * Validates cached file by checking the host-file and all imported files
 * and comparing the date-modified timestamps.
 *
 *
 * Example usage:
 *
 * <?php
 *   include 'CSS_Crush.php';
 *   $path_to_compiled_file = CSS_Crush::file( '/css/screen.css' );
 * ?>
 *
 * <link rel="stylesheet" type="text/css" href="<?php echo $path_to_compiled_file; ?>" media="screen" />
 *
 */
class CSS_Crush {

	private static $config;

	// Properties available to each 'file' process
	private static $options;
	private static $compileName;
	private static $compileSuffix;
	private static $variables;
	private static $literals;
	private static $literalCount;

	// Pattern matching
	private static $regex = array(
		'imports'  => '#@import +(?:url)? *\(? *([\'"])?(.+\.css)\1? *\)? *;?#',
		'variables'=> '#@variables\s+\{\s*(.*?)\s*\};?#s',
		'comments' => '#/\*(.*?)\*/#s',
	);

	// Init gets called manually post class definition
	private static $initialized = false;
	public static function init () {
		self::$initialized = true;
		self::$compileSuffix = '.crush.css';
		self::$config = new stdClass;
		self::$config->file = '.' . __CLASS__;
		self::$config->data = null;
		self::$config->path = null;
		self::$config->baseDir = null;
		self::$config->baseURL = null;

		$docRoot = $_SERVER[ 'DOCUMENT_ROOT' ];
		// workaround trailing slash issues
		$docRoot = ( substr( $docRoot, -1 ) == '/' ) ? substr( $docRoot, 0, -1 ) : $docRoot;

		if ( defined( 'STDIN' ) and $_SERVER[ 'argc' ] > 0 ) {
			// Command line
			self::log( 'Command line mode' );
			self::$cli = true;
		}
		else {
			// Running on a server
			self::log( 'Server mode' );
			self::$config->docRoot = $docRoot;
			self::$cli = false;
		}
		self::$regex = (object) self::$regex;
	}

	// Initialize config data, create config file if needed
	private static function loadConfig () {
		$config =& self::$config;
		if (
			file_exists( $config->path ) and
			$config->data  and
			$config->data[ 'originPath' ] == $config->path
		) {
			// Already loaded and config file exists in the current directory
			return;
		}
		else if ( file_exists( $config->path ) ) {
			// Load from file
			$config->data = unserialize( file_get_contents( $config->path ) );
		}
		else {
			// Create
			self::log( 'Creating config file' );
			file_put_contents( $config->path, serialize( array() ) );
			$config->data = array();
		}
	}

	private static function setPath ( $new_dir ) {
		$config =& self::$config;
		$docRoot = $config->docRoot;
		if ( strpos( $new_dir, $docRoot ) !== 0 ) {
			// Not a system path
			$new_dir = realpath( "{$docRoot}/{$new_dir}" );
		}
		if ( !file_exists( $new_dir ) ) {
			throw new Exception( __METHOD__ . ': Path "' . $new_dir . '" doesn\'t exist' );
		}
		else if ( !is_writable( $new_dir ) ) {
			self::log( 'Attempting to change permissions' );
			try {
				@chmod( $new_dir, 0755 );
			}
			catch ( Exception $e ) {
				throw new Exception( __METHOD__ . ': Directory un-writable' );
			}
			self::log( 'Permissions updated' );
		}
		$config->path = "{$new_dir}/" . $config->file;
		$config->baseDir = $new_dir;
		$config->baseURL = substr( $new_dir, strlen( $docRoot ) );
	}


	################################################################################################
	#  Public API

	/**
	 * Process host CSS file and return a new compiled file
	 *
	 * @param string $file  Absolute or relative path to the host CSS file
	 * @param mixed $options  An array of options or null
	 * @return string  The public path to the compiled file or an empty string
	 */
	public static function file ( $file, $options = null ) {
		if ( strpos( $file, self::$config->docRoot ) === 0 ) {
			// System path
			self::setPath( dirname( $file ) );
		}
		else if ( strpos( $file, '/' ) === 0 ) {
			// WWW root path
			self::setPath( dirname( self::$config->docRoot . $file ) );
		}
		else {
			// Relative path
			self::setPath( dirname( dirname( __FILE__ ) . '/' . $file ) );
		}

		self::loadConfig();
		$config =& self::$config;

		// Make basic information about the hostfile accessible
		$hostfile = new stdClass;
		$hostfile->name = basename( $file );
		$hostfile->path = "{$config->baseDir}/{$hostfile->name}";
		$hostfile->mtime = filemtime( $hostfile->path );

		if ( !file_exists( $hostfile->path ) ) {
			// If host file doesn't exist return an empty string
			return '';
		}

		self::parseOptions( $options );

		// Compiled filename we're searching for
		self::$compileName = basename( $hostfile->name, '.css' ) . self::$compileSuffix;

		// Check for a valid compiled file
		$validCompliledFile = self::validateCache( $hostfile );
		if ( is_string( $validCompliledFile ) ) {
			return $validCompliledFile;
		}

		// Compile
		$output = self::compile( $hostfile );

		// Add in boilerplate
		$output = self::getBoilerplate() . "\n{$output}";

		// Create file and return path. Return empty string on failure
		if ( file_put_contents( "{$config->baseDir}/" . self::$compileName, $output ) ) {
			return "{$config->baseURL}/" . self::$compileName;
		}
		else {
			return '';
		}
	}

	/**
	 * Clear config file and compiled files for the specified directory
	 *
	 * @param string  System path to the directory
	 */
	public static function clearCache ( $dir = '' ) {
		if ( empty( $dir ) ) {
			$dir = dirname( __FILE__ );
		}
		else if ( !file_exists( $dir ) ) {
			return;
		}
		$configPath = $dir . '/' . self::$config->file;
		if ( file_exists( $configPath ) ) {
			unlink( $configPath );
		}
		// Remove any compiled files
		$suffix = self::$compileSuffix;
		$suffixLength = strlen( $suffix );
		foreach ( scandir( $dir ) as $file ) {
			$expectedPos = strlen( $file ) - $suffixLength;
			if ( strpos( $file, $suffix ) === $expectedPos ) {
				unlink( $dir . "/{$file}" );
			}
		}
	}

	public static $cli;

	public static function cli ( $file, $options = null ) {
		// Make basic information about the hostfile accessible
		$hostfile = new stdClass;
		$hostfile->name = basename( $file );
		$hostfile->path = realpath( $file );
		$hostfile->mtime = filemtime( $hostfile->path );

		self::$config->baseDir = dirname( $hostfile->path );

		self::parseOptions( $options );
		return self::compile( $hostfile );
	}

	/**
	 * Flag for enabling debug mode
	 *
	 * @var boolean
	 */
	public static $debug = false;

	/**
	 * Print the log
	 */
	public static function log () {
		if ( !self::$debug ) {
			return;
		}
		static $log = '';
		$args = func_get_args();
		if ( !count( $args ) ) {
			// No arguments, return the log
			return $log;
		}
		else {
			$arg = $args[0];
		}
		if ( is_string( $arg ) ) {
			$log .= $arg . '<hr>';
		}
		else {
			$out = '<pre>';
			ob_start();
			print_r( $arg );
			$out .= ob_get_clean();
			$out .= '</pre>';
			$log .= $out . '<hr>';
		}
	}

	################################################################################################
	#  Internal functions

	public static function getBoilerplate () {
		return <<<TXT
/*
 *  File created by CSS Crush
 *  http://github.com/peteboere/css-crush
 */
TXT;
	}

	private static function parseOptions ( &$options ) {
		// Create default options for those not set
		$option_defaults = array(
			'macros'   => true,
			'comments' => false,
			'minify'   => true,
			'versioning' => true,
		);
		self::$options = is_array( $options ) ?
			array_merge( $option_defaults, $options ) : $option_defaults;
	}

	private static function compile ( &$hostfile ) {
		// Reset properties for current process
		self::$literals = array();
		self::$variables = array();
		self::$literalCount = 0;
		$regex = self::$regex;

		// Collate hostfile and imports
		$output = self::collateImports( $hostfile );

		// Extract literals
		$re = '#(\'|")(?:\\1|[^\1])*?\1#';
		$cb_extractStrings = self::createCallback( 'cb_extractStrings' );
		$output = preg_replace_callback( $re, $cb_extractStrings, $output );

		// Extract comments
		$cb_extractComments = self::createCallback( 'cb_extractComments' );
		$output = preg_replace_callback( $regex->comments, $cb_extractComments, $output );

		// Extract variables
		$cb_extractVariables = self::createCallback( 'cb_extractVariables' );
		$output = preg_replace_callback( $regex->variables, $cb_extractVariables, $output );

		// Search and replace variables
		$re = '#var\(\s*([A-Z0-9_-]+)\s*\)#i';
		$cb_placeVariables = self::createCallback( 'cb_placeVariables' );
		$output = preg_replace_callback( $re, $cb_placeVariables, $output);

		// Optionally apply macros
		if ( self::$options[ 'macros' ] !== false ) {
			self::applyMacros( $output );
		}

		// Optionally minify (after macros since macros may introduce un-wanted whitespace)
		if ( self::$options[ 'minify' ] !== false ) {
			self::minify( $output );
		}

		// Expand selectors
		$re = '#([^}{]+){#s';
		$cb_expandSelector = self::createCallback( 'cb_expandSelector' );
		$output = preg_replace_callback( $re, $cb_expandSelector, $output);

		// Restore all comments
		$cb_restoreLiteral = self::createCallback( 'cb_restoreLiteral' );
		$output = preg_replace_callback( '#(___c\d+___)#', $cb_restoreLiteral, $output);

		// Restore all literals
		$cb_restoreLiteral = self::createCallback( 'cb_restoreLiteral' );
		$output = preg_replace_callback( '#(___\d+___)#', $cb_restoreLiteral, $output);

		// Release un-needed memory
		self::$literals = self::$variables = null;

		return $output;
	}

	private static function validateCache ( &$hostfile ) {
		$config = self::$config;

		// Search base directory for an existing compiled file
		foreach ( scandir( $config->baseDir ) as $filename ) {

			if ( self::$compileName != $filename ) {
				continue;
			}
			// Cached file exists
			self::log( 'Cached file exists' );

			$existingfile = new stdClass;
			$existingfile->name = $filename;
			$existingfile->path = "{$config->baseDir}/{$existingfile->name}";
			$existingfile->URL = "{$config->baseURL}/{$existingfile->name}";

			// Start off with the host file then add imported files
			$all_files = array( $hostfile->mtime );

			if ( file_exists( $existingfile->path ) and isset( $config->data[ self::$compileName ] ) ) {
				// File exists and has config
				self::log( 'has config' );
				foreach ( $config->data[ $existingfile->name ][ 'imports' ] as $import_file ) {
					// Check if this is docroot relative or hostfile relative
					$root = strpos( $import_file, '/' ) === 0 ? $config->docRoot : $config->baseDir;
					$import_filepath = realpath( $root ) . "/{$import_file}";
					if ( file_exists( $import_filepath ) ) {
						$all_files[] = filemtime( $import_filepath );
					}
					else {
						// File has been moved, remove old file and skip to compile
						self::log( 'Import file has been moved, removing existing file' );
						unlink( $existingfile->path );
						return false;
					}
				}

				$existing_options = $config->data[ $existingfile->name ][ 'options' ];
				$existing_datesum = $config->data[ $existingfile->name ][ 'datem_sum' ];
				if (
						$existing_options == self::$options and
						$existing_datesum == array_sum( $all_files )
				) {
					// Files have not been modified and config is the same: return the old file
					self::log( "Files have not been modified, returning existing
						 file '{$existingfile->URL}'" );
					return $existingfile->URL .	( self::$options[ 'versioning' ] !== false  ? "?{$existing_datesum}" : '' );
				}
				else {
					// Remove old file and continue making a new one...
					self::log( 'Files has been modified, removing existing file' );
					unlink( $existingfile->path );
				}
			}
			else if ( file_exists( $existingfile->path ) ) {
				// File exists but has no config
				self::log( 'File exists but no config, removing existing file' );
				unlink( $existingfile->path );
			}
			return false;

		} // foreach
		return false;
	}

	private static function collateImports ( &$hostfile ) {
		$str = file_get_contents( $hostfile->path );
		$config =& self::$config;
		$compileName = self::$compileName;
		$regex = self::$regex;

		// Obfuscate any directives within comment blocks
		$cb_obfuscateDirectives = self::createCallback( 'cb_obfuscateDirectives' );
		$str = preg_replace_callback( $regex->comments, $cb_obfuscateDirectives, $str );

		// Initialize config object
		$config->data[ $compileName ] = array();

		// Keep track of relative paths with nested imports
		$relativeContext = '';
		// Detect whether we're leading from an absolute filepath
		$absoluteFlag = false;
		$imports_mtimes = array();
		$imports_filenames = array();
		$import = new stdClass;

		while ( preg_match( $regex->imports, $str, $match, PREG_OFFSET_CAPTURE ) ) {
			// Matched a file import statement
			$text = $match[0][0]; // Full match
			$offset = $match[0][1]; // Full match offset
			$import->name = $match[2][0];
			if ( strpos( $import->name, '/' ) === 0 ) {
				// Absolute path
				self::log('Absolute path');
				$segments = array( $config->docRoot, $import->name );
				$relativeContext = '';
				$absoluteFlag = true;
			}
			else {
				// Relative path
				self::log('Relative path');
				$root = $absoluteFlag ? $config->docRoot : $config->baseDir;
				$segments = array_filter( array( $root, $relativeContext, $import->name ) );
				if ( $absoluteFlag ) {
					$relativeContext = dirname( substr( $import->path, strlen( $config->baseDir ) + 1 ) );
				}
				$absoluteFlag = false;
			}
			$import->path = realpath( implode( '/', $segments ) );

			//self::log( 'Relative context: ' .  $relativeContext );
			//self::log( 'Import filepath: ' . $import->path );

			$preStatement  = substr( $str, 0, $offset );
			$postStatement = substr( $str, $offset + strlen( $text ) );

			if ( $import->content = @file_get_contents( $import->path ) ) {
				// Imported file exists, so construct new content

				// Add import details to config
				$imports_mtimes[] = filemtime( $import->path );
				$imports_filenames[] = $relativeContext ?
					"{$relativeContext}/{$import->name}" : $import->name;

				// Obfuscate any directives within comment blocks
				$import_content = preg_replace_callback(
					$regex->imports, $cb_obfuscateDirectives, $import->content );

				// Set relative context if there is a nested import statement
				if ( preg_match( $regex->imports, $import->content ) ) {
					$dirName = dirname( $import->name );
					if ( $dirName != '.' ) {
						$relativeContext =
							!empty( $relativeContext ) ? "{$relativeContext}/{$dirName}" : $dirName;
					}
				}
				else {
					$relativeContext = '';
				}
				// Reconstruct the main string
				$str = $preStatement . $import->content . $postStatement;
			}
			else {
				// Failed to open import, just continue with the import line removed
				self::log( 'File not found' );
				$str = $preStatement . $postStatement;
			}
		}

		$config->data[ $compileName ][ 'imports' ] = $imports_filenames;
		$config->data[ $compileName ][ 'datem_sum' ] = array_sum( $imports_mtimes ) + $hostfile->mtime;
		$config->data[ $compileName ][ 'options' ] = self::$options;

		// Need to store the current path so we can check we're using the right config path later
		$config->data[ 'originPath' ] = $config->path;

		if ( !self::$cli ) {
			// Save config changes
			file_put_contents( $config->path, serialize( $config->data ) );
		}
		self::log( $config->data );

		return $str;
	}

	private static function applyMacros ( &$str ) {
		$user_funcs = get_defined_functions();
		$csscrushs = array();
		foreach ( $user_funcs[ 'user' ] as $func ) {
			if ( strpos( $func, 'csscrush_' ) === 0 ) {
				$parts = explode( '_', $func );
				array_shift( $parts );
				$property = implode( '-', $parts );
				$csscrushs[ $property ] = $func;
			}
		}
		// Determine which macros to apply
		$opts = self::$options[ 'macros' ];
		$maclist = array();
		if ( $opts === true ) {
			$maclist = $csscrushs;
		}
		else {
			foreach ( $csscrushs as $property => $callback ) {
				if ( in_array( $property, $opts ) ) {
					$maclist[ $property ] = $callback;
				}
			}
		}
		// Loop macro list and apply callbacks
		foreach ( $maclist as $property => $callback ) {
			$wrapper = '$prop = "' . $property . '";' .
					'$result = ' . $callback . '( $prop, $match[2] );' .
					'return $result ? $match[1] . $result . $match[3] : $match[0];';
			$str = preg_replace_callback(
					'#([\{\s;]+)' . $property . '\s*:\s*' . '([^;\}]+)' . '([;\}])#',
					create_function ( '$match', $wrapper ),
					$str );
		}

		// Backwards compatable double-colon syntax for pseudo elements
		$str = preg_replace( '#\:\:(after|before|first-letter|first-line)#', ':$1', $str );

	}

	private static function minify ( &$str ) {
		// Colons cannot be globally matched safely because of pseudo-selectors etc.
		$innerbrace = create_function(
			'$match',
			'return preg_replace( \'#\s*:\s*#\', \':\', $match[0] );'
		);
		$str = preg_replace_callback( '#\{[^}]+\}#s', $innerbrace, trim( $str ) );

		$replacements = array(
			'#\s{2,}#'                          => ' ',      // Remove double spaces
			'#\s*(;|,|\{)\s*#'                  => '$1',     // Clean-up around delimiters
			'#\s*;*\s*\}\s*#'                   => '}',      // Clean-up closing braces
			'#[^}{]+\{\s*}#'                    => '',       // Strip empty statements
			'#([^0-9])0[a-zA-Z%]{2}#'           => '${1}0',  // Strip unnecessary units on zeros
			'#:(0 0|0 0 0|0 0 0 0)([;}])#'      => ':0${2}', // Collapse zero lists
			'#(background-position):0([;}])#'   => '$1:0 0$2', // Restore any overshoot
			'#([^/d])0(\.\d+)#'                 => '$1$2',   // Strip leading zeros on floats
			'#(\[)\s*|\s*(\])|(\()\s*|\s*(\))#' => '${1}${2}${3}${4}',  // Clean-up bracket internal space
			'#\s*([>~+=])\s*#'                  => '$1',     // Clean-up around combinators
			'#\#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3#i'
			                                    => '#$1$2$3', // Reduce Hex codes
		);

		$str = preg_replace(
			array_keys( $replacements ), array_values( $replacements ), $str );
	}

	################################################################################################
	#  Search / replace callbacks

	private static function createCallback ( $name ) {
		return create_function( '$m',
			'return call_user_func( array( "' . __CLASS__ . '", "' . $name . '" ), $m );' );
	}

	public static function cb_extractStrings ( $match ) {
		$label = "___" . ++self::$literalCount . "___";
		self::$literals[ $label ] = $match[0];
		return $label;
	}

	public static function cb_extractComments ( $match ) {
		$comment = $match[0];
		$flagged = strpos( $comment, '/*!' ) === 0;
		if ( self::$options[ 'comments' ] or $flagged ) {
			$label = "___c" . ++self::$literalCount . "___";
			self::$literals[ $label ] = $flagged ? '/*!' . substr( $match[1], 1 ) . '*/' : $comment;
			return $label;
		}
		return '';
	}

	public static function cb_extractVariables ( $match ) {
		$vars = preg_split( '#\s*;\s*#', $match[1], null, PREG_SPLIT_NO_EMPTY );
		foreach ( $vars as $var ) {
			$parts = preg_split( '#\s*:\s*#', $var, null, PREG_SPLIT_NO_EMPTY );
			if ( count( $parts ) == 2 ) {
				list( $property, $value ) = $parts;
			}
			else {
				continue;
			}
			// Remove any comment markers around variable names
			$property = preg_replace( '#___c\d+___\s*#', '', $property );
			self::$variables[ $property ] = $value;
		}
		return '';
	}

	public static function cb_placeVariables ( $match ) {
		$key = $match[1];
		if ( isset( self::$variables[ $key ] ) ) {
			return self::$variables[ $key ];
		}
		else {
			return '';
		}
	}

	public static function cb_expandSelector_braces ( $match ) {
		$label = "__any" . ++self::$literalCount . "__";
		self::$literals[ $label ] = $match[1];
		return $label;
	}

	public static function cb_expandSelector ( $match ) {
		 // http://dbaron.org/log/20100424-any
		$text = $match[0];
		$between = $match[1];
		if ( strpos( $between, ':any' ) === false ) {
			return $text;
		}

		$cb_expandSelector_braces = self::createCallback( 'cb_expandSelector_braces' );
		$between = preg_replace_callback(
			'#:any\(([^)]*)\)#', $cb_expandSelector_braces, $between );

		// Strip any comment labels
		$between = preg_replace( '#\s*___c\d+___\s*#', '', $between );

		$re_comma = '#\s*,\s*#';
		$matched_statements = preg_split( $re_comma, $between );

		$stack = array();
		foreach ( $matched_statements as $matched_statement ) {
			$pos = strpos( $matched_statement, '__any' );
			if ( $pos !== false ) {
				// Contains an :any statement so we expand
				$chain = array( '' );
				do {
					if ( $pos === 0 ) {
						preg_match( '#__any\d+__#', $matched_statement, $m );
						$parts = preg_split( $re_comma, self::$literals[ $m[0] ] );
						$parts = array_map( 'trim', $parts );
						$tmp = array();
						foreach ( $chain as $rowCopy ) {
							foreach ( $parts as $part ) {
								$tmp[] = $rowCopy . $part;
							}
						}
						$chain = $tmp;
						$matched_statement = substr( $matched_statement, strlen( $m[0] ) );
					}
					else {
						foreach ( $chain as &$row ) {
							$row .= substr( $matched_statement, 0, $pos );
						}
						$matched_statement = substr( $matched_statement, $pos );
					}
				} while ( ( $pos = strpos( $matched_statement, '__any' ) ) !== false );

				// Finish off
				foreach ( $chain as &$row ) {
					$stack[] = $row . $matched_statement;
				}
			}
			else {
				// Nothing special
				$stack[] = $matched_statement;
			}
		}

		// Preserving the original whitespace for easier debugging
		$first = rtrim( array_shift( $stack ) );
		$finish = array_map( 'trim', $stack );
		array_unshift( $finish, $first );
		return implode( ',', $finish ) . '{';
	}

	public static function cb_obfuscateDirectives ( $match ) {
		return str_replace( '@', '(at)', $match[0] );
	}

	public static function cb_restoreLiteral ( $match ) {
		return self::$literals[ $match[0] ];
	}

}

################################################################################################
#  End class definition
################################################################################################


// Initialize manually since it's static
CSS_Crush::init();



################################################################################################
#  Command line API

/*
php CSS_Crush.php -f=css/screen.css -n
>>> non-minified output
*/

if ( CSS_Crush::$cli ) {
	$options = getopt( "f:o::m::cn", array(
			'file:',    // Input file
			'output::', // Output file
			'macros::', // Comma seperated list of macro properties
			'comments', // (flag) Leave comments intact
			'nominify',
		));
	$file = null;
	$params = array();
	if ( isset( $options[ 'f' ] ) ) {
		$file = $options[ 'f' ];
	}
	else if ( isset( $options[ 'file' ] ) ) {
		$file = $options[ 'file' ];
	}
	if ( !$file or !file_exists( $file ) ) {
		return;
	}
	if ( isset( $options[ 'm' ] ) ) {
		$params[ 'macros' ] = explode( ',', $options[ 'm' ] );
	}
	else if ( isset( $options[ 'macros' ] ) ) {
		$params[ 'macros' ] = explode( ',', $options[ 'macros' ] );
	}
	if ( isset( $options[ 'c' ] ) or isset( $options[ 'comments' ] ) ) {
		$params[ 'comments' ] = true;
	}
	if ( isset( $options[ 'n' ] ) or isset( $options[ 'nominify' ] ) ) {
		$params[ 'minify' ] = false;
	}

	$output = CSS_Crush::cli( $file, $params );

	$outputFile = isset( $options[ 'o' ] );
	if ( $outputFile ) {
		$outputFile = $options[ 'o' ];
	}
	else {
		$outputFile = isset( $options[ 'output' ] ) ? $options[ 'output' ] : false;
	}

	if ( $outputFile ) {
		$output = CSS_Crush::getBoilerplate() . "\n{$output}";
		file_put_contents( $outputFile, $output );
	}
	else {
		echo $output . PHP_EOL;
	}
}

################################################################################################
#  Macro callbacks ( user functions )

///////////// IELegacy /////////////

// Fix opacity in ie6/7/8
if ( !function_exists( 'csscrush_Opacity' ) ) {
	function csscrush_Opacity ( $prop, $val ) {
		$msval = round( $val*100 );
		$out = "-ms-filter: \"progid:DXImageTransform.Microsoft.Alpha(Opacity={$msval})\";
				filter: progid:DXImageTransform.Microsoft.Alpha(Opacity={$msval});
				zoom:1;
				{$prop}: {$val}";
		return preg_replace( "#\s+#", ' ', $out );
	}
}
// Fix display:inline-block in ie6/7
if ( !function_exists( 'csscrush_Display' ) ) {
	function csscrush_Display ( $prop, $val ) {
		if ( $val == 'inline-block' ) {
			return "{$prop}:{$val};*{$prop}:inline;*zoom:1";
		}
		return "{$prop}:{$val}";
	}
}
// Fix min-height in ie6
if ( !function_exists( 'csscrush_Min_Height' ) ) {
	function csscrush_Min_Height ( $prop, $val ) {return "{$prop}:{$val};_height:{$val}";}
}

///////////// CSS3 /////////////

if ( !function_exists( 'csscrush_Border_Radius' ) ) {
	function csscrush_Border_Radius ( $prop, $val ) {
		return "-moz-{$prop}:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Border_Top_Left_Radius' ) ) {
	function csscrush_Border_Top_Left_Radius ( $prop, $val ) {
		return "-moz-border-radius-topleft:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Border_Top_Right_Radius' ) ) {
	function csscrush_Border_Top_Right_Radius ( $prop, $val ) {
		return "-moz-border-radius-topright:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Border_Bottom_Right_Radius' ) ) {
	function csscrush_Border_Bottom_Right_Radius ( $prop, $val ) {
		return "-moz-border-radius-bottomright:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Border_Bottom_Left_Radius' ) ) {
	function csscrush_Border_Bottom_Left_Radius ( $prop, $val ) {
		return "-moz-border-radius-bottomleft:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Box_Shadow' ) ) {
	function csscrush_Box_Shadow ( $prop, $val ) {
		return "-webkit-{$prop}:{$val};-moz-{$prop}:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Transform' ) ) {
	function csscrush_Transform ( $prop, $val ) {
		return "-o-{$prop}:{$val};-webkit-{$prop}:{$val};-moz-{$prop}:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Transition' ) ) {
	function csscrush_Transition ( $prop, $val ) {
		return "-o-{$prop}:{$val};-webkit-{$prop}:{$val};-moz-{$prop}:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Background_Size' ) ) {
	function csscrush_Background_Size ( $prop, $val ) {
		return "-o-{$prop}:{$val};-webkit-{$prop}:{$val};-moz-{$prop}:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Box_Sizing' ) ) {
	function csscrush_Box_Sizing ( $prop, $val ) {
		return "-webkit-{$prop}:{$val};-moz-{$prop}:{$val};{$prop}:{$val}";
	}
}
if ( !function_exists( 'csscrush_Background_Image' ) ) {
	function csscrush_Background_Image ( $prop, $val ) {
		if ( strpos( $val, 'linear-gradient' ) !== false ) {
			$val = substr( $val, strpos( $val, '(' ) + 1 );
			$args = preg_split( '#\s*,\s*#', str_replace( ')', '', $val ) );
			$args = array_map( 'trim', $args );

			// top, #444444, #999999
			foreach ( $args as &$arg ) {
				$re = '!^#([a-z0-9])([a-z0-9])([a-z0-9])$!i';
				if ( preg_match( $re, $arg ) ) {
					$arg = preg_replace( $re, '#$1$1$2$2$3$3', $arg );
				}
			}
			list( $dir, $col1, $col2 ) = $args;
			// Dropped support for IE since the IE filter spoils text rendering
			$out = "
				background-color:{$col1};
				background-image: -webkit-gradient(
					linear, left top, left bottom, color-stop( 0, {$col1} ), color-stop( 1, {$col2} ));
				background-image:-moz-linear-gradient(top, {$col1}, {$col2});
				background-image:linear-gradient(top, {$col1}, {$col2});";
			return preg_replace( "#\s+#", ' ', $out );
		}
		return false;
	}
}

