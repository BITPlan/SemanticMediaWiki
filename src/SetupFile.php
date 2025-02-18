<?php

namespace SMW;

use SMW\Exception\FileNotWritableException;
use SMW\Utils\File;
use SMW\SQLStore\Installer;

/**
 * @private
 *
 * @license GNU GPL v2+
 * @since 3.1
 *
 * @author mwjames
 */
class SetupFile {

	/**
	 * Describes the maintenance mode
	 */
	const MAINTENANCE_MODE = 'maintenance_mode';

	/**
	 * Describes the upgrade key
	 */
	const UPGRADE_KEY = 'upgrade_key';

	/**
	 * Describes the database requirements
	 */
	const DB_REQUIREMENTS = 'db_requirements';

	/**
	 * Describes the entity collection setting
	 */
	const ENTITY_COLLATION = 'entity_collation';

	/**
	 * Key that describes the date of the last table optimization run.
	 */
	const LAST_OPTIMIZATION_RUN = 'last_optimization_run';

	/**
	 * Describes the file name
	 */
	const FILE_NAME = '.smw.json';

	/**
	 * Describes incomplete tasks
	 */
	const INCOMPLETE_TASKS = 'incomplete_tasks';

	/**
	 * Versions
	 */
	const LATEST_VERSION = 'latest_version';
	const PREVIOUS_VERSION = 'previous_version';

	/**
	 * @var File
	 */
	private $file;

	/**
	 * @since 3.1
	 *
	 * @param File|null $file
	 */
	public function __construct( File $file = null ) {
		$this->file = $file;

		if ( $this->file === null ) {
			$this->file = new File();
		}
	}

	/**
	 * @since 3.1
	 *
	 * @param array $vars
	 */
	public function loadSchema( &$vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		if ( isset( $vars['smw.json'] ) ) {
			return;
		}

		// @see #3506
		$file = File::dir( $vars['smwgConfigFileDir'] . '/' . self::FILE_NAME );

		// Doesn't exist? The `Setup::init` will take care of it by trying to create
		// a new file and if it fails or unable to do so wail raise an exception
		// as we expect to have access to it.
		if ( is_readable( $file ) ) {
			$vars['smw.json'] = json_decode( file_get_contents( $file ), true );
		}
	}

	/**
	 * @since 3.1
	 *
	 * @param boolean $isCli
	 *
	 * @return boolean
	 */
	public static function isGoodSchema( $isCli = false ) {
		// get the schema State as a  human readable description
		$schemaState=SetupFile::getSchemaState($isCli);
		// check that it starts with "ok:" and not "error:"
		$result=SetupFile::strStartsWith($schemaState,"ok:");
		return $result;
	}

	/**
	 * @since 3.1.7
	 *
	 * see https://stackoverflow.com/a/6513929/1497139
	 *
	 * @param string $haystack
	 * @param string $needle
	 *
	 * return boolean
	 */
	public static function strStartsWith($haystack, $needle) {
           return (strpos($haystack, $needle) === 0);
  }

	/**
	 * @since 3.1.7
	 *
	 * @param boolean $isCli
	 *
	 * @return string 
	 */
	public static function getSchemaState( $isCli = false ) {
          
		if ( $isCli && defined( 'MW_PHPUNIT_TEST' ) ) {
			return "ok: CLI with PHP Unit Test active";
		}

		if ( $isCli === false && ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ) ) {
			return "ok: isCli is true and PHP_SAPI cli/phpdbg=".PHP_SAPI;
		}

		// #3563, Use the specific wiki-id as identifier for the instance in use
		$id = Site::id();

    if ( !isset( $GLOBALS['smw.json'][$id]['upgrade_key'] ) ) {
      global $smwgConfigFileDir;
			return "error: smw.json for ".$id." upgrade key missing - you might want to check \$smwgConfigFileDir:".$smwgConfigFileDir;
		}

		$upgradeKey = self::makeUpgradeKey( $GLOBALS );
		$expected   =$GLOBALS['smw.json'][$id]['upgrade_key'];
		if ( $upgradeKey === $expected ) 
			$schemaState= "ok: found upgradeKey.".$upgradeKey;
		else
			$schemaState= "error: expected upgradeKey ".$expected." for ".$id." but found ".$upgradeKey;

		if (
			isset( $GLOBALS['smw.json'][$id][self::MAINTENANCE_MODE] ) &&
			$GLOBALS['smw.json'][$id][self::MAINTENANCE_MODE] !== false ) {
			$schemaState= "error: upgradeKey ".$upgradeKey." is ok but maintainance is active";
		}

