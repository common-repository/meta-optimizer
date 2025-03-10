<?php

namespace WPMetaOptimizer;

// Check run from WP
defined( 'ABSPATH' ) || die();

class Options extends Base {
	public static $instance = null;

	function __construct() {
		parent::__construct();

		add_action( 'admin_menu', array( $this, 'adminMenu' ) );
		add_action( 'init', array( $this, 'defineWords' ) );
	}

	function defineWords() {
		$tableInfo = array(
			'post'    => [
				'name'  => __( 'Post' ),
				'title' => __( 'Post Meta', 'meta-optimizer' )
			],
			'comment' => [
				'name'  => __( 'Comment' ),
				'title' => __( 'Comment Meta', 'meta-optimizer' )
			],
			'user'    => [
				'name'  => __( 'User' ),
				'title' => __( 'User Meta', 'meta-optimizer' )
			],
			'term'    => [
				'name'  => __( 'Term', 'meta-optimizer' ),
				'title' => __( 'Term Meta', 'meta-optimizer' )
			]
		);

		foreach ( $this->tables as $type => $info ) {
			$this->tables[ $type ]['name']  = $tableInfo[ $type ]['name'];
			$this->tables[ $type ]['title'] = $tableInfo[ $type ]['title'];
		}
	}

	/**
	 * Add admin menu
	 *
	 * @return void
	 */
	public function adminMenu() {
		add_submenu_page( 'tools.php', __( 'Meta Optimizer', 'meta-optimizer' ), __( 'Meta Optimizer', 'meta-optimizer' ), 'manage_options', WPMETAOPTIMIZER_PLUGIN_KEY, array(
			$this,
			'settingsPage'
		) );
	}

	/**
	 * Add settings page
	 *
	 * @return void
	 */
	public function settingsPage() {
		$Helpers       = Helpers::getInstance();
		$updateMessage = '';
		$currentTab    = 'tables';

		if ( isset( $_POST[ WPMETAOPTIMIZER_PLUGIN_KEY ] ) ) {
			$currentTab = isset( $_POST['current_tab'] ) ? sanitize_text_field( $_POST['current_tab'] ) : $currentTab;
			$postData   = $_POST;
			unset( $postData[ WPMETAOPTIMIZER_PLUGIN_KEY ] );
			unset( $postData['current_tab'] );

			if ( wp_verify_nonce( $_POST[ WPMETAOPTIMIZER_PLUGIN_KEY ], 'settings_submit' ) ) {
				$checkBoxList = [];

				$options = $this->getOption( null, [], false );

				foreach ( $postData as $key => $value ) {
					if ( strpos( $key, '_white_list' ) !== false || strpos( $key, '_black_list' ) !== false )
						$value = sanitize_textarea_field( $value );
					else
						$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );

					$options[ sanitize_key( $key ) ] = $value;
				}

				if ( $currentTab == 'settings' )
					$checkBoxList = [
						'support_wp_query',
						'support_wp_query_active_automatically',
						'support_wp_query_deactive_while_import',
						'original_meta_actions'
					];
				else if ( $currentTab == 'tools' )
					$checkBoxList = [ 'disable_quick_draft_widget', 'disable_post_revisions' ];

				foreach ( $checkBoxList as $checkbox ) {
					$options[ $checkbox ] = isset( $postData[ $checkbox ] ) ? sanitize_text_field( $postData[ $checkbox ] ) : 0;
				}

				update_option( WPMETAOPTIMIZER_OPTION_KEY, $options, false );
				$updateMessage = $this->getNoticeMessageHTML( __( 'Settings saved.' ) );

				// Reset Import
				foreach ( $this->tables as $type => $table ) {
					if ( isset( $postData[ 'reset_import_' . $type ] ) )
						$this->setOption( 'import_' . $type . '_latest_id', null );
				}

				wp_cache_delete( 'options', WPMETAOPTIMIZER_PLUGIN_KEY );
			}

			if ( wp_verify_nonce( $_POST[ WPMETAOPTIMIZER_PLUGIN_KEY ], 'reset_tables_submit' ) ) {
				$importTables = $this->getOption( 'import', [] );
				$types        = array_keys( $this->tables );

				$reset = false;
				foreach ( $types as $type ) {
					if ( isset( $postData[ 'reset_plugin_table_' . $type ] ) ) {
						$Helpers->resetMetaTable( $type );

						if ( isset( $postData[ 'reset_import_' . $type ] ) ) {
							$importTables[ $type ] = 1;
							$this->setOption( 'import_' . $type . '_latest_id', null );
						}

						$reset = true;
					}
				}

				if ( $reset )
					$updateMessage = $this->getNoticeMessageHTML( __( 'Plugin table(s) reseted.', 'meta-optimizer' ) );

				$this->setOption( 'import', $importTables );
			}

			if ( wp_verify_nonce( $_POST[ WPMETAOPTIMIZER_PLUGIN_KEY ], 'optimize_submit' ) ) {
				$effectedItems = 0;
				$types         = array_keys( $this->tables );
				foreach ( $types as $type ) {
					if ( isset( $postData[ 'orphaned_' . $type . '_meta' ] ) ) {
						Optimize::deleteOrphanedMeta( $type );
						$effectedItems ++;
					}
				}

				if ( isset( $postData['delete_orphaned_term_relationships'] ) ) {
					Optimize::deleteOrphanedRelationships();
					$effectedItems ++;
				}

				if ( isset( $postData['delete_revisions_posts'] ) ) {
					Optimize::deletePosts( 'revision' );
					$effectedItems ++;
				}

				if ( isset( $postData['delete_trash_posts'] ) ) {
					Optimize::deletePosts( null, 'trash' );
					$effectedItems ++;
				}

				if ( isset( $postData['delete_auto_draft_posts'] ) ) {
					Optimize::deletePosts( null, 'auto-draft' );
					$effectedItems ++;
				}

				if ( isset( $postData['delete_expired_transients'] ) ) {
					Optimize::deleteExpiredTransients();
					$effectedItems ++;
				}

				if ( $effectedItems )
					$updateMessage .= $this->getNoticeMessageHTML( __( 'Optimize selected items.', 'meta-optimizer' ) );

				if ( isset( $postData['optimize_db_tables'] ) ) {
					Optimize::optimizeDatabaseTables();
					$updateMessage .= $this->getNoticeMessageHTML( __( 'Your WordPress database tables optimized.', 'meta-optimizer' ) );
				}
			}
		}

