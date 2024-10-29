<?php
/**
 * GitHub Import Manager
 *
 * @package PushPull
 */

namespace CreativeMoods\PushPull;
use CreativeMoods\PushPull\providers\GitProviderFactory;

/**
 * Class Import
 */
class Puller {
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

	/**
	 * Import an image.
	 *
	 * @param string $image the name of the image.
	 *
	 * @return integer|\WP_Error
	 */
	protected function import_image($image) {
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);

		// Get attachment from Git
		$imagepost = $gitProvider->getRemotePostByName('attachment', $image);
		// Find local filename
		$fn = wp_upload_dir()['path']."/".$imagepost->meta->_wp_attached_file;
		// Get binary contents from Git
		$media = $gitProvider->getRemotePostByName('media', $imagepost->meta->_wp_attached_file);
		// Write binary contents to local file in uploads/
		$wpfsd = new \WP_Filesystem_Direct( false );
		// TODO check result
		$wpfsd->put_contents ( $fn, $media );
		// Create attachment
		$imageid = url_to_postid($image);
		if ($imageid !== 0) {
			$this->app->write_log(
				sprintf(
					/* translators: 1: image name, 2: filename */
					__( 'Image attachment %1$s (%2$s) already exists locally. Updating.', 'pushpull' ),
					$image,
					$fn,
				)
			);
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
	 * Pull a post.
	 *
	 * @param string $type the type of post.
	 * @param string $name the name of the post.
	 *
	 * @return string|WP_Error
	 */
	public function pull( $type, $name ) {
		$this->app->write_log(
			sprintf(
				/* translators: 1: name of post */
				__( 'Starting pull from Git for %1$s.', 'pushpull' ),
				$name,
			)
		);

		// TODO Est-ce qu'on peut éviter d'utiliser la factory plusieurs fois ?
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$post = $gitProvider->getRemotePostByName($type, $name);
		// TODO We need to add wp_slash otherwise \\ will be deleted -> too many slashes
		$post->post_content = str_replace("@@DOMAIN@@", get_home_url(), wp_slash($post->post_content));

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
			$post->ID = $id;
			if (is_wp_error($id)) {
				$this->app->write_log(__( 'Error creating post.', 'pushpull' ));
			}
		}

		// Post terms
		if (property_exists($post, 'terms')) {
			foreach ($post->terms as $term) {
				$this->app->write_log(
					sprintf(
						/* translators: 1: taxonomy of the term */
						__( 'Creating term for taxonomy %1$s.', 'pushpull' ),
						$term->taxonomy,
					)
				);
				wp_set_post_terms($id, [$term->term_id], $term->taxonomy, false);
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

		// We find out registered plugins and apply one action for each.
		// If you are a plugin maintainer and want PushPull to handle your
		// data, create an action hook named pushpull_import_yourplugin
		$active_plugins = get_option('active_plugins');
		foreach ($active_plugins as $plugin) {
			$plugin = explode("/", $plugin)[0];
			if (has_action('pushpull_import_' . $plugin)) {
				// We call the 3rd party filter hook if it exists
				do_action('pushpull_import_' . $plugin, $post);
			} else {
				// Call our default filter hook for this 3rd party plugin
				do_action('pushpull_default_import_' . $plugin, $post);
			}
		}

		$this->app->write_log(
			sprintf(
				/* translators: 1: id of post */
				__( 'End import from Git with id %1$s.', 'pushpull' ),
				$id,
			)
		);

		return $id;
	}
}