		return $schemaState;
	}

	/**
	 * @since 3.1
	 *
	 * @param array $vars
	 *
	 * @return string
	 */
	public static function makeUpgradeKey( $vars ) {
		return sha1( self::makeKey( $vars ) );
	}

	/**
	 * @since 3.1
	 *
	 * @param array $vars
	 *
	 * @return boolean
	 */
	public function inMaintenanceMode( $vars = [] ) {

		if ( !defined( 'MW_PHPUNIT_TEST' ) && ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ) ) {
			return false;
		}

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$id = Site::id();

		if ( !isset( $vars['smw.json'][$id][self::MAINTENANCE_MODE] ) ) {
			return false;
		}

		return $vars['smw.json'][$id][self::MAINTENANCE_MODE] !== false;
	}

	/**
	 * @since 3.1
	 *
	 * @param array $vars
	 *
	 * @return []
	 */
	public function getMaintenanceMode( $vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$id = Site::id();

		if ( !isset( $vars['smw.json'][$id][self::MAINTENANCE_MODE] ) ) {
			return [];
		}

		return $vars['smw.json'][$id][self::MAINTENANCE_MODE];
	}

	/**
	 * Tracking the latest and previous version, which allows us to decide whether
	 * current activties relate to an install (new) or upgrade.
	 *
	 * @since 3.2
	 *
	 * @param int $version
	 */
	public function setLatestVersion( $version ) {

		$latest = $this->get( SetupFile::LATEST_VERSION );
		$previous = $this->get( SetupFile::PREVIOUS_VERSION );

		if ( $latest === null && $previous === null ) {
			$this->set(
				[
					SetupFile::LATEST_VERSION => $version
				]
			);
		} elseif ( $latest !== $version ) {
			$this->set(
				[
					SetupFile::LATEST_VERSION => $version,
					SetupFile::PREVIOUS_VERSION => $latest
				]
			);
		}
	}

	/**
	 * @since 3.2
	 *
	 * @param string $key
	 * @param array $args
	 */
	public function addIncompleteTask( string $key, array $args = [] ) {

		$incomplete_tasks = $this->get( self::INCOMPLETE_TASKS );

		if ( $incomplete_tasks === null ) {
			$incomplete_tasks = [];
		}

		$incomplete_tasks[$key] = $args === [] ? true : $args;

		$this->set( [ self::INCOMPLETE_TASKS => $incomplete_tasks ] );
	}

	/**
	 * @since 3.2
	 *
	 * @param string $key
	 */
	public function removeIncompleteTask( string $key ) {

		$incomplete_tasks = $this->get( self::INCOMPLETE_TASKS );

		if ( $incomplete_tasks === null ) {
			$incomplete_tasks = [];
		}

		unset( $incomplete_tasks[$key] );

		$this->set( [ self::INCOMPLETE_TASKS => $incomplete_tasks ] );
	}

	/**
	 * @since 3.2
	 *
	 * @param array $vars
	 *
	 * @return boolean
	 */
	public function hasDatabaseMinRequirement( array $vars = [] ) : bool {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$id = Site::id();

		// No record means, no issues!
		if ( !isset( $vars['smw.json'][$id][self::DB_REQUIREMENTS] ) ) {
			return true;
		}

		$requirements = $vars['smw.json'][$id][self::DB_REQUIREMENTS];

		return version_compare( $requirements['latest_version'], $requirements['minimum_version'], 'ge' );
	}

	/**
	 * @since 3.1
	 *
	 * @param array $vars
	 *
	 * @return []
	 */
	public function findIncompleteTasks( $vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$id = Site::id();
		$tasks = [];

		// Key field => [ value that constitutes the `INCOMPLETE` state, error msg ]
		$checks = [
			\SMW\SQLStore\Installer::POPULATE_HASH_FIELD_COMPLETE => [ false, 'smw-install-incomplete-populate-hash-field' ],
			\SMW\Elastic\ElasticStore::REBUILD_INDEX_RUN_COMPLETE => [ false, 'smw-install-incomplete-elasticstore-indexrebuild' ]
		];

		foreach ( $checks as $key => $value ) {

			if ( !isset( $vars['smw.json'][$id][$key] ) ) {
				continue;
			}

			if ( $vars['smw.json'][$id][$key] === $value[0] ) {
				$tasks[] = $value[1];
			}
		}

		if ( isset( $vars['smw.json'][$id][self::INCOMPLETE_TASKS] ) ) {
			foreach ( $vars['smw.json'][$id][self::INCOMPLETE_TASKS] as $key => $args ) {
				if ( $args === true ) {
					$tasks[] = $key;
				} else {
					$tasks[] = [ $key, $args ];
				}
			}
		}

		return $tasks;
	}

	/**
	 * @since 3.1
	 *
	 * @param mixed $maintenanceMode
	 */
	public function setMaintenanceMode( $maintenanceMode, $vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$this->write(
			[
				self::UPGRADE_KEY => self::makeUpgradeKey( $vars ),
				self::MAINTENANCE_MODE => $maintenanceMode
			],
			$vars
		);
	}

	/**
	 * @since 3.1
	 *
	 * @param array $vars
	 */
	public function finalize( $vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		// #3563, Use the specific wiki-id as identifier for the instance in use
		$key = self::makeUpgradeKey( $vars );
		$id = Site::id();

		if (
			isset( $vars['smw.json'][$id][self::UPGRADE_KEY] ) &&
			$key === $vars['smw.json'][$id][self::UPGRADE_KEY] &&
			$vars['smw.json'][$id][self::MAINTENANCE_MODE] === false ) {
			return false;
		}

		$this->write(
			[
				self::UPGRADE_KEY => $key,
				self::MAINTENANCE_MODE => false
			],
			$vars
		);
	}

	/**
	 * @since 3.1
	 *
	 * @param array $vars
	 */
	public function reset( $vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$id = Site::id();
		$args = [];

		if ( !isset( $vars['smw.json'][$id] ) ) {
			return;
		}

		$vars['smw.json'][$id] = [];

		$this->write( [], $vars );
	}

	/**
	 * @since 3.1
	 *
	 * @param array $args
	 */
	public function set( array $args, $vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$this->write( $args, $vars );
	}

	/**
	 * @since 3.1
	 *
	 * @param array $args
	 */
	public function get( $key, $vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$id = Site::id();

		if ( isset( $vars['smw.json'][$id][$key] ) ) {
			return $vars['smw.json'][$id][$key];
		}

		return null;
	}

	/**
	 * @since 3.1
	 *
	 * @param string $key
	 */
	public function remove( $key, $vars = [] ) {

		if ( $vars === [] ) {
			$vars = $GLOBALS;
		}

		$this->write( [ $key => null ], $vars );
	}

	/**
	 * @since 3.1
	 *
	 * @param array $vars
	 * @param array $args
	 */
	public function write( array $args, array $vars ) {

		$configFile = File::dir( $vars['smwgConfigFileDir'] . '/' . self::FILE_NAME );
		$id = Site::id();

		if ( !isset( $vars['smw.json'] ) ) {
			$vars['smw.json'] = [];
		}

		foreach ( $args as $key => $value ) {
			// NULL means that the key key is removed
			if ( $value === null ) {
				unset( $vars['smw.json'][$id][$key] );
			} else {
				$vars['smw.json'][$id][$key] = $value;
			}
		}

		// Log the base elements used for computing the key
		$vars['smw.json'][$id]['upgrade_key_base'] = self::makeKey(
			$vars
		);

		// Remove legacy
		if ( isset( $vars['smw.json']['upgradeKey'] ) ) {
			unset( $vars['smw.json']['upgradeKey'] );
		}
		if ( isset( $vars['smw.json'][$id]['in.maintenance_mode'] ) ) {
			unset( $vars['smw.json'][$id]['in.maintenance_mode'] );
		}

		try {
			$this->file->write(
				$configFile,
				json_encode( $vars['smw.json'], JSON_PRETTY_PRINT )
			);
		} catch( FileNotWritableException $e ) {
			// Users may not have `wgShowExceptionDetails` enabled and would
			// therefore not see the exception error message hence we fail hard
			// and die
			die(
				"\n\nERROR: " . $e->getMessage() . "\n" .
				"\n       The \"smwgConfigFileDir\" setting should point to a" .
				"\n       directory that is persistent and writable!\n"
			);
		}
	}

	/**
	 * Listed keys will have a "global" impact of how data are stored, formatted,
	 * or represented in Semantic MediaWiki. In most cases it will require an action
	 * from an adminstrator when one of those keys are altered.
	 */
	public static function makeKey( $vars ) {

		// Only recognize those properties that require a fixed table
		$pageSpecialProperties = array_intersect(
			// Special properties enabled?
			$vars['smwgPageSpecialProperties'],

			// Any custom fixed properties require their own table?
			TypesRegistry::getFixedProperties( 'custom_fixed' )
		);

		$pageSpecialProperties = array_unique( $pageSpecialProperties );

		// Sort to ensure the key contains the same order
		sort( $vars['smwgFixedProperties'] );
		sort( $pageSpecialProperties );

		// The following settings influence the "shape" of the tables required
		// therefore use the content to compute a key that reflects any
		// changes to them
		$components = [
			$vars['smwgUpgradeKey'],
			$vars['smwgDefaultStore'],
			$vars['smwgFixedProperties'],
			$vars['smwgEnabledFulltextSearch'],
			$pageSpecialProperties
		];

		// Only add the key when it is different from the default setting
		if ( $vars['smwgEntityCollation'] !== 'identity' ) {
			$components += [ 'smwgEntityCollation' => $vars['smwgEntityCollation'] ];
		}

		if ( $vars['smwgFieldTypeFeatures'] !== false ) {
			$components += [ 'smwgFieldTypeFeatures' => $vars['smwgFieldTypeFeatures'] ];
		}

		// Recognize when the version requirements change and force
		// an update to be able to check the requirements
		$components += Setup::MINIMUM_DB_VERSION;

		return json_encode( $components );
	}

}
