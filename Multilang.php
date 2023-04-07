<?php

class Multilang {
	private int $sites_count;
	private int $current_blog_id;
	private int $to_blog_id;

	public function __construct() {
		$this->sites_count     = $this->sites_count();
		$this->current_blog_id = get_current_blog_id();

		register_activation_hook( __DIR__ . '/multilang-plugin.php', array( $this, 'activation' ) );

		add_action( 'init', array( $this, 'init' ), 99 );
		add_action( 'wp_head', array( $this, 'add_alternate_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'network_admin_menu', array( $this, 'add_menu_item' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'delete_post', array( $this, 'remove_post_to_post' ), 10, 2 );
		add_action( 'delete_term', array( $this, 'remove_term_to_term' ), 10, 1 );

		add_action( 'wp_ajax_mlt_generate', array( $this, 'generate' ) );
		add_action( 'wp_ajax_mlt_add_post_by_id', array( $this, 'add_post_by_id' ) );
		add_action( 'wp_ajax_mlt_remove_id', array( $this, 'remove_by_id' ) );

		// custom columns
		add_filter( 'manage_posts_columns', array( $this, 'add_post_column' ) );
		add_filter( 'manage_pages_columns', array( $this, 'add_post_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'post_column_content' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'post_column_content' ), 10, 2 );
	}

	public function init() {
		$taxonomies = get_taxonomies();
		unset( $taxonomies['nav_menu'] );
		unset( $taxonomies['link_category'] );
		unset( $taxonomies['post_format'] );
		unset( $taxonomies['wp_theme'] );
		unset( $taxonomies['wp_template_part_area'] );

		foreach ( $taxonomies as $taxonomy ) {
			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_post_column' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'tax_column_content' ), 10, 3 );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'tax_edit_form_content' ), 99, 2 );
		}
	}

	public function get_languages() : array {
		if ( $this->sites_count < 2 ) {
			return [];
		}

		$list      = [];
		$object_id = get_queried_object_id();

		foreach ( $this->get_sites() as $site ) {
			$id = $this->get_id_from_table( $object_id, $site->blog_id, 'mlt_post_to_post' );
			if ( ! $id ) {
				continue;
			}

			switch_to_blog( $site->blog_id );
			$url    = get_permalink( $id );
			$locale = get_option( 'WPLANG' );
			restore_current_blog();

			$locale = strtolower( str_replace( '_', '-', $locale ) );

			$list["blog_$site->blog_id"] = array(
				'name'    => get_network_option( 1, "mlt_lang_{$site->blog_id}" ),
				'blog_id' => $site->blog_id,
				'post_id' => $id,
				'url'     => $url,
				'locale'  => $locale,
				'current' => $this->current_blog_id == $site->blog_id
			);

		}

		return $list;
	}

	public function add_alternate_links() {
		$languages = $this->get_languages();
		if ( empty( $languages ) ) {
			return;
		}

		foreach ( $languages as $language ) {
			switch_to_blog( $language['blog_id'] );

			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />',
				esc_attr( $language['locale'] ),
				esc_attr( $language['url'] )
			);

			restore_current_blog();
		}
	}

	public function create_tables() {
		global $wpdb;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$tables = array(
			'post_to_post',
			'media_to_media',
			'term_to_term',
		);

		foreach ( $tables as $table ) {
			$sql_table = "CREATE TABLE mlt_{$table} ( id int(11) NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) {$wpdb->get_charset_collate()}";
			dbDelta( $sql_table );

			foreach ( get_sites() as $site ) {
				$sql_column = "ALTER TABLE mlt_{$table} ADD COLUMN blog_{$site->blog_id} int(11) NULL";
				$wpdb->query( $sql_column );
			}
		}
	}

	public function activation() {
		if ( ! is_multisite() ) {
			wp_die( __( 'Multisite not activated!' ) );
		}

		$this->create_tables();
	}

	public function add_scripts() {
		wp_enqueue_style( 'multilang-style', plugin_dir_url( __FILE__ ) . 'assets/style.css', [], filemtime( __DIR__ . '/assets/style.css' ) );
		wp_enqueue_script( 'multilang-script', plugin_dir_url( __FILE__ ) . 'assets/script.js', [], filemtime( __DIR__ . '/assets/script.js' ), true );
		wp_localize_script( 'multilang-script', 'mlt', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'mlt-nonce' )
		) );
	}

	public function notice() {
		if ( ! is_multisite() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				__( '<strong>Multisite Translator:</strong> Multisite not activated!' )
			);
		}
	}