		$postTypes = get_post_types( [
			'show_ui' => true
		], "objects" );

		$metaSaveTypes = $this->getOption( 'meta_save_types', [], false );
		?>
        <div class="wrap wpmo-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-editor-table"></span>
				<?php _e( 'Meta Optimizer', 'meta-optimizer' ) ?>
            </h1>
			<?php echo wp_kses( $updateMessage, array( 'div' => [ 'class' => [] ], 'p' => [] ) ); ?>

            <div class="nav-tab-wrapper">
                <a id="tables-tab"
                   class="wpmo-tab nav-tab <?php echo $currentTab == 'tables' ? 'nav-tab-active' : '' ?>">
					<?php _e( 'Tables', 'meta-optimizer' ) ?>
                </a>
                <a id="settings-tab"
                   class="wpmo-tab nav-tab <?php echo $currentTab == 'settings' ? 'nav-tab-active' : '' ?>">
					<?php _e( 'Settings' ) ?>
                </a>
                <a id="import-tab"
                   class="wpmo-tab nav-tab <?php echo $currentTab == 'import' ? 'nav-tab-active' : '' ?>">
					<?php _e( 'Import', 'meta-optimizer' ) ?>
                </a>
                <a id="tools-tab"
                   class="wpmo-tab nav-tab <?php echo $currentTab == 'tools' ? 'nav-tab-active' : '' ?>">
					<?php _e( 'Tools', 'meta-optimizer' ) ?>
                </a>
                <a id="optimize-tab"
                   class="wpmo-tab nav-tab <?php echo $currentTab == 'optimize' ? 'nav-tab-active' : '' ?>">
					<?php _e( 'Optimize', 'meta-optimizer' ) ?>
                </a>
            </div>

