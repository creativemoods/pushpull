<?php
/**
 * GitHub Import Manager
 *
 * @package PushPull
 */

/**
 * Class PushPull_Import
 */
class PushPull_Import {

	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Initializes a new import manager.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	public function get_post_by_name(string $name, string $post_type = "post") {
		$query = new WP_Query([
			"post_type" => $post_type,
			"name" => $name
		]);

		return $query->have_posts() ? reset($query->posts) : null;
	}

	/**
	 * Import a post.
	 *
	 * @param integer $user_id user_id to import to.
	 * @param string $type the type of post.
	 * @param string $name the name of the post.
	 *
	 * @return string|WP_Error
	 */
	public function import_post( $user_id, $type, $name ) {
		if ( ! is_numeric( $user_id ) ) {
			$this->app->write_log(__( 'Invalid user ID', 'pushpull' ));
		}

		$this->app->write_log(__( 'Starting import from Git.', 'pushpull' ));

		$post = $this->app->api()->fetch()->getPostByName($type, $name);
		// Post
		$id = url_to_postid($name);
		if ($id !== 0) {
			$post->ID = $id;
			$this->app->write_log(__( 'Post already exists locally. Updating.', 'pushpull' ));
			wp_update_post($post, true);
		} else {
			$this->app->write_log(__( 'Creating new post.', 'pushpull' ));
			$id = wp_insert_post($post, true);
		}

		// Post meta
		if (property_exists($post, 'meta')) {
			foreach ($post->meta as $key => $value) {
				// Unserialize because https://developer.wordpress.org/reference/functions/update_metadata/ "...or itself a PHP-serialized string"
				$value = maybe_unserialize($value);
				update_post_meta($id, $key, $value);
			}
		}

		// Post terms
		if (property_exists($post, 'terms')) {
			$this->app->write_log($post->terms);
			foreach ($post->terms as $term) {
				if ($term->taxonomy === "post_translations") {
					// Change back from post names to IDs
					$newvals = [];
					$description = maybe_unserialize($term->description);
					foreach($description as $lang => $name) {
						$arr = explode('/', $name); // e.g. "page/our-story"
						$post = $this->get_post_by_name($arr[1], $arr[0]);
						$newvals[$lang] = $post->ID;
					}
					pll_save_post_translations($newvals);
				} else {
					wp_set_post_terms($id, [$term->term_id], $term->taxonomy, false);
				}
			}
		}

		// Post images
		if (property_exists($post, 'intimages')) {
			foreach ($post->intimages as $image) {
				// Get attachment from Git
				$imagepost = $this->app->api()->fetch()->getPostByName('attachment', $image);
				// Find local filename
				$fn = wp_upload_dir()['path']."/".$imagepost->meta->_wp_attached_file;
				// Get binary contents from Git
				$media = $this->app->api()->fetch()->getPostByName('media', $imagepost->meta->_wp_attached_file);
				// Write binary contents to local file in uploads/
				$fh = fopen($fn, 'w');
				fwrite($fh, $media);
				fclose($fh);
				// Create attachment
				$imageid = url_to_postid($image);
				if ($imageid !== 0) {
					$this->app->write_log(__( 'Image attachment '.$image.' ('.$fn.') already exists locally. Updating.', 'pushpull' ));
					// TODO
				} else {
					$this->app->write_log(__( 'Creating new image attachment.', 'pushpull' ));
					$wp_filetype = wp_check_filetype($fn, null);
					$return = apply_filters( 'wp_handle_upload', array( 'file' => $fn, 'url' => wp_upload_dir()['url'].'/'.$imagepost->meta->_wp_attached_file, 'type' => $wp_filetype['type'] ) );
					$attachment = [
						'post_mime_type' => $return['type'],
						'guid'           => $return['url'],
						'post_parent'    => 0,
						'post_title'     => $imagepost->post_title,
						'post_name'      => $imagepost->post_name,
						'post_content'   => $imagepost->post_content,
						'post_excerpt'   => property_exists($imagepost, 'post_excerpt') ? $imagepost->post_excerpt : "",
						'post_date'      => $imagepost->post_date,
						'post_date_gmt'  => property_exists($imagepost, 'post_date_gmt') ? $imagepost->post_date_gmt : $imagepost->post_date,
					];
					$attachid = wp_insert_attachment($attachment, $fn, 0);
					// Regenerate attachment metadata
					$data = wp_generate_attachment_metadata($attachid, $fn);
					wp_update_attachment_metadata($attachid, $data);
					// TODO Quid de postimage->meta['_wp_attachment_image_alt'] ?
					// Move to Static folder
					wp_rml_move(wp_rml_create_or_return_existing_id('Static', _wp_rml_root(), 0, [], false, true), [$attachid]);
				}
			}
		}

		$this->app->write_log(__( 'End import from Git.', 'pushpull' ));
		return $id;
	}