	public function field( $args ) {
		$value = get_network_option( 1, $args['name'] );

		printf(
			'<input type="text" id="%s" name="%s" value="%s">',
			esc_attr( $args['name'] ),
			esc_attr( $args['name'] ),
			sanitize_text_field( $value )
		);
	}

	public function register_settings() {
		add_settings_section(
			'multilang_settings_section',
			'Multilang Settings',
			'',
			'multilang'
		);
		foreach ( $this->get_sites() as $site ) {
			register_setting( 'multilang_settings', "mlt_lang_{$site->blog_id}", 'sanitize_text_field' );
			add_settings_field(
				"mlt_lang_{$site->blog_id}",
				"Site {$site->blog_id}",
				array( $this, 'field' ),
				'multilang',
				'multilang_settings_section',
				array(
					'name' => "mlt_lang_{$site->blog_id}"
				)
			);
		}

	}

	public function settings_page() {
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'update' ) {
			foreach ( $this->get_sites() as $site ) {
				update_network_option( 1, "mlt_lang_{$site->blog_id}", sanitize_text_field( $_POST["mlt_lang_{$site->blog_id}"] ) );
			}
		}
		?>
        <div class="wrap">
            <h2><?php _e( 'Multilang' ); ?></h2>
            <form method="post" action="admin.php?page=multilang">
				<?php settings_fields( 'multilang_settings' ); ?>
				<?php do_settings_sections( 'multilang' ); ?>
				<?php submit_button(); ?>
            </form>
        </div>
		<?php
	}

	public function add_menu_item() {
		add_menu_page(
			'Multilang',
			'Multilang',
			'manage_network_options',
			'multilang',
			array( $this, 'settings_page' ),
			'dashicons-translation'
		);
	}

	public function add_post_column( $columns ) {
		if ( $this->sites_count < 2 ) {
			return $columns;
		}

		foreach ( $this->get_sites() as $site ) {
			if ( (int) $site->blog_id === $this->current_blog_id ) {
				continue;
			}

			$name             = "mlt_lang_{$site->blog_id}";
			$style            = "<style>.column-$name { width: 120px; }</style>";
			$columns[ $name ] = strtoupper( get_network_option( 1, $name ) ) . $style;
		}

		return $columns;
	}

	public function tax_column_content( $content, $column_name, $term_id ) {
		if ( $this->sites_count < 2 ) {
			return;
		}

		foreach ( $this->get_sites() as $site ) {
			$blog_id = (int) $site->blog_id;

			if ( $blog_id === $this->current_blog_id ) {
				continue;
			}

			$name = "mlt_lang_$blog_id";

			if ( $column_name === $name ) {
				if ( $to_term_id = (int) $this->get_id_from_table( $term_id, $blog_id, 'mlt_term_to_term' ) ) {
					echo $this->button_edit( $to_term_id, $blog_id, 'term' );
					echo $this->button_remove( $term_id, $to_term_id, $blog_id, 'term' );
				} elseif ( $this->current_blog_id === 1 ) {
					echo $this->button_clone( $term_id, $blog_id, 'term' );
					echo $this->button_add_by_id( $term_id, $blog_id, 'term' );
				} else {
					echo '-';
				}
			}
		}
	}

	public function tax_edit_form_content( $term, $taxonomy ) {
		if ( $this->sites_count < 2 ) {
			return;
		}

		echo '<tr><th><h2>Multilang</h2></th></tr>';

		foreach ( $this->get_sites() as $site ) {
			$blog_id = (int) $site->blog_id;
			if ( $blog_id === $this->current_blog_id ) {
				continue;
			}

			$name = "mlt_lang_{$blog_id}";

			echo '<tr class="form-field">';
			echo '<th scope="row" style="vertical-align: middle">' . strtoupper( get_network_option( 1, $name ) ) . '</th>';
			echo '<td style="display: inline-block">';
			if ( $to_term_id = (int) $this->get_id_from_table( $term->term_id, $blog_id, 'mlt_term_to_term' ) ) {
				echo $this->button_edit( $to_term_id, $blog_id, 'term' );
				echo $this->button_remove( $term->term_id, $to_term_id, $blog_id, 'term' );
			} elseif ( $this->current_blog_id === 1 ) {
				echo $this->button_clone( $term->term_id, $blog_id, 'term' );
				echo $this->button_add_by_id( $term->term_id, $site->blog_id, 'term' );
			} else {
				echo '-';
			}
			echo '</td>';
			echo '</tr>';
		}
	}

	public function post_column_content( $column_name, $post_id ) {
		if ( $this->sites_count < 2 ) {
			return;
		}

		foreach ( $this->get_sites() as $site ) {
			$blog_id = (int) $site->blog_id;

			if ( $blog_id === $this->current_blog_id ) {
				continue;
			}

			$name = "mlt_lang_$blog_id";

			if ( $column_name === $name ) {
				if ( $to_post_id = (int) $this->get_id_from_table( $post_id, $blog_id, 'mlt_post_to_post' ) ) {
					echo $this->button_edit( $to_post_id, $blog_id );
					echo $this->button_remove( $post_id, $to_post_id, $blog_id );
				} elseif ( $this->current_blog_id === 1 ) {
					echo $this->button_clone( $post_id, $blog_id );
					echo $this->button_add_by_id( $post_id, $blog_id );
				} else {
					echo '-';
				}
			}
		}
	}

	public function meta_box_content( $post ) {
		echo '<div class="mlt-metabox">';
		foreach ( $this->get_sites() as $site ) {
			if ( (int) $site->blog_id === $this->current_blog_id ) {
				continue;
			}

			$name       = "mlt_lang_{$site->blog_id}";
			$label      = strtoupper( get_network_option( 1, $name ) );
			$to_post_id = (int) $this->get_id_from_table( $post->ID, $site->blog_id, 'mlt_post_to_post' );
			$html       = '-';
			if ( $to_post_id ) {
				$html = $this->button_edit( $to_post_id, $site->blog_id );
				$html .= $this->button_remove( $post->ID, $to_post_id, $site->blog_id );
			} elseif ( $this->current_blog_id === 1 ) {
				$html = $this->button_clone( $post->ID, $site->blog_id );
				$html .= $this->button_add_by_id( $post->ID, $site->blog_id );
			}

			printf(
				'<span><strong>%s</strong></span><span>%s</span>',
				esc_html( $label ),
				$html
			);
		}
		echo '</div>';
	}

	public function add_meta_boxes( $post_type ) {
		if ( $this->sites_count < 2 || $post_type === 'acf-field-group' ) {
			return;
		}

		add_meta_box(
			'mlt_box',
			__( 'Multilang' ),
			array( $this, 'meta_box_content' ),
			$post_type,
			'side',
			'high',
			array( '__back_compat_meta_box' => false )
		);
	}

	public function remove_term_to_term( $term_id ) {
		$this->remove_id_from_table( $term_id, 'mlt_term_to_term' );
	}

	public function remove_post_to_post( $post_id, $post ) {
		if ( $post->post_type === 'attachment' ) {
			$table = 'mlt_media_to_media';
		} else {
			$table = 'mlt_post_to_post';
		}

		$this->remove_id_from_table( $post_id, $table );
	}

	public function remove_by_id() {
		if ( ! $this->check_security() ) {
			wp_send_json_error( 'Nonce error.' );
		}

		$from_id = absint( $_POST['from_post_id'] );
		$id      = absint( $_POST['post_id'] );
		$blog_id = absint( $_POST['blog_id'] );
		$type    = sanitize_text_field( $_POST['type'] );

		if ( ! $from_id || ! $id || ! $blog_id ) {
			wp_send_json_error( 'ID error.' );
		}

		$table_name = $type === 'term' ? 'mlt_term_to_term' : 'mlt_post_to_post';

		$this->remove_id_from_table( $id, $blog_id, $table_name );

		$data = $this->button_clone( $from_id, $blog_id, $type );
		$data .= $this->button_add_by_id( $from_id, $blog_id, $type );

		wp_send_json_success( $data );
	}

	public function add_post_by_id() {
		if ( ! $this->check_security() ) {
			wp_send_json_error( 'Nonce error.' );
		}

		$new_id  = absint( $_POST['new_post_id'] );
		$id      = absint( $_POST['post_id'] );
		$blog_id = absint( $_POST['blog_id'] );
		$type    = sanitize_text_field( $_POST['type'] );

		if ( ! $new_id || ! $id || ! $blog_id ) {
			wp_send_json_error( 'ID error.' );
		}

		if ( $type === 'term' ) {
			switch_to_blog( $blog_id );
			$term = get_term( $new_id );
			restore_current_blog();
			if ( ! $term ) {
				wp_send_json_error( 'Term ID error.' );
			} else {
				$this->add_id_to_table( $id, $new_id, $blog_id, 'mlt_term_to_term' );
				$data = $this->button_edit( $new_id, $blog_id, 'term' );
				$data .= $this->button_remove( $id, $new_id, $blog_id, 'term' );
				wp_send_json_success( $data );
			}
		}

		switch_to_blog( $blog_id );

		$post = get_post( $new_id );

		restore_current_blog();

		if ( ! $post ) {
			wp_send_json_error( 'ID error.' );
		}

		$this->add_id_to_table( $id, $new_id, $blog_id, 'mlt_post_to_post' );

		$data = $this->button_edit( $new_id, $blog_id );
		$data .= $this->button_remove( $id, $new_id, $blog_id, $type );

		wp_send_json_success( $data );
	}

	public function generate() {
		if ( ! $this->check_security() ) {
			wp_send_json_error( 'Nonce error.' );
		}

		$id      = absint( $_POST['post_id'] );
		$blog_id = absint( $_POST['blog_id'] );
		$type    = sanitize_text_field( $_POST['type'] );

		if ( ! $id || ! $blog_id ) {
			wp_send_json_error( 'ID error.' );
		}

		$this->to_blog_id = $blog_id;

		if ( $type === 'term' ) {
			$new_term_id = (int) $this->clone_term( $id, $blog_id );
			$data        = $this->button_edit( $new_term_id, $blog_id, 'term' );
			$data        .= $this->button_remove( $id, $new_term_id, $blog_id, 'term' );
			wp_send_json_success( $data );
		}

		$post            = get_post( $id );
		$post_taxonomies = get_post_taxonomies( $post );
		$new_post_data   = array(
			'post_type'    => $post->post_type,
			'post_title'   => $post->post_title,
			'post_status'  => 'draft',
			'post_content' => $this->parse_content( $post->post_content ), // clone media files
			'meta_input'   => $this->clone_acf_fields( $id )
		);

		// clone terms
		if ( ! empty( $post_taxonomies ) ) {
			foreach ( $post_taxonomies as $post_taxonomy ) {
				$post_terms = get_the_terms( $post, $post_taxonomy );
				if ( ! empty( $post_terms ) ) {
					foreach ( $post_terms as $post_term ) {
						$new_term_id = (int) $this->clone_term( $post_term->term_id, $blog_id );
						if ( ! $new_term_id ) {
							continue;
						}

						switch ( $post_taxonomy ) {
							case 'category':
								$new_post_data['post_category'][] = $new_term_id;
								break;
							default:
								$new_post_data['tax_input'][ $post_taxonomy ][] = $new_term_id;
						}
					}
				}
			}
		}

		// clone page template
		if ( $page_template = get_post_meta( $id, '_wp_page_template', true ) ) {
			$new_post_data['meta_input']['_wp_page_template'] = $page_template;
		}

		switch_to_blog( $blog_id );

		$new_post_id = wp_insert_post( $new_post_data, true );

		restore_current_blog();

		if ( is_wp_error( $new_post_id ) ) {
			wp_send_json_error( $new_post_id->get_error_message() );
		}

		$this->add_id_to_table( $id, $new_post_id, $blog_id, 'mlt_post_to_post' );

		$data = $this->button_edit( $new_post_id, $blog_id );
		$data .= $this->button_remove( $id, $new_post_id, $blog_id, $type );

		wp_send_json_success( $data );
	}

	private function get_sites() {
		return get_sites(
			array(
				'public'   => 1,
				'archived' => 0,
				'mature'   => 0,
				'spam'     => 0,
				'deleted'  => 0
			)
		);
	}

	private function sites_count() : int {
		return count( $this->get_sites() );
	}

	private function button_clone( $id, $blog_id, $type = 'post' ) : string {
		if ( ! $id || ! $blog_id ) {
			return '';
		}

		return sprintf(
			'<div class="mlt-translate"><button class="mltTranslate button button-primary" data-post_id="%s" data-blog_id="%s" data-type="%s">Clone</button><span class="spinner"></span></div>',
			esc_attr( $id ),
			esc_attr( $blog_id ),
			esc_attr( $type )
		);
	}

	private function button_edit( $id, $blog_id, $type = 'post' ) : string {
		if ( ! $id || ! $blog_id ) {
			return '';
		}

		switch_to_blog( $blog_id );

		$link = sprintf(
			'<a class="button button-secondary" href="%s">Edit</a>',
			$type === 'term' ? get_edit_term_link( $id ) : get_edit_post_link( $id )
		);

		switch_to_blog( $this->current_blog_id );

		return $link;
	}

	private function button_remove( $from_id, $to_id, $blog_id, $type = 'post' ) : string {
		if ( ! $from_id || ! $to_id || ! $blog_id || $this->current_blog_id !== 1 ) {
			return '';
		}

		return sprintf(
			'<button class="button button-secondary mltRemoveId" data-from_post_id="%s" data-post_id="%s" data-blog_id="%s" data-type="%s">&times;</button><span class="spinner"></span>',
			esc_attr( $from_id ),
			esc_attr( $to_id ),
			esc_attr( $blog_id ),
			esc_attr( $type )
		);
	}

	private function button_add_by_id( $from_id, $blog_id, $type = 'post' ) : string {
		if ( ! $from_id || ! $blog_id ) {
			return '';
		}

		return sprintf(
			'<div class="mlt-add-by-id"><input type="number" name="add_id" placeholder="Add by ID"><button class="mltAddById button button-secondary" data-post_id="%s" data-blog_id="%s" data-type="%s">+</button><span class="spinner"></span></div>',
			esc_attr( $from_id ),
			esc_attr( $blog_id ),
			esc_attr( $type )
		);
	}

	private function get_row_from_table( $id, $table ) {
		if ( ! $id || ! $table ) {
			return [];
		}

		global $wpdb;

		return $wpdb->get_results( "SELECT * FROM {$table} WHERE blog_{$this->current_blog_id} = {$id}" );
	}

	private function get_id_from_table( $id, $blog_id, $table ) {
		if ( ! $id || ! $blog_id || ! $table ) {
			return null;
		}

		global $wpdb;
		$column_name = "blog_$blog_id";
		$results     = $wpdb->get_results( "SELECT {$column_name} FROM {$table} WHERE blog_{$this->current_blog_id} = {$id}" );

		return $results[0]->$column_name ?? null;
	}

	private function remove_id_from_table( $id, $blog_id, $table ) {
		if ( ! $id || ! $table ) {
			return;
		}

		if ( ! $blog_id ) {
			$blog_id = $this->current_blog_id;
		}

		global $wpdb;

		if ( $blog_id === 1 ) {
			$wpdb->delete( $table, array( 'blog_1' => $id ) );
		} else {
			$wpdb->update(
				$table,
				array( "blog_{$blog_id}" => null ),
				array( "blog_{$blog_id}" => $id )
			);
		}
	}

	private function add_id_to_table( $from_id, $to_id, $to_blog_id, $table ) {
		if ( ! $from_id || ! $to_id || ! $to_blog_id || ! $table ) {
			return;
		}

		global $wpdb;
		$row = $this->get_row_from_table( $from_id, $table );

		if ( ! empty( $row ) ) {
			$wpdb->update(
				$table,
				array( "blog_{$to_blog_id}" => $to_id ),
				array( "blog_1" => $from_id )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'blog_1'             => $from_id,
					"blog_{$to_blog_id}" => $to_id
				)
			);
		}
	}

	private function clone_media( $media_id, $to_blog_id ) {
		if ( empty( $media_id ) || ! $to_blog_id ) {
			return null;
		}

		if ( is_array( $media_id ) ) {
			$new_media_ids = [];
			foreach ( $media_id as $id ) {
				$new_media_ids[] = $this->clone_media( $id, $to_blog_id );
			}

			return $new_media_ids;
		}

		$table_name   = 'mlt_media_to_media';
		$new_media_id = $this->get_id_from_table( $media_id, $to_blog_id, $table_name );

		if ( $new_media_id ) {
			return $new_media_id;
		}

		$media     = get_post( $media_id );
		$file_path = get_attached_file( $media_id );

		switch_to_blog( $to_blog_id );

		$file_name     = basename( $file_path );
		$new_file_path = wp_upload_dir()['basedir'] . '/' . $file_name;

		if ( copy( $file_path, $new_file_path ) ) {
			$new_media = array(
				'post_title'     => $media->post_title,
				'post_content'   => $media->post_content,
				'post_status'    => $media->post_status,
				'post_mime_type' => $media->post_mime_type,
			);

			$new_media_id = wp_insert_attachment( $new_media, $new_file_path );

			wp_update_attachment_metadata( $new_media_id, wp_generate_attachment_metadata( $new_media_id, $new_file_path ) );
		}

		restore_current_blog();

		$this->add_id_to_table( $media_id, $new_media_id, $to_blog_id, $table_name );

		return $new_media_id;
	}

	private function clone_term( $term_id, $to_blog_id ) {
		if ( empty( $term_id ) || ! $to_blog_id ) {
			return null;
		}

		$table_name      = 'mlt_term_to_term';
		$new_term_id     = $this->get_id_from_table( $term_id, $to_blog_id, $table_name );
		$new_term_parent = 0;

		if ( $new_term_id ) {
			return $new_term_id;
		}

		if ( (int) $term_id === 1 ) {
			$this->add_id_to_table( $term_id, 1, $to_blog_id, $table_name );

			return 1;
		}

		$term = get_term( $term_id );
		if ( ! $term ) {
			return null;
		}

		if ( $term->parent ) {
			$new_term_parent = $this->clone_term( $term->parent, $to_blog_id );
		}

		switch_to_blog( $to_blog_id );

		if ( $new_term_id = term_exists( $term->slug ) ) {
			restore_current_blog();
			$this->add_id_to_table( $term_id, $new_term_id, $to_blog_id, $table_name );

			return $new_term_id;
		}

		$new_term = term_exists( $term->slug );
		if ( ! $new_term ) {
			$new_term = wp_insert_term( $term->name, $term->taxonomy, array(
				'description' => $term->description,
				'slug'        => $term->slug,
				'parent'      => (int) $new_term_parent
			) );
		}

		restore_current_blog();

		if ( is_wp_error( $new_term ) ) {
			return null;
		}

		$this->add_id_to_table( $term_id, $new_term['term_id'], $to_blog_id, $table_name );

		return $new_term['term_id'];
	}

	private function filter_blocks( $blocks ) {
		if ( empty( $blocks ) ) {
			return [];
		}

		foreach ( $blocks as $block_key => $block ) {

			if ( strpos( $block['blockName'], 'acf/' ) === false ) {
				// gutenberg blocks
				switch ( $block['blockName'] ) {
					case 'core/image':
						$blocks[ $block_key ] = $this->clone_media_block( 'image', $block );
						break;
					case 'core/audio':
						$blocks[ $block_key ] = $this->clone_media_block( 'audio', $block );
						break;
					case 'core/cover':
						$blocks[ $block_key ] = $this->clone_media_block( 'cover', $block );
						break;
					case 'core/file':
						$blocks[ $block_key ] = $this->clone_media_block( 'file', $block );
						break;
					case 'core/media-text':
						$blocks[ $block_key ] = $this->clone_media_block( 'media-text', $block );
						break;
					case 'core/video':
						$blocks[ $block_key ] = $this->clone_media_block( 'video', $block );
						break;
					case 'core/gallery':
					default:
						if ( $block['innerBlocks'] ) {
							$blocks[ $block_key ]['innerBlocks'] = $this->filter_blocks( $block['innerBlocks'] );
						}
				}
			} else {
				// acf blocks
				$blocks[ $block_key ] = $this->clone_acf_block( $block );
			}
		}

		return $blocks;
	}

	private function parse_content( $content ) {
		$parse_blocks = parse_blocks( $content );

		if ( empty( $parse_blocks ) ) {
			return $content;
		}

		$parse_blocks = $this->filter_blocks( $parse_blocks );

		return serialize_blocks( $parse_blocks );
	}

	private function clone_media_block( $type, $block ) {
		if ( ! $type || empty( $block ) ) {
			return $block;
		}

		$media_id     = (int) $block['attrs']['id'];
		$new_media_id = $this->clone_media( $media_id, $this->to_blog_id );

		switch_to_blog( $this->to_blog_id );

		$new_media_url = '';
		$inner_html    = $block['innerHTML'];
		$inner_content = $block['innerContent'][0];
		switch ( $type ) {
			case 'image':
				$new_media_url = wp_get_attachment_image_url( $new_media_id, $block['attrs']['sizeSlug'] );
				break;
			case 'media-text':
				$new_media_url = wp_get_attachment_image_url( $new_media_id, $block['attrs']['mediaSizeSlug'] );
				break;
			case 'video':
			case 'cover':
			case 'file':
			case 'audio':
				$new_media_url = wp_get_attachment_url( $new_media_id );
				break;
		}

		$patterns     = array(
			'/src="(.*?)"/',
			'/wp-image-(\d+)/',
			'/background-image:url\((.*?)\)/',
			'/data="(.*?)"/',
			'/href="(.*?)"/'
		);
		$replacements = array(
			'src="' . $new_media_url . '"',
			'wp-image-' . $new_media_id,
			'background-image:url(' . $new_media_url . ')',
			'data="' . $new_media_url . '"',
			'href="' . $new_media_url . '"'
		);

		$inner_html    = preg_replace( $patterns, $replacements, $inner_html );
		$inner_content = preg_replace( $patterns, $replacements, $inner_content );

		switch_to_blog( $this->current_blog_id );

		if ( isset( $block['attrs']['id'] ) ) {
			$block['attrs']['id'] = (int) $new_media_id;
		}
		if ( isset( $block['attrs']['mediaId'] ) ) {
			$block['attrs']['mediaId'] = (int) $new_media_id;
		}
		if ( isset( $block['attrs']['mediaLink'] ) ) {
			$block['attrs']['mediaLink'] = $new_media_url;
		}
		if ( isset( $block['attrs']['url'] ) ) {
			$block['attrs']['url'] = $new_media_url;
		}
		if ( isset( $block['attrs']['href'] ) ) {
			$block['attrs']['href'] = $new_media_url;
		}
		$block['innerHTML']       = $inner_html;
		$block['innerContent'][0] = $inner_content;

		return $block;
	}

	private function clone_acf_block( $block = [] ) {
		if ( empty( $block['attrs']['data'] ) ) {
			return $block;
		}

		$block_data = $block['attrs']['data'];

		foreach ( $block_data as $data_key => $data_item ) {
			$acf_object = get_field_object( $data_item );
			if ( ! $acf_object ) {
				continue;
			}

			if ( $acf_object['type'] === 'image' || $acf_object['type'] === 'gallery' ) {
				$key      = preg_replace( '/^_/', '', $data_key );
				$media_id = $block_data[ $key ];

				$block['attrs']['data'][ $key ] = $this->clone_media( $media_id, $this->to_blog_id );
			}
		}

		return $block;
	}

	private function clone_acf_fields( $post_id ) : array {
		if ( ! $post_id ) {
			return [];
		}

		$post_meta       = get_post_meta( $post_id );
		$clone_post_meta = [];

		if ( ! empty( $post_meta ) ) {
			foreach ( $post_meta as $key => [$value] ) {
				$acf_object = get_field_object( $value );
				if ( ! $acf_object ) {
					continue;
				}

				$clone_post_meta[ $key ] = $value;

				$acf_key     = preg_replace( '/^_/', '', $key );
				$field_value = maybe_unserialize( $post_meta[ $acf_key ][0] );

				if ( $acf_object['type'] === 'image' || $acf_object['type'] === 'gallery' ) {
					$clone_post_meta[ $acf_key ] = $this->clone_media( $field_value, $this->to_blog_id );
				} else {
					$clone_post_meta[ $acf_key ] = $field_value;
				}
			}
		}

		return $clone_post_meta;
	}

	private function check_security() {
		return wp_verify_nonce( $_POST['nonce'], 'mlt-nonce' );
	}
}

$Multilang = new Multilang();