            <div id="tables-tab-content" class="wpmo-tab-content <?php echo $currentTab != 'tables' ? 'hidden' : '' ?>">
				<?php
				foreach ( $this->tables as $type => $table ) {
					$ignoreColumns = $Helpers->getIgnoreColumnNames( $type );
					$columns       = $Helpers->getTableColumns( $table['table'], $type );
					sort( $columns );
					$tableSize = $Helpers->getTableSize( $table['table'], true );
					?>
                    <h2><?php echo esc_html( $table['title'] ) ?></h2>
                    <p class="description">
						<?php
						_e( 'Table Size:', 'meta-optimizer' );
						echo ' ' . $tableSize . ' | ';
						_e( 'Number of Columns:', 'meta-optimizer' );
						echo ' ' . ( is_array( $columns ) ? count( $columns ) : 0 ) . ' | ';
						_e( 'Number of rows:', 'meta-optimizer' );
						echo ' ' . $Helpers->getTableRowsCount( $table['table'] );
						?>
                    </p>

                    <table class="wp-list-table widefat fixed striped table-view-list table-sticky-head">
                        <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th><?php _e( 'Field Name', 'meta-optimizer' ) ?></th>
                            <th><?php _e( 'Type', 'meta-optimizer' ) ?></th>
                            <th><?php _e( 'Change' ) ?></th>
							<?php if ( $this->getOption( 'original_meta_actions', false ) == 1 ) { ?>
                                <th class="color-red"><span class="dashicons dashicons-info"></span> <abbr
                                            title="<?php echo sprintf( __( "These actions directly affect the %s WordPress table and %s plugin table", 'meta-optimizer' ), $Helpers->getWPMetaTableName( $type ), $Helpers->getMetaTableName( $type ) ); ?>"
                                            class="tooltip-title"><?php _e( 'Change the original meta' ) ?></abbr></th>
							<?php } ?>
                        </tr>
                        </thead>
                        <tbody>
						<?php
						$c = 1;
						if ( is_array( $columns ) && count( $columns ) )
							foreach ( $columns as $column ) {
								$_column     = $column;
								$column      = $Helpers->translateColumnName( $type, $column );
								$indexExists = DBIndexes::checkExists( $table['table'], $_column, $ignoreColumns );
								$columnType  = strtolower( $Helpers->getTableColumnType( $table['table'], $_column ) );

								$checkInBlackList = Helpers::getInstance()->checkInBlackWhiteList( $type, $column );
								if ( $checkInBlackList ) {
									$listActionTitle = __( 'Remove from black list', 'meta-optimizer' );
									$listAction      = 'remove';
								} else {
									$listActionTitle = __( 'Add to black list', 'meta-optimizer' );
									$listAction      = 'insert';
								}

								if ( $_column === $column )
									$_column = '';

								echo "<tr class='" . ( $checkInBlackList ? 'black-list-column' : '' ) . "'><td>{$c}</td><td class='column-name'><span>" . esc_html( $column ) . "</span>" . ( $_column ? " <abbr class='translated-column-name tooltip-title' title='" . __( 'The meta key was renamed because it equals the name of a reserved column.', 'meta-optimizer' ) . "'>(" . esc_html( $_column ) . ")</abbr>" : '' ) . "</td>";

								echo "<td>$columnType</td>";

								echo "<td class='change-icons'>";
								echo "<span class='dashicons dashicons-edit rename-table-column tooltip-title' title='" . __( 'Rename', 'meta-optimizer' ) . "' data-type='" . esc_html( $type ) . "' data-meta-table='plugin' data-column='" . esc_html( $column ) . "'></span>";
								echo "<span class='dashicons dashicons-trash delete-table-column tooltip-title' title='" . __( 'Delete' ) . "' data-type='" . esc_html( $type ) . "' data-meta-table='plugin' data-column='" . esc_html( $column ) . "'></span>";
								echo "<span class='dashicons dashicons-" . esc_html( $listAction ) . " add-remove-black-list tooltip-title' title='" . esc_html( $listActionTitle ) . "' data-action='" . esc_html( $listAction ) . "' data-type='" . esc_html( $type ) . "' data-meta-table='plugin' data-column='" . esc_html( $column ) . "'></span>";
								echo "<span class='dashicons dashicons-post-status change-table-index tooltip-title" . ( $indexExists ? ' active' : '' ) . "' title='" . __( 'Index', 'meta-optimizer' ) . "' data-type='" . esc_html( $type ) . "' data-column='" . esc_html( $column ) . "'></span>";
								echo "</td>";

								if ( $this->getOption( 'original_meta_actions', false ) == 1 ) {
									echo "<td class='change-icons'>";
									if ( $Helpers->checkCanChangeWPMetaKey( $type, $column ) ) {
										echo "<span class='dashicons dashicons-edit rename-table-column tooltip-title' title='" . __( 'Rename', 'meta-optimizer' ) . "' data-type='" . esc_html( $type ) . "' data-meta-table='origin' data-column='" . esc_html( $column ) . "'></span>";
										echo "<span class='dashicons dashicons-trash delete-table-column tooltip-title' title='" . __( 'Delete' ) . "' data-type='" . esc_html( $type ) . "' data-meta-table='origin' data-column='" . esc_html( $column ) . "'></span>";
									} else {
										echo '---';
									}
									echo "</td>";
								}

								echo "</tr>";
								$c ++;
							}
						else
							echo "<tr><td colspan='" . ( $this->getOption( 'original_meta_actions', false ) == 1 ? 5 : 4 ) . "'>" . __( 'Without custom field column', 'meta-optimizer' ) . "</td></tr>";
						?>
                        </tbody>
                    </table>
                    <br>
					<?php
				}
				?>
                <br>
                <form action="" method="post">
					<?php wp_nonce_field( 'reset_tables_submit', WPMETAOPTIMIZER_PLUGIN_KEY, false ); ?>
                    <input type="hidden" name="current_tab" value="tables">
                    <table class="reset-db-table">
                        <tr>
                            <th><?php _e( 'Reset Database tables', 'meta-optimizer' ) ?></th>
                            <td>
                                <strong>
									<?php _e( 'You can use this option to delete all plugin meta fields as well as data, then restart the import process . ', 'meta-optimizer' ) ?>
                                </strong>
                                <p class="description">
                                    <span class="description-notice">
                                        <?php _e( 'Be very careful with this command . It will empty the contents of your database table and there is no undo . ', 'meta-optimizer' ) ?>
                                    </span>
                                </p>

								<?php
								foreach ( $this->tables as $type => $table ) {
									?>
                                    <label>
                                        <input type="checkbox" name="reset_plugin_table_<?php echo esc_attr( $type ) ?>"
                                               value="1">
										<?php echo esc_html( $table['name'] ) . ' ( ' . $Helpers->getMetaTableName( $type ) . ' )' ?>
                                    </label>
                                    <label>
                                        <input type='checkbox' name='reset_import_<?php echo esc_attr( $type ) ?>'
                                               value='1'><?php _e( 'Run Import', 'meta-optimizer' ) ?>
                                    </label>
                                    <br>
									<?php
								}
								?>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <input type="submit" class="button button-primary button-large"
                                       value="<?php _e( 'Reset', 'meta-optimizer' ) ?>">
                            </td>
                        </tr>
                    </table>
                </form>
            </div>

