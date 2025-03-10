<?php

namespace WPMetaOptimizer;

// Check run from WP
defined( 'ABSPATH' ) || die();

use DateTime;

class Helpers extends Base {
	public static $instance = null;
	protected $Options = null;

	function __construct() {
		parent::__construct();

		$this->Options = Options::getInstance();
	}

	/**
	 * Insert meta, called when add or update meta
	 *
	 * @param array $args              {
	 *                                 input args
	 *
	 * @type string $metaType          Type of meta
	 * @type int    $objectID          Object ID
	 * @type string $metaKey           Meta key
	 * @type string $metaValue         Metadata value. Must be serializable if non-scalar.
	 * @type bool   $unique            Meta is unique
	 * @type bool   $addMeta           Add meta status
	 * @type string $prevValue         Previous value to check before updating
	 * @type bool   $checkCurrentValue Check current value use when an import process running
	 *                                 }
	 *
	 * @return null|int|bool
	 */
	public function insertMeta( $args ) {
		global $wpdb;

		$args = wp_parse_args( $args, [
			'metaType'          => '',
			'objectID'          => 0,
			'metaKey'           => '',
			'metaValue'         => '',
			'unique'            => true,
			'addMeta'           => false,
			'prevValue'         => '',
			'checkCurrentValue' => true
		] );

		extract( $args );

		if ( ! $objectID || empty( $metaType ) || empty( $metaKey ) )
			return null;

		$tableName = $this->getMetaTableName( $metaType );
		if ( ! $tableName )
			return null;

		$column = sanitize_key( $metaType . '_id' );

		// WP check for an existing meta key for an object id
		// Checked because update_metadata function checked again and call add_metadata function
		if ( ! $addMeta ) {
			$_metaKey    = $this->translateColumnName( $metaType, $metaKey );
			$wpMetaTable = $this->getWPMetaTableName( $metaType );
			$idColumn    = 'user' === $metaType ? 'umeta_id' : 'meta_id';
			$metaIDs     = $wpdb->get_col( $wpdb->prepare( "SELECT $idColumn FROM $wpMetaTable WHERE meta_key = %s AND $column = %d", $_metaKey, $objectID ) );

			if ( empty( $metaIDs ) )
				return null;
		}

		$addTableColumn = $this->addTableColumn( $tableName, $metaType, $metaKey, $metaValue );
		if ( ! $addTableColumn )
			return null;

		$checkInserted = intval( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tableName} WHERE {$column} = %d",
				$objectID
			)
		) );

		$originMetaValue = $metaValue;

		if ( is_bool( $metaValue ) )
			$metaValue = intval( $metaValue );

		if ( $checkInserted ) {
			if ( $checkCurrentValue ) {
				$currentValue = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT {$metaKey} FROM {$tableName} WHERE {$column} = %d",
						$objectID
					)
				);

				$currentValue = maybe_unserialize( $currentValue );

				if ( $unique && $currentValue !== null )
					return null;

				elseif ( ! $unique && empty( $prevValue ) && $addMeta ) {
					if ( is_array( $currentValue ) && isset( $currentValue['wpmoai0'] ) )
						$currentValue = array_values( $currentValue );

					if ( is_array( $metaValue ) )
						$metaValue = [ $metaValue ];

					if ( is_array( $currentValue ) && ! is_array( $metaValue ) ) {
						$metaValue = array_merge( $currentValue, [ $metaValue ] );

					} elseif ( is_array( $currentValue ) && is_array( $metaValue ) ) {
						$metaValue = array_merge( $currentValue, $metaValue );

					} elseif ( ! is_null( $currentValue ) ) {
						$metaValue = [ $currentValue, $originMetaValue ];
					}

					$metaValue = $this->reIndexMetaValue( $metaValue );

				} elseif ( ! $unique && ! empty( $prevValue ) && $currentValue !== null ) {
					if ( is_array( $currentValue ) ) {
						if ( isset( $currentValue['wpmoai0'] ) )
							$currentValue = array_values( $currentValue );

						$indexValue = array_search( $prevValue, $currentValue, false );

						if ( $indexValue === false )
							return null;
						else {
							$currentValue[ $indexValue ] = $metaValue;
							$metaValue                   = $currentValue;
						}

						$metaValue = $this->reIndexMetaValue( $metaValue, false );

					} elseif ( $prevValue !== $currentValue )
						return null;

				} elseif ( is_array( $metaValue ) && ! isset( $metaValue['wpmoai0'] ) ) {
					$metaValue = $this->reIndexMetaValue( [ $metaValue ] );
				}

				$addTableColumn = $this->addTableColumn( $tableName, $metaType, $metaKey, $metaValue );
				if ( ! $addTableColumn )
					return null;
			}

			$metaValue = maybe_serialize( $metaValue );

			if ( $metaValue === '' )
				$metaValue = null;

			$result = $wpdb->update(
				$tableName,
				[ $metaKey => $metaValue, 'updated_at' => $this->now ],
				[ $column => $objectID ]
			);

			return $result;

		} else {
			if ( is_array( $metaValue ) && ! isset( $metaValue['wpmoai0'] ) )
				$metaValue = $this->reIndexMetaValue( [ $metaValue ] );

			$metaValue = maybe_serialize( $metaValue );

			$result = $wpdb->insert(
				$tableName,
				[
					$column      => $objectID,
					'created_at' => $this->now,
					'updated_at' => $this->now,
					$metaKey     => $metaValue
				]
			);
			if ( ! $result )
				return false;

			return $wpdb->insert_id;
		}
	}

	public function reIndexMetaValue( $metaValue, $checkZeroIndex = true ) {
		if ( is_array( $metaValue ) && ( ! $checkZeroIndex || isset( $metaValue[0] ) ) ) {
			$metaValue  = array_values( $metaValue );
			$_metaValue = [];
			for ( $i = 0; $i <= count( $metaValue ) - 1; $i ++ ) {
				$_metaValue[ 'wpmoai' . $i ] = $metaValue[ $i ];
			}
			$metaValue = $_metaValue;
		}

		return $metaValue;
	}

	/**
	 * Delete meta row from plugin tables
	 *
	 * @param int    $objectID Object ID
	 * @param string $type     Meta type
	 *
	 * @return bool|int
	 */
	public function deleteMetaRow( $objectID, $type ) {
		global $wpdb;
		$table = $this->getMetaTableName( $type );
		if ( $table )
			return $wpdb->query( "DELETE FROM {$table} WHERE {$type}_id = {$objectID}" );

		return false;
	}

	/**
	 * Add column to plugin tables
	 *
	 * @param string $table     Table name
	 * @param string $type      Meta type
	 * @param string $field     Meta field
	 * @param string $metaValue Meta value
	 *
	 * @return bool|int|null
	 */
	public function addTableColumn( $table, $type, $field, $metaValue ) {
		global $wpdb;
		$collate = '';

		$value       = maybe_serialize( $metaValue );
		$value       = $this->numericVal( $value );
		$columnType  = $this->getFieldType( $value );
		$valueLength = mb_strlen( $value );

		if ( in_array( $columnType, $this->charTypes ) )
			$collate = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci';

		if ( $this->checkColumnExists( $table, $type, $field, false ) ) {
			$currentColumnType = $this->getTableColumnType( $table, $field );
			$newColumnType     = $this->getNewColumnType( $currentColumnType, $columnType );

			if ( $newColumnType == 'VARCHAR' ) {
				$currentFieldMaxLengthValue = intval( $wpdb->get_var( "SELECT MAX(LENGTH({$field})) as length FROM {$table}" ) );

				if ( $currentFieldMaxLengthValue >= $valueLength && $currentColumnType === 'VARCHAR' )
					return true;
				else
					$newColumnType = 'VARCHAR(' . ( $valueLength > $currentFieldMaxLengthValue ? $valueLength : $currentFieldMaxLengthValue ) . ')';
			} elseif ( $newColumnType == $currentColumnType )
				return true;

			$sql = "ALTER TABLE `$table` CHANGE `{$field}` `{$field}` {$newColumnType} {$collate} NULL DEFAULT NULL";
		} else {
			if ( $columnType == 'VARCHAR' )
				$columnType = 'VARCHAR(' . $valueLength . ')';

			$sql = "ALTER TABLE `{$table}` ADD COLUMN `{$field}` {$columnType} {$collate} NULL AFTER `{$type}_id`";
		}

		return $wpdb->query( $sql );
	}

	/**
	 * Reset plugin meta table
	 *
	 * @param string $type Meta Type
	 *
	 * @return bool
	 */
	function resetMetaTable( $type ) {
		global $wpdb;
		$table = $this->getMetaTableName( $type );

		if ( $table ) {
			$columns = $this->getTableColumns( $table, $type );
			if ( empty( $columns ) )
				return false;

			// Clear table indexes cache
			DBIndexes::clearCache( $table );

			// Clear table columns cache
			wp_cache_delete( 'table_columns_' . $table . '_' . $type, WPMETAOPTIMIZER_PLUGIN_KEY );

			// Delete all data in a table
			$sql = "TRUNCATE `$table`";
			$wpdb->query( $sql );

			$drops = [];
			foreach ( $columns as $column ) {
				$drops[] = "DROP `$column`";
			}
			$drops = implode( ', ', $drops );

			// Delete all custom meta fields from a table
			$sql = "ALTER TABLE `$table` $drops";

			return $wpdb->query( $sql );
		}

		return false;
	}

	/**
	 * Get the numeric (integer, float) value of a variable
	 *
	 * @param mixed $value The scalar value being converted to an integer or float
	 *
	 * @return float|int|mixed|string
	 */
	public function numericVal( $value ) {
		if ( is_numeric( $value ) )
			if ( intval( $value ) == $value )
				return intval( $value );
			elseif ( floatval( $value ) == $value )
				return floatval( $value );

		return $value;
	}

	/**
	 * Check column exists in plugin tables
	 *
	 * @param string $table    Table name
	 * @param string $type     Meta type
	 * @param string $field    Meta field
	 * @param bool   $useCache Use cache
	 *
	 * @return bool
	 */
	public function checkColumnExists( $table, $type, $field, $useCache = true ) {
		$tableColumns = $this->getTableColumns( $table, $type, $useCache );

		return in_array( $field, $tableColumns );
	}

	/**
	 * Get list of plugin table columns
	 *
	 * @param string $table    Table name
	 * @param string $type     Meta type
	 * @param bool   $useCache Use cache
	 *
	 * @return array
	 */
	public function getTableColumns( $table, $type, $useCache = false ) {
		global $wpdb;
		$tableColumns = false;

		if ( $useCache )
			$tableColumns = wp_cache_get( 'table_columns_' . $table . '_' . $type, WPMETAOPTIMIZER_PLUGIN_KEY );

		if ( $tableColumns === false ) {
			$columns      = $wpdb->get_results( "SHOW COLUMNS FROM $table", ARRAY_A );
			$columns      = array_map( function ( $column ) {
				return $column['Field'];
			}, $columns );
			$tableColumns = array_diff( $columns, $this->getIgnoreColumnNames( $type ) );

			wp_cache_set( 'table_columns_' . $table . '_' . $type, $tableColumns, WPMETAOPTIMIZER_PLUGIN_KEY, WPMETAOPTIMIZER_CACHE_EXPIRE );
		}

		return $tableColumns;
	}

	/**
	 * Changed column name if in reserved column keys
	 *
	 * @param string $type       Meta type
	 * @param string $columnName Column name
	 *
	 * @return string               Changed column name
	 */
	public function translateColumnName( $type, $columnName ) {
		$suffix       = $this->reservedKeysSuffix;
		$reservedKeys = $this->getIgnoreColumnNames( $type );

		if ( substr( $columnName, - ( strlen( $suffix ) ) ) === $suffix )
			$columnName = str_replace( $suffix, '', $columnName );
		elseif ( in_array( $columnName, $reservedKeys ) )
			$columnName = $columnName . $suffix;

		return $columnName;
	}

	public function getIgnoreColumnNames( $type ) {
		return array_merge( $this->ignoreTableColumns, [ $type . '_id' ] );
	}

	/**
	 * Get a new column type for existed columns
	 *
	 * @param string $currentColumnType Current column type
	 * @param string $valueType         New value type
	 *
	 * @return string
	 */
	public function getNewColumnType( $currentColumnType, $valueType ) {
		if ( $currentColumnType === $valueType )
			return $currentColumnType;
		elseif ( in_array( $currentColumnType, $this->intTypes ) && in_array( $valueType, $this->floatTypes ) )
			return $valueType;
		elseif ( in_array( $currentColumnType, $this->intTypes ) && in_array( $valueType, $this->charTypes ) )
			return $valueType;
		elseif ( in_array( $currentColumnType, $this->dateTypes ) && in_array( $valueType, $this->charTypes ) )
			return $valueType;
		elseif ( in_array( $currentColumnType, $this->intTypes ) && array_search( $currentColumnType, $this->intTypes ) < array_search( $valueType, $this->intTypes ) )
			return $valueType;
		elseif ( in_array( $currentColumnType, $this->floatTypes ) && array_search( $currentColumnType, $this->floatTypes ) < array_search( $valueType, $this->floatTypes ) )
			return $valueType;

		return $currentColumnType;
	}

	/**
	 * Get a table column type
	 *
	 * @param string $table Table name
	 * @param string $field Column name
	 *
	 * @return string               Column type
	 */
	public function getTableColumnType( $table, $field ) {
		global $wpdb;

		$wpdb->get_results( "SELECT `$field` FROM {$table} LIMIT 1" );
		$columnType = $wpdb->get_col_info( 'type', 0 );

		$types = array(
			1   => 'TINYINT',
			2   => 'SMALLINT',
			3   => 'INT',
			4   => 'FLOAT',
			5   => 'DOUBLE',
			8   => 'BIGINT',
			9   => 'MEDIUMINT',
			10  => 'DATE',
			12  => 'DATETIME',
			252 => 'TEXT',
			253 => 'VARCHAR',
		);

		return $types[ $columnType ] ?? false;
	}

	/**
	 * Return table column type base on value
	 *
	 * @param string $value Meta value
	 *
	 * @return string               Column type
	 */
	public function getFieldType( $value ) {
		$valueLength = mb_strlen( $value );

		if ( $this->isDate( $value ) )
			return 'DATE';
		elseif ( $this->isDateTime( $value ) )
			return 'DATETIME';
		// elseif ($this->isJson($value))
		//     return 'LONGTEXT';
		elseif ( is_string( $value ) && $valueLength <= 65535 || is_null( $value ) )
			return 'VARCHAR';
		elseif ( is_bool( $value ) )
			return 'TINYINT';
		elseif ( is_float( $value ) )
			return 'FLOAT';
		elseif ( is_double( $value ) )
			return 'DOUBLE';
		elseif ( is_numeric( $value ) && intval( $value ) != 0 || is_int( $value ) || $value == 0 ) {
			$value = intval( $value );
			if ( $value >= - 128 && $value <= 127 )
				return 'TINYINT';
			if ( $value >= - 32768 && $value <= 32767 )
				return 'SMALLINT';
			if ( $value >= - 8388608 && $value <= 8388607 )
				return 'MEDIUMINT';
			if ( $value >= - 2147483648 && $value <= 2147483647 )
				return 'INT';
			else
				return 'BIGINT';
		} else
			return 'TEXT';
	}

	/**
	 * Get table rows count
	 *
	 * @param string $table Table name
	 *
	 * @return int
	 */
	public function getTableRowsCount( $table ) {
		global $wpdb;

		return $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	public function getTableSize( $table, $humanSize = false ) {
		global $wpdb;

		$size = intval( $wpdb->get_var( "SELECT (DATA_LENGTH + INDEX_LENGTH) AS `size` FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$wpdb->dbname' AND TABLE_NAME = '$table'" ) );

		if ( $humanSize )
			$size = $this->humanFileSize( $size, 1 );

		return $size;
	}

	function humanFileSize( $bytes, $dec = 2 ): string {
		$size   = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
		$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );
		if ( $factor == 0 )
			$dec = 0;

		return sprintf( "%.{$dec}f %s", $bytes / ( 1024 ** $factor ), $size[ $factor ] );
	}

	/**
	 * Get meta table name base on a type
	 *
	 * @param string $type Meta type
	 *
	 * @return bool|string
	 */
	public function getMetaTableName( $type ) {
		return isset( $this->tables[ $type ] ) ? $this->tables[ $type ]['table'] : false;
	}

	/**
	 * Get WP table name base on a type
	 *
	 * @param string $type Meta type
	 *
	 * @return bool|string
	 */
	public function getWPPrimaryTableName( $type ) {
		return $this->wpPrimaryTables[ $type ] ?? false;
	}

	/**
	 * Get WordPress meta table name base on a type
	 *
	 * @param string $type Meta type
	 *
	 * @return bool|string
	 */
	public function getWPMetaTableName( $type ) {
		return $this->wpMetaTables[ $type ] ?? false;
	}

	/**
	 * Check if user set dont save in default WordPress meta tables
	 *
	 * @param string $type Meta type
	 *
	 * @return bool
	 */
	public function checkDontSaveInDefaultTable( $type ) {
		$defaultMetaSave = $this->Options->getOption( 'dont_save_wpmeta', [] );

		return isset( $defaultMetaSave[ $type ] );
	}

	/**
	 * Check meta can get/add/update
	 *
	 * @param string $type Meta type
	 *
	 * @return bool
	 */
	public function checkMetaType( $type ) {
		$metaSaveTypes = $this->Options->getOption( 'meta_save_types', [] );

		return isset( $metaSaveTypes[ $type ] );
	}

	/**
	 * Check a supported post type
	 *
	 * @param int $postID Post ID
	 *
	 * @return bool
	 */
	public function checkPostType( $postID ) {
		$postType = wp_cache_get( 'post_type_value_' . $postID, WPMETAOPTIMIZER_PLUGIN_KEY );
		if ( $postType === false ) {
			$postType = get_post_type( $postID );
			wp_cache_set( 'post_type_value_' . $postID, $postType, WPMETAOPTIMIZER_PLUGIN_KEY, WPMETAOPTIMIZER_CACHE_EXPIRE );
		}

		$allowedPostTypes = $this->Options->getOption( 'post_types', [] );

		return isset( $allowedPostTypes[ $postType ] );
	}

	/**
	 * Get allowed post-types
	 *
	 * @return array
	 */
	public function getSupportPostTypes() {
		$allowedPostTypes = $this->Options->getOption( 'post_types', [] );
		$allowedPostTypes = array_keys( $allowedPostTypes );
		if ( ( $key = array_search( 'hidden', $allowedPostTypes ) ) !== false )
			unset( $allowedPostTypes[ $key ] );

		return array_values( $allowedPostTypes );
	}

	/**
	 * Check a meta key exists in black/white list
	 *
	 * @param string $type     Meta type
	 * @param string $metaKey  Meta key
	 * @param string $listName List name
	 *
	 * @return bool
	 */
	public function checkInBlackWhiteList( $type, $metaKey, $listName = 'black_list' ) {
		if ( $listName === 'black_list' && isset( $this->ignoreWPMetaKeys[ $type ] ) && in_array( $metaKey, $this->ignoreWPMetaKeys[ $type ] ) )
			return true;

		$list = $this->Options->getOption( $type . '_' . $listName, '' );
		if ( empty( $list ) )
			return '';

		$list = explode( "\n", $list );
		$list = str_replace( [ "\n", "\r" ], '', $list );
		$list = array_map( 'trim', $list );

		return in_array( $metaKey, $list );
	}

	/**
	 * Check can change WordPress meta keys
	 *
	 * @param string $type    Meta type
	 * @param string $metaKey Meta key
	 *
	 * @return bool
	 */
	public function checkCanChangeWPMetaKey( $type, $metaKey ) {
		return ! ( isset( $this->cantChangeWPMetaKeys[ $type ] ) && in_array( $metaKey, $this->cantChangeWPMetaKeys[ $type ] ) );
	}

	/**
	 * Get the number of remaining cases of the specified type
	 *
	 * @param string $type Meta type
	 *
	 * @return int
	 */
	public function getObjectLeftItemsCount( $type ) {
		$latestObjectID = $this->Options->getOption( 'import_' . $type . '_latest_id', null );

		if ( $latestObjectID === 'finished' )
			return 0;

		return $this->getLatestObjectID( $type, $latestObjectID, true );
	}

	/**
	 * Get latest object ID
	 *
	 * @param string  $type           Meta type
	 * @param int     $latestObjectID Latest changed object ID
	 * @param boolean $findItemsLeft  Find items left for an import process
	 *
	 * @return int
	 */
	public function getLatestObjectID( $type, $latestObjectID = null, $findItemsLeft = false ) {
		global $wpdb;
		$primaryColumn = 'ID';
		$where         = [];
		$wheres        = "";

		$table = $wpdb->prefix . $type . 's';

		if ( in_array( $type, [ 'term', 'comment' ] ) )
			$primaryColumn = $type . '_ID';

		if ( $latestObjectID = intval( $latestObjectID ) )
			$where[] = "$primaryColumn < $latestObjectID";

		if ( $type === 'post' ) {
			$allowedPostTypes = $this->getSupportPostTypes();

			$postWhere = "(post_status IN ('publish','future','draft','pending','private')";
			if ( count( $allowedPostTypes ) )
				$postWhere .= " AND post_type IN ('" . implode( "','", $allowedPostTypes ) . "')";

			if ( in_array( 'attachment', $allowedPostTypes ) )
				$postWhere .= " OR post_type = 'attachment'";
			$postWhere .= ")";

			$where[] = $postWhere;
		}

		if ( count( $where ) )
			$wheres = "WHERE " . implode( ' AND ', $where );

		if ( $findItemsLeft )
			$query = "SELECT COUNT(*) FROM $table $wheres";
		else
			$query = "SELECT $primaryColumn FROM $table $wheres ORDER BY $primaryColumn DESC LIMIT 1";

		return intval( $wpdb->get_var( $query ) );
	}

	/**
	 * Check active an automatic support WP query
	 *
	 * @return void
	 */
	public function activeAutomaticallySupportWPQuery() {
		if ( $this->checkImportFinished() ) {
			$supportWPQuery      = $this->Options->getOption( 'support_wp_query', 0 ) == 1;
			$activeAutomatically = $this->Options->getOption( 'support_wp_query_active_automatically', false ) == 1;

			if ( ! $supportWPQuery && $activeAutomatically )
				$this->Options->setOption( 'support_wp_query', 1 );
		}
	}

	/**
	 * Check support WP Query
	 *
	 * @return boolean
	 */
	public function checkSupportWPQuery() {
		$supportWPQuery        = $this->Options->getOption( 'support_wp_query', false ) == 1;
		$deactivateWhileImport = $this->Options->getOption( 'support_wp_query_deactive_while_import', false ) == 1;

		return $supportWPQuery && ( ! $deactivateWhileImport || $this->checkImportFinished() );
	}

	/**
	 * Check import finished
	 *
	 * @param boolean $type Meta type
	 *
	 * @return boolean
	 */
	public function checkImportFinished( $type = false ) {
		// $types = array_keys($this->tables);
		$types = $this->Options->getOption( 'meta_save_types', [] );
		if ( isset( $types['hidden'] ) )
			unset( $types['hidden'] );

		$types = array_keys( $types );

		if ( $type && in_array( $type, $types ) )
			$types = [ $type ];

		if ( count( $types ) == 0 )
			return false;

		foreach ( $types as $type ) {
			$latestObjectID = $this->Options->getOption( 'import_' . $type . '_latest_id', null );
			if ( $latestObjectID !== 'finished' )
				return false;
		}

		return true;
	}

	public static function secondsToHumanReadable( $seconds ) {
		$seconds = intval( $seconds );
		$dtF     = new \DateTime ( '@0' );
		$dtT     = new \DateTime ( "@$seconds" );
		$ret     = '';
		if ( $seconds === 0 ) {
			// special case
			return '0 ' . __( 'Seconds', 'meta-optimizer' );
		}
		$diff = $dtF->diff( $dtT );
		foreach (
			array(
				'y' => __( 'Years', 'meta-optimizer' ),
				'm' => __( 'Months', 'meta-optimizer' ),
				'd' => __( 'Days', 'meta-optimizer' ),
				'h' => __( 'Hours', 'meta-optimizer' ),
				'i' => __( 'Minutes', 'meta-optimizer' ),
				's' => __( 'Seconds', 'meta-optimizer' )
			) as $time => $timeName
		) {
			if ( $diff->$time !== 0 ) {
				$ret .= $diff->$time . ' ' . $timeName;
				$ret .= ' ';
			}
		}

		return substr( $ret, 0, - 1 );
	}

	/**
	 * Check is JSON
	 *
	 * @param string $string Input string
	 *
	 * @return boolean
	 */
	private function isJson( $string ) {
		if ( ! is_string( $string ) )
			return false;
		json_decode( $string );

		return json_last_error() === JSON_ERROR_NONE;
	}

	/**
	 * Check is Date
	 *
	 * @param string $string Input string
	 *
	 * @return boolean
	 */
	private function isDate( $string ) {
		$time = strtotime( $string );

		if ( $time )
			$time = DateTime::createFromFormat( 'Y-m-d', $string ) !== false;

		return $time;
	}

	/**
	 * Check is DateTime
	 *
	 * @param string $string Input string
	 *
	 * @return boolean
	 */
	private function isDateTime( $string ) {
		return DateTime::createFromFormat( 'Y-m-d H:i:s', $string ) !== false;
	}

	/**
	 * Returns an instance of class
	 *
	 * @return Helpers
	 */
	static function getInstance() {
		if ( self::$instance == null )
			self::$instance = new Helpers();

		return self::$instance;
	}
}
