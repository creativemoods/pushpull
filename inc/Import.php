<?php
/**
 * GitHub Import Manager
 *
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

/**
 * Class Import
 */
class Import {

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
		$query = new \WP_Query([
			"post_type" => $post_type,
			"name" => $name
		]);

		return $query->have_posts() ? reset($query->posts) : null;
	}

	/**
	 * Import an image.
	 *
	 * @param string $image the name of the image.
	 *
	 * @return integer|WP_Error
	 */
	public function import_image($image) {
		// Get attachment from Git
		$imagepost = $this->app->fetch()->getPostByName('attachment', $image);
		// Find local filename
		$fn = wp_upload_dir()['path']."/".$imagepost->meta->_wp_attached_file;
		// Get binary contents from Git
		$media = $this->app->fetch()->getPostByName('media', $imagepost->meta->_wp_attached_file);
		// Write binary contents to local file in uploads/
		$fh = fopen($fn, 'w');
		fwrite($fh, $media);
		fclose($fh);
		// Create attachment
		$imageid = url_to_postid($image);
		if ($imageid !== 0) {
			$this->app->write_log(__( 'Image attachment '.$image.' ('.$fn.') already exists locally. Updating.', 'pushpull' ));
			// TODO
			return $imageid;
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
				'post_content'   => str_replace("@@DOMAIN@@", get_home_url(), $imagepost->post_content),
				'post_excerpt'   => property_exists($imagepost, 'post_excerpt') ? $imagepost->post_excerpt : "",
				'post_date'      => $imagepost->post_date,
				'post_date_gmt'  => property_exists($imagepost, 'post_date_gmt') ? $imagepost->post_date_gmt : $imagepost->post_date,
			];
			$attachid = wp_insert_attachment($attachment, $fn, 0);
			// Regenerate attachment metadata
			$data = wp_generate_attachment_metadata($attachid, $fn);
			wp_update_attachment_metadata($attachid, $data);
			// TODO Quid de postimage->meta['_wp_attachment_image_alt'] ?
			// Move to original folder
			if (function_exists('wp_rml_move') && property_exists($imagepost, 'folder')) {
				wp_rml_move(wp_rml_create_or_return_existing_id($imagepost->folder, _wp_rml_root(), 0, [], false, true), [$attachid]);
			}
			return $attachid;
		}
	}

	/**
	 * Replace patterns in blocks code
	 */
	protected function replace_patterns($blocks, $patterns) {
		$res = [];
		foreach ($blocks as $block) {
			// Check if the block has attributes and a ref attribute
			if (isset($block['attrs']) && isset($block['attrs']['ref'])) {
				foreach ($patterns as $pattern) {
					if ($block['attrs']['ref'] === $pattern->id) {
						// We have a replacement match
						$tmppost = $this->get_post_by_name($pattern->name, 'wp_block');
						$this->app->write_log("Replacing ".$block['attrs']['ref']." with ".$tmppost->post_name." with ID ".$tmppost->ID);
						$block['attrs']['ref'] = $tmppost->ID;
					}
				}
			}
			$newBlock = $block;
			// Recursively search in innerBlocks if they exist
			if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$innerRes = $this->replace_patterns($block['innerBlocks'], $patterns);
				$newBlock['innerBlocks'] = $innerRes;
			}
			$res[] = $newBlock;
		}

		return $res;
	}

	/**
	 * Import a post.
	 *
	 * @param string $type the type of post.
	 * @param string $name the name of the post.
	 *
	 * @return string|WP_Error
	 */
	public function import_post( $type, $name ) {
		$this->app->write_log(__( 'Starting import from Git for '.$name.'.', 'pushpull' ));

		$post = $this->app->fetch()->getPostByName($type, $name);
		// We need to add wp_slash otherwise \\ will be deleted
		$post->post_content = str_replace("@@DOMAIN@@", get_home_url(), wp_slash($post->post_content));

		// Replace references to patterns
		if (property_exists($post, 'patterns') && count($post->patterns) > 0) {
			// https://wordpress.stackexchange.com/questions/391381/gutenberg-block-manipulation-undo-parse-blocks-with-serialize-blocks-result
			$parsed = parse_blocks(str_replace('\\"', '"', $post->post_content));
			$replaced = $this->replace_patterns($parsed, $post->patterns);
			$post->post_content = serialize_blocks($replaced);
		}

		// Handle author (otherwise will be admin)
		if (property_exists($post, 'author')) {
			$post->post_author = get_user_by('login', $post->author)->ID;
		}

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
				//$this->app->write_log(__( 'Creating meta key '.$key.'.', 'pushpull' ));
				// Unserialize because https://developer.wordpress.org/reference/functions/update_metadata/ "...or itself a PHP-serialized string"
				$value = maybe_unserialize($value);
				if ($key === "_generate_element_display_conditions") {
					// We need to reset the post name to its ID if it exists
					foreach ($value as $item => $displaycond) {
						if ($displaycond['rule'] === "post:page") {
							$arr = explode('/', $displaycond['object']); // e.g. "page/our-story"
							$tmppost = $this->get_post_by_name($arr[1], $arr[0]);
							if ($tmppost !== null) {
								$value[$item]['object'] = $tmppost->ID;
							}
						}
					}
				}
				update_post_meta($id, $key, $value);
			}
		}

		// Post terms
		if (property_exists($post, 'terms')) {
			foreach ($post->terms as $term) {
				$this->app->write_log(__( 'Creating term for taxonomy '.$term->taxonomy.'.', 'pushpull' ));
				wp_set_post_terms($id, [$term->term_id], $term->taxonomy, false);
			}
		}

		if (property_exists($post, 'language') && function_exists('pll_set_post_language')) {
			pll_set_post_language($id, $post->language);
		}

		if (property_exists($post, 'translations') && function_exists('pll_save_post_translations')) {
			// Change back from post names to IDs
			$newvals = [];
			$description = maybe_unserialize($post->translations);
			$found = true;
			foreach($description as $lang => $name) {
				$arr = explode('/', $name); // e.g. "page/our-story"
				$tmppost = $this->get_post_by_name($arr[1], $arr[0]);
				if ($tmppost !== null) {
					$newvals[$lang] = $tmppost->ID;
				} else {
					$found = false;
				}
			}
			if ($found) {
				pll_save_post_translations($newvals);
			}
		}

		// Post images
		if (property_exists($post, 'featuredimage')) {
			$imageid = $this->import_image($post->featuredimage);
			update_post_meta($id, '_thumbnail_id', $imageid);
		}
		if (property_exists($post, 'intimages')) {
			foreach ($post->intimages as $image) {
				$this->import_image($image);
			}
		}

		$this->app->write_log(__( 'End import from Git.', 'pushpull' ));
		return $id;
	}

	/**
	 * Imports a payload.
	 *
	 * @param Payload $payload GitHub payload object.
	 *
	 * @return string|WP_Error
	 */
	public function payload( Payload $payload ) {
		/**
		 * Whether there's an error during import.
		 *
		 * @var false|WP_Error $error
		 */
		$error = false;

		$result = $this->commit( $this->app->fetch()->commit( $payload->get_commit_id() ) );

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
		return $this->commit( $this->app->fetch()->master() );
	}

	/**
	 * Imports a provided commit into the database.
	 *
	 * @param Commit|WP_Error $commit Commit to import.
	 *
	 * @return string|WP_Error
	 */
	protected function commit( $commit ) {
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}

		if ( $commit->already_synced() ) {
			return new \WP_Error( 'commit_synced', __( 'Already synced this commit.', 'wp-github-sync' ) );
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
	 * @param Blob $blob Blob to validate.
	 *
	 * @return bool
	 */
	protected function importable_blob( Blob $blob ) {
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
	 * @param Blob $blob Blob to transform into a Post.
	 *
	 * @return Post
	 */
	protected function blob_to_post( Blob $blob ) {
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

		$post = new Post( $args, $this->app->api() );
		$post->set_meta( $meta );

		return $post;
	}
}