            <div id="settings-tab-content"
                 class="wpmo-tab-content <?php echo $currentTab != 'settings' ? 'hidden' : '' ?>">
                <form action="" method="post">
                    <input type="hidden" name="current_tab" value="settings">
					<?php wp_nonce_field( 'settings_submit', WPMETAOPTIMIZER_PLUGIN_KEY, false ); ?>
                    <table>
                        <tbody>
                        <tr>
                            <th><?php _e( 'Support WordPress Query', 'meta-optimizer' ) ?></th>
                            <td>
                                <label><input type="checkbox" name="support_wp_query" id="support_wp_query"
                                              value="1" <?php checked( $this->getOption( 'support_wp_query', false ) == 1 ); ?> <?php disabled( ! $Helpers->checkImportFinished() ) ?>><?php _e( 'Active', 'meta-optimizer' ) ?>
                                </label>
                                <label><input type="checkbox" name="support_wp_query_active_automatically"
                                              id="support_wp_query_active_automatically"
                                              value="1" <?php checked( $this->getOption( 'support_wp_query_active_automatically', false ) == 1 ) ?>><?php _e( 'Active automatically after import completed', 'meta-optimizer' ) ?>
                                </label>
                                <label><input type="checkbox" name="support_wp_query_deactive_while_import"
                                              id="support_wp_query_deactive_while_import"
                                              value="1" <?php checked( $this->getOption( 'support_wp_query_deactive_while_import', false ) == 1 ) ?>><?php _e( 'Deactive while import process is run', 'meta-optimizer' ) ?>
                                </label>
                                <p class="description">
                                    <span class="description-notice">
                                        <?php _e( 'Apply a filter to the WordPress query. You can disable this option if you experience any problems with the results of your display posts.', 'meta-optimizer' ) ?>
                                    </span>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e( 'Save meta for', 'meta-optimizer' ) ?></td>
                            <td>
                                <input type="hidden" name="meta_save_types[hidden]" value="1">
								<?php
								foreach ( $this->tables as $type => $table ) {
									?>
                                    <label>
                                        <input type="checkbox"
                                               name="meta_save_types[<?php echo esc_attr( $type ) ?>]"
                                               value="1" <?php checked( isset( $metaSaveTypes[ $type ] ) ) ?>>
										<?php echo esc_html( $table['name'] ) ?>
                                    </label>
									<?php
								}
								?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e( 'Don\'t saving Meta in the default tables', 'meta-optimizer' ) ?></td>
                            <td>
                                <input type="hidden" name="dont_save_wpmeta[hidden]" value="1">
								<?php
								$defaultMetaSave = $this->getOption( 'dont_save_wpmeta', [] );
								foreach ( $this->tables as $type => $table ) {
									?>
                                    <label><input type="checkbox"
                                                  name="dont_save_wpmeta[<?php echo esc_attr( $type ) ?>]"
                                                  value="1" <?php checked( isset( $defaultMetaSave[ $type ] ) ) ?>> <?php echo esc_html( $table['name'] ) ?>
                                    </label>
									<?php
								}
								?>
                                <p class="description">
                                    <span class="description-notice">
                                        <?php _e( 'It is not recommended to activate this options.', 'meta-optimizer' ) ?>
                                    </span>
                                </p>
                                <p class="description">
									<?php _e( 'You can choose the Meta types if you do not want Meta saved in the default tables.', 'meta-optimizer' ) ?>
                                    <a href="https://developer.wordpress.org/plugins/metadata/" target="_blank">
										<?php _e( 'More information', 'meta-optimizer' ) ?>
                                    </a>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e( 'Post Types', 'meta-optimizer' ) ?></td>
                            <td>
                                <input type="hidden" name="post_types[hidden]" value="1">
								<?php
								$postTypesOption = $this->getOption( 'post_types', [] );
								foreach ( $postTypes as $postType ) {
									if ( ! in_array( $postType->name, $this->ignorePostTypes ) )
										echo '<label><input type="checkbox" name="post_types[' . esc_attr( $postType->name ) . ']" value="1" ' .
										     checked( $postTypesOption[ $postType->name ] ?? 0, 1, false ) . ( isset( $metaSaveTypes['post'] ) ? '' : ' disabled' ) . '/>' . esc_html( $postType->label ) . '</label> &nbsp;';
								}
								?>
                                <br>
                                <p class="description"><?php _e( 'You can save meta fields for specific post types.', 'meta-optimizer' ) ?></p>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <label for="original_meta_actions"><?php _e( 'Actions for original meta', 'meta-optimizer' ) ?></label>
                            </td>
                            <td>
                                <label><input type="checkbox" name="original_meta_actions"
                                              id="original_meta_actions"
                                              value="1" <?php checked( $this->getOption( 'original_meta_actions', false ) == 1 ) ?>><?php _e( 'Active', 'meta-optimizer' ) ?>
                                </label>
                                <p class="description"><?php _e( 'In the plugin tables tab, display actions for original meta keys.', 'meta-optimizer' ) ?></p>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <table>
                        <thead>
                        <tr>
                            <th>
								<?php _e( 'Black/White list', 'meta-optimizer' ) ?>
                            </th>
                            <td colspan="2">
								<?php _e( 'Set White/Black list for custom meta fields', 'meta-optimizer' ) ?>
                                <p class="description"><?php _e( 'You can\'t use the White list and Black list at the same time for each meta type, Write each item on a new line.', 'meta-optimizer' ) ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'Type' ) ?></th>
                            <th><?php _e( 'White List', 'meta-optimizer' ) ?></th>
                            <th><?php _e( 'Black List', 'meta-optimizer' ) ?></th>
                        </tr>
                        </thead>
                        <tbody>
						<?php
						foreach ( $this->tables as $type => $table ) {
							?>
                            <tr>
                                <td><?php echo esc_html( $table['title'] ) ?></td>
                                <td>
                                    <textarea name="<?php echo esc_attr( $type ) ?>_white_list"
                                              id="<?php echo esc_attr( $type ) ?>_white_list" cols="40" rows="7"
                                              class="ltr"
                                              placeholder="custom_field_name" <?php echo isset( $metaSaveTypes[ $type ] ) ? '' : ' disabled' ?>><?php echo esc_textarea( $this->getOption( $type . '_white_list', '' ) ) ?></textarea>
                                </td>
                                <td>
                                    <textarea name="<?php echo esc_attr( $type ) ?>_black_list"
                                              id="<?php echo esc_attr( $type ) ?>_black_list" cols="40" rows="7"
                                              class="ltr"
                                              placeholder="custom_field_name" <?php echo isset( $metaSaveTypes[ $type ] ) ? '' : ' disabled' ?>><?php echo esc_textarea( $this->getOption( $type . '_black_list', '' ) ) ?></textarea>
                                </td>
                            </tr>
							<?php
						}
						?>
                        <tr>
                            <td colspan="3">
                                <input type="submit" class="button button-primary button-large"
                                       value="<?php _e( 'Save' ) ?>">
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </form>
            </div>

            <div id="import-tab-content"
                 class="wpmo-tab-content <?php echo $currentTab != 'import' ? 'hidden' : '' ?>">
                <form action="" method="post">
                    <input type="hidden" name="current_tab" value="import">
					<?php wp_nonce_field( 'settings_submit', WPMETAOPTIMIZER_PLUGIN_KEY, false ); ?>
                    <table>
                        <tbody>
                        <tr>
                            <th colspan="2"><?php _e( 'Import Post/Comment/User/Term Metas from meta tables', 'meta-optimizer' ) ?></th>
                        </tr>
                        <tr>
                            <td>
                                <label for="import_items_number"><?php _e( 'Import items per run', 'meta-optimizer' ) ?></label>
                            </td>
                            <td>
                                <input type="number" name="import_items_number" id="import_items_number"
                                       class="small-text" step="1" min="1" max="30"
                                       value="<?php echo esc_attr( $this->getOption( 'import_items_number', WPMETAOPTIMIZER_DEFAULT_IMPORT_NUMBER ) ) ?>"
                                       placeholder="1">
                                <p class="description"><?php _e( 'The import scheduler runs every minute, and you can set the number of items to import.', 'meta-optimizer' ) ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'Meta Tables', 'meta-optimizer' ) ?></th>
                            <td>
                                <input type="hidden" name="import[hidden]" value="1">
								<?php
								$importTables = $this->getOption( 'import', [] );
								foreach ( $this->tables as $type => $table ) {
									$latestObjectID   = $this->getOption( 'import_' . $type . '_latest_id', false );
									$metaTypeCanSaved = isset( $metaSaveTypes[ $type ] );
									?>
                                    <label><input type="checkbox" name="import[<?php echo esc_attr( $type ) ?>]"
                                                  value="1" <?php checked( isset( $importTables[ $type ] ) );
										echo esc_html( $metaTypeCanSaved ) ? '' : ' disabled' ?>> <?php echo esc_html( $table['name'] ) . ' (' . $Helpers->getWPMetaTableName( $type ) . ')' ?>
                                    </label> <br>
									<?php
									if ( $metaTypeCanSaved && $latestObjectID ) {
										$checkedDate = $this->getOption( 'import_' . $type . '_checked_date', false );
										$checkedDate = $checkedDate ? ' (' . wp_date( 'Y-m-d H:i:s', strtotime( $checkedDate ) ) . ') ' : '';

										echo '<div class="blue-alert">';
										if ( $latestObjectID === 'finished' ) {
											echo __( 'Finished', 'meta-optimizer' ) . esc_html( $checkedDate );

										} elseif ( is_numeric( $latestObjectID ) ) {
											$leftItems   = $Helpers->getObjectLeftItemsCount( $type );
											$objectTitle = $objectLink = false;

											if ( $type == 'post' ) {
												$objectTitle = get_the_title( $latestObjectID );
												$objectLink  = get_edit_post_link( $latestObjectID );

											} elseif ( $type == 'comment' ) {
												$comment     = get_comment( $latestObjectID );
												$objectTitle = $comment->comment_author . ' - ' . $comment->comment_author_email;
												$objectLink  = get_edit_comment_link( $latestObjectID );

											} elseif ( $type == 'user' ) {
												$user        = get_userdata( $latestObjectID );
												$objectTitle = $user->display_name;
												$objectLink  = get_edit_user_link( $latestObjectID );

											} elseif ( $type == 'term' ) {
												if ( $term = get_term( $latestObjectID ) )
													$objectTitle = $term->name;
												$objectLink = get_edit_term_link( $latestObjectID );
											}

											if ( $objectTitle && $objectLink ) {
												echo __( 'The last item checked:', 'meta-optimizer' ) . " <a href='$objectLink' target='_blank'>$objectTitle</a> $checkedDate";
											} else {
												echo __( 'Unknown item', 'meta-optimizer' ) . " $checkedDate";
											}

											if ( $leftItems ) {
												echo sprintf( '<br>%s %d', esc_html__( 'Left Items:', 'meta-optimizer' ), $leftItems );
												echo sprintf( ', %s %s', esc_html__( 'Estimate Import Time:', 'meta-optimizer' ), $this->estimateImportTime( $leftItems ) );
											}
										}

										echo "<br><label><input type='checkbox' name='reset_import_" . esc_attr( $type ) . "' value='1'> " . __( 'Reset', 'meta-optimizer' ) . '</label>';
										echo '</div>';
									}
									echo '<br>';
								}
								?>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <p class="description"><?php _e( 'Importing runs in the background without requiring a website to be open.', 'meta-optimizer' ) ?></p>
								<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
									echo '<p class="description"><span class="description-notice">' . __( 'WP Cron disabled by <code>define(\'DISABLE_WP_CRON\', true);</code> in wp-config.php, Please make sure your site run WP cron manually.', 'meta-optimizer' ) . '</span></p>';
								} ?>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <input type="submit" class="button button-primary button-large"
                                       value="<?php _e( 'Save' ) ?>">
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </form>
            </div>
            <div id="tools-tab-content"
                 class="wpmo-tab-content <?php echo $currentTab != 'tools' ? 'hidden' : '' ?>">
                <form action="" method="post">
                    <input type="hidden" name="current_tab" value="tools">
					<?php wp_nonce_field( 'settings_submit', WPMETAOPTIMIZER_PLUGIN_KEY, false ); ?>
                    <table>
                        <tr>
                            <th colspan="2"><?php _e( 'Optimize WordPress', 'meta-optimizer' ) ?></th>
                        </tr>
                        <tr>
                            <td><?php _e( 'Quick draft widget', 'meta-optimizer' ) ?></td>
                            <td>
								<?php
								$this->customCheckbox( array(
									'name'        => 'disable_quick_draft_widget',
									'title'       => __( 'Disable quick draft dashboard widget', 'meta-optimizer' ),
									'description' => __( 'Auto draft records will not appear in the post database table when the quick draft widget is disabled.', 'meta-optimizer' ),
									'badge'       => __( 'Recommended', 'meta-optimizer' ),
									'badge_class' => 'blue-badge',
									'checked'     => $this->getOption( 'disable_quick_draft_widget', false ) == 1
								) );
								?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php _e( 'Post Revisions', 'meta-optimizer' ) ?></td>
                            <td>
								<?php
								$this->customCheckbox( array(
									'name'        => 'disable_post_revisions',
									'title'       => __( 'Disable post revisions', 'meta-optimizer' ),
									'description' => __( 'Revisions are old versions of posts and pages. You can prevent the creation of revisions.', 'meta-optimizer' ),
									'badge_class' => 'blue-badge',
									'checked'     => $this->getOption( 'disable_post_revisions', false ) == 1
								) );
								?>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <input type="submit" class="button button-primary button-large"
                                       value="<?php _e( 'Save' ) ?>">
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
            <div id="optimize-tab-content"
                 class="wpmo-tab-content <?php echo $currentTab != 'optimize' ? 'hidden' : '' ?>">
				<?php
				$optimizeItems = 0;

				$orphanedRelationships = Optimize::getOrphanedRelationshipsCount();
				$optimizeItems         = $orphanedRelationships > 0 ? ++ $optimizeItems : $optimizeItems;
				$revisionsCount        = Optimize::getPostsCount( 'revision' );
				$optimizeItems         = $revisionsCount > 0 ? ++ $optimizeItems : $optimizeItems;
				$trashCount            = Optimize::getPostsCount( null, 'trash' );
				$optimizeItems         = $trashCount > 0 ? ++ $optimizeItems : $optimizeItems;
				$autoDraftCount        = Optimize::getPostsCount( null, 'auto-draft' );
				$optimizeItems         = $autoDraftCount > 0 ? ++ $optimizeItems : $optimizeItems;
				$transientExpiredCount = Optimize::getExpiredTransientsCount();
				$optimizeItems         = $transientExpiredCount > 0 ? ++ $optimizeItems : $optimizeItems;
				$dbTablesCount         = Optimize::getDatabaseTablesCount();
				$optimizeItems         = $dbTablesCount > 0 ? ++ $optimizeItems : $optimizeItems;
				?>
                <form action="" method="post">
                    <input type="hidden" name="current_tab" value="optimize">
					<?php wp_nonce_field( 'optimize_submit', WPMETAOPTIMIZER_PLUGIN_KEY, false ); ?>
                    <table>
                        <tr>
                            <th colspan="2"><?php _e( 'Optimize your WordPress database', 'meta-optimizer' ) ?></th>
                        </tr>
						<?php foreach ( $this->tables as $type => $table ) {
							$orphanedMetaCount = Optimize::getOrphanedMetaCount( $type );
							$optimizeItems     = $orphanedMetaCount && $orphanedMetaCount > 0 ? ++ $optimizeItems : $optimizeItems;
							?>
                            <tr>
                                <th><?php
									echo sprintf( __( 'Orphaned %s', 'meta-optimizer' ), $table['title'] ); ?></th>
                                <td>
									<?php
									$this->customCheckbox( array(
										'name'         => 'orphaned_' . $type . '_meta',
										'title'        => sprintf( __( 'Orphaned %s are data about deleted %s. This data is safe to delete.', 'meta-optimizer' ), $table['title'], $table['plural_name'] ),
										'count'        => $orphanedMetaCount,
										'badge'        => __( 'Optimized', 'meta-optimizer' ),
										'disabled'     => $orphanedMetaCount == 0,
										'class'        => 'wpmo-checkbox-red',
										'enabled_save' => false
									) );
									?>
                                </td>
                            </tr>
						<?php } ?>
                        <tr>
                            <th><?php _e( 'Orphaned Term Relationships', 'meta-optimizer' ) ?></th>
                            <td>
								<?php
								$this->customCheckbox( array(
									'name'         => 'delete_orphaned_term_relationships',
									'title'        => __( 'Orphaned term relationships. This data is safe to delete.', 'meta-optimizer' ),
									'count'        => $orphanedRelationships,
									'badge'        => __( 'Optimized', 'meta-optimizer' ),
									'disabled'     => $orphanedRelationships == 0,
									'class'        => 'wpmo-checkbox-red',
									'enabled_save' => false
								) );
								?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'Revisions', 'meta-optimizer' ) ?></th>
                            <td>
								<?php
								$this->customCheckbox( array(
									'name'         => 'delete_revisions_posts',
									'title'        => __( 'Revisions are old versions of posts and pages. You can safely delete these unless you know you have screwed something up and need to revert to an older version.', 'meta-optimizer' ),
									'count'        => $revisionsCount,
									'badge'        => __( 'Optimized', 'meta-optimizer' ),
									'disabled'     => $revisionsCount == 0,
									'class'        => 'wpmo-checkbox-red',
									'enabled_save' => false
								) );
								?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'Trashed Posts', 'meta-optimizer' ) ?></th>
                            <td>
								<?php
								$this->customCheckbox( array(
									'name'         => 'delete_trash_posts',
									'title'        => __( 'Trashed posts refer to posts, pages, and other types of posts that have been trashed and are awaiting permanent deletion.', 'meta-optimizer' ),
									'count'        => $trashCount,
									'badge'        => __( 'Optimized', 'meta-optimizer' ),
									'disabled'     => $trashCount == 0,
									'class'        => 'wpmo-checkbox-red',
									'enabled_save' => false
								) );
								?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'Auto-drafts', 'meta-optimizer' ) ?></th>
                            <td>
								<?php
								$this->customCheckbox( array(
									'name'         => 'delete_auto_draft_posts',
									'title'        => __( 'The auto-drafts are automatically saved when you begin editing posts or pages in WordPress. Eventually, you may have many auto-drafts that you won\'t publish, so you can delete them.', 'meta-optimizer' ),
									'count'        => $autoDraftCount,
									'badge'        => __( 'Optimized', 'meta-optimizer' ),
									'disabled'     => $autoDraftCount == 0,
									'class'        => 'wpmo-checkbox-red',
									'enabled_save' => false
								) );
								?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'Expired Transients', 'meta-optimizer' ) ?></th>
                            <td>
								<?php
								$this->customCheckbox( array(
									'name'         => 'delete_expired_transients',
									'title'        => __( 'Temporary data is stored in a database as transients. There is no need for expired transients.', 'meta-optimizer' ),
									'count'        => $transientExpiredCount,
									'badge'        => __( 'Optimized', 'meta-optimizer' ),
									'disabled'     => $transientExpiredCount == 0,
									'class'        => 'wpmo-checkbox-red',
									'enabled_save' => false
								) );
								?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e( 'Optimize Tables', 'meta-optimizer' ) ?></th>
                            <td>
								<?php
								$this->customCheckbox( array(
									'name'         => 'optimize_db_tables',
									'title'        => __( 'Reduces storage space and improves database speed by reorganizing the physical storage of database data.', 'meta-optimizer' ),
									'count'        => $dbTablesCount,
									'count_title'  => __( 'Database Tables', 'meta-optimizer' ),
									'enabled_save' => false
								) );
								?>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <input type="submit" class="button button-primary button-large"
                                       value="<?php _e( 'Optimize Database', 'meta-optimizer' ) ?>" <?php disabled( $optimizeItems == 0 ) ?>>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
        </div>
		<?php
	}

	/**
	 * Print custom checkbox
	 *
	 * @param array $args
	 *
	 * @return string|void
	 */
	function customCheckbox( $args ) {
		$defaults = array(
			'name'         => '',
			'title'        => '',
			'description'  => '',
			'value'        => 1,
			'count'        => 0,
			'count_title'  => __( 'Items', 'meta-optimizer' ),
			'badge'        => '',
			'badge_class'  => 'green-badge',
			'checked'      => false,
			'disabled'     => false,
			'enabled_save' => true,
			'class'        => '',
			'echo'         => true
		);

		$args = wp_parse_args( $args, $defaults );

		if ( empty( $args['name'] ) || empty( $args['title'] ) )
			return '';

		ob_start();
		?>
        <div class="wpmo-checkbox <?php echo $args['class'] ?>">
            <input type="checkbox" name="<?php echo $args['name'] ?>"
                   id="<?php echo $args['name'] ?>" <?php echo $args['enabled_save'] ? '' : 'class="dont-enabled"' ?>
                   value="<?php echo $args['value'] ?>" <?php disabled( $args['disabled'] ) ?> <?php checked( $args['checked'] ) ?> >
            <label for="<?php echo $args['name'] ?>">
                <span class="label"><?php echo $args['title'] ?></span>
				<?php
				if ( ! empty( $args['description'] ) )
					echo '<span class="description">' . $args['description'] . '</span>';
				?>
				<?php if ( $args['count'] > 0 ) { ?>
                    <span class="item-count"><?php echo $args['count'] ?></span>
					<?php
					if ( ! empty( $args['count_title'] ) )
						echo '<span>' . $args['count_title'] . '</span>';
					?>
				<?php } elseif ( ! empty( $args['badge'] ) ) { ?>
                    <span class="badge <?php echo $args['badge_class'] ?>"><?php echo $args['badge'] ?></span>
				<?php } ?>
            </label>
        </div>
		<?php

		$html = ob_get_clean();

		if ( $args['echo'] )
			echo $html;
		else
			return $html;
	}

	/**
	 * Get estimate import time
	 *
	 * @param int $leftItems Left items count
	 *
	 * @return false|string
	 */
	function estimateImportTime( $leftItems ) {
		$leftItems = intval( $leftItems );
		$number    = intval( $this->getOption( 'import_items_number', WPMETAOPTIMIZER_DEFAULT_IMPORT_NUMBER ) );
		$minutes   = 1;
		if ( $leftItems > $number )
			$minutes = intval( $leftItems / $number );

		return Helpers::secondsToHumanReadable( $minutes * 60 );
	}

	/**
	 * Get option value
	 *
	 * @param string  $key      Option key
	 * @param mixed   $default  Default value
	 * @param boolean $useCache Use cache
	 *
	 * @return mixed
	 */
	public function getOption( $key = null, $default = null, $useCache = true ) {
		$options = wp_cache_get( 'options', WPMETAOPTIMIZER_PLUGIN_KEY );

		if ( ! $useCache || $options === false ) {
			$options = get_option( WPMETAOPTIMIZER_OPTION_KEY );
			wp_cache_set( 'options', $options, WPMETAOPTIMIZER_PLUGIN_KEY, WPMETAOPTIMIZER_CACHE_EXPIRE );
		}

		if ( $key != null )
			return $options[ $key ] ?? $default;

		return $options ?: $default;
	}

	/**
	 * Set plugin option
	 *
	 * @param string $key   Option key
	 * @param mixed  $value Option value
	 *
	 * @return boolean
	 */
	public function setOption( $key, $value ) {
		$options = $this->getOption( null, [], false );
		if ( strpos( $key, '_white_list' ) !== false || strpos( $key, '_black_list' ) !== false )
			$value = sanitize_textarea_field( $value );
		else
			$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
		$options[ $key ] = $value;

		wp_cache_delete( 'options', WPMETAOPTIMIZER_PLUGIN_KEY );

		return update_option( WPMETAOPTIMIZER_OPTION_KEY, $options, false );
	}

	/**
	 * Get notice message HTML
	 *
	 * @param string $message Message text
	 * @param string $status  Message status text
	 *
	 * @return string
	 */
	private function getNoticeMessageHTML( $message, $status = 'success' ) {
		return '<div class="notice notice-' . $status . ' is-dismissible" ><p>' . $message . '</p></div> ';
	}

	/**
	 * Returns an instance of class
	 *
	 * @return Options
	 */
	static function getInstance() {
		if ( self::$instance == null )
			self::$instance = new Options();

		return self::$instance;
	}
}