	/**
	 * Imports a payload.
	 *
	 * @param PushPull_Payload $payload GitHub payload object.
	 *
	 * @return string|WP_Error
	 */
	public function payload( PushPull_Payload $payload ) {
		/**
		 * Whether there's an error during import.
		 *
		 * @var false|WP_Error $error
		 */
		$error = false;

		$result = $this->commit( $this->app->api()->fetch()->commit( $payload->get_commit_id() ) );

		if ( is_wp_error( $result ) ) {
			$error = $result;
		}

		$removed = array();
		foreach ( $payload->get_commits() as $commit ) {
			$removed = array_merge( $removed, $commit->removed );
		}
		foreach ( array_unique( $removed ) as $path ) {
			$result = $this->app->database()->delete_post_by_path( $path );

			if ( is_wp_error( $result ) ) {
				if ( $error ) {
					$error->add( $result->get_error_code(), $result->get_error_message() );
				} else {
					$error = $result;
				}
			}
		}

		if ( $error ) {
			return $error;
		}

		return __( 'Payload processed', 'wp-github-sync' );
	}

	/**
	 * Imports the latest commit on the master branch.
	 *
	 * @return string|WP_Error
	 */
	public function master() {
		return $this->commit( $this->app->api()->fetch()->master() );
	}

	/**
	 * Imports a provided commit into the database.
	 *
	 * @param PushPull_Commit|WP_Error $commit Commit to import.
	 *
	 * @return string|WP_Error
	 */
	protected function commit( $commit ) {
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}

		if ( $commit->already_synced() ) {
			return new WP_Error( 'commit_synced', __( 'Already synced this commit.', 'wp-github-sync' ) );
		}

		$posts = array();
		$new   = array();

		foreach ( $commit->tree()->blobs() as $blob ) {
			if ( ! $this->importable_blob( $blob ) ) {
				continue;
			}

			$posts[] = $post = $this->blob_to_post( $blob );

			if ( $post->is_new() ) {
				$new[] = $post;
			}
		}

		$result = $this->app->database()->save_posts( $posts, $commit->author_email() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $new ) {
			$result = $this->app->export()->new_posts( $new );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $posts;
	}

	/**
	 * Checks whether the provided blob should be imported.
	 *
	 * @param PushPull_Blob $blob Blob to validate.
	 *
	 * @return bool
	 */
	protected function importable_blob( PushPull_Blob $blob ) {
		global $wpdb;

		// Skip the repo's readme.
		if ( 'readme' === strtolower( substr( $blob->path(), 0, 6 ) ) ) {
			return false;
		}

		// If the blob sha already matches a post, then move on.
		if ( ! is_wp_error( $this->app->database()->fetch_by_sha( $blob->sha() ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Imports a single blob content into matching post.
	 *
	 * @param PushPull_Blob $blob Blob to transform into a Post.
	 *
	 * @return PushPull_Post
	 */
	protected function blob_to_post( PushPull_Blob $blob ) {
		$args = array( 'post_content' => $blob->content_import() );
		$meta = $blob->meta();

		if ( $meta ) {
			if ( array_key_exists( 'layout', $meta ) ) {
				$args['post_type'] = $meta['layout'];
				unset( $meta['layout'] );
			}

			if ( array_key_exists( 'published', $meta ) ) {
				$args['post_status'] = true === $meta['published'] ? 'publish' : 'draft';
				unset( $meta['published'] );
			}

			if ( array_key_exists( 'post_title', $meta ) ) {
				$args['post_title'] = $meta['post_title'];
				unset( $meta['post_title'] );
			}

			if ( array_key_exists( 'ID', $meta ) ) {
				$args['ID'] = $meta['ID'];
				unset( $meta['ID'] );
			}

			if ( array_key_exists( 'post_date', $meta ) ) {

				if ( empty( $meta['post_date'] ) ) {
					$meta['post_date'] = current_time( 'mysql' );
				}

				$args['post_date'] = $meta['post_date'];

				$args['post_date_gmt'] = get_gmt_from_date( $meta['post_date'] );
				unset( $meta['post_date'] );
			}
		}

		$meta['_sha'] = $blob->sha();

		$post = new PushPull_Post( $args, $this->app->api() );
		$post->set_meta( $meta );

		return $post;
	}
}